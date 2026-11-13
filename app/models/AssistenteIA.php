<?php

/**
 * Assistente virtual com IA (Claude, da Anthropic), restrito ao SICAPDA.
 *
 * - O comportamento e tudo o que a IA sabe sobre o sistema ficam em app/conhecimento/assistente.md
 *   (texto em português: para mudar o que ela responde ou recusa, edite esse arquivo).
 * - Os dados da empresa chegam pelas ferramentas de AssistenteFerramentas (só leitura, sempre da empresa
 *   de quem está logado; a IA nunca escolhe a empresa).
 * - Sem ANTHROPIC_API_KEY no config/.env, com a IA fora do ar ou acima do limite de perguntas, quem
 *   responde é o motor local (Assistente, por palavras-chave), então o chat nunca fica mudo.
 *
 * Usado pela tela /assistente (DemandaController) e pela API do aplicativo (ApiController), com o mesmo código.
 */
class AssistenteIA
{
    /** Chamadas ao modelo por pergunta (cada consulta a ferramentas gasta uma). */
    private const MAX_RODADAS = 6;
    /** A pergunta inteira (todas as rodadas e novas tentativas) precisa caber neste tempo. */
    private const PRAZO_SEGUNDOS = 75;
    private const MAX_TOKENS = 16000;

    /** Histórico enviado pelo chat: últimas mensagens, cada uma cortada neste tamanho. */
    private const MAX_HISTORICO = 12;
    private const MAX_TEXTO_HISTORICO = 2000;
    public const MAX_PERGUNTA = 300;

    /** Perguntas por usuário por hora (ASSISTENTE_IA_LIMITE_HORA no .env; 0 = sem limite). */
    private const LIMITE_HORA_PADRAO = 40;

    private const ARQUIVO_CONHECIMENTO = __DIR__ . '/../conhecimento/assistente.md';

    private int $empresaId;
    private array $usuario;
    private DateTimeImmutable $agora;
    private AssistenteFerramentas $ferramentas;

    /**
     * @param array $usuario ['id' => int, 'nome' => string, 'perfil' => 'admin'|'usuario']
     */
    public function __construct(int $empresaId, array $usuario, ?DateTimeImmutable $agora = null)
    {
        $this->empresaId = $empresaId;
        $this->usuario = $usuario;
        $this->agora = $agora ?? new DateTimeImmutable('now');
        $this->ferramentas = new AssistenteFerramentas($empresaId, $this->agora->setTime(0, 0));
    }

    /** A IA está configurada neste servidor. */
    public static function ativa(): bool
    {
        return Claude::configurado() && is_readable(self::ARQUIVO_CONHECIMENTO);
    }

    /**
     * Responde com a IA quando possível; senão, com o motor local.
     * Retorna ['resposta' => string, 'ia' => bool].
     *
     * @param array $historico mensagens anteriores do chat: [['autor' => 'usuario'|'bot', 'texto' => '...'], ...]
     */
    public static function responderOuLocal(int $empresaId, array $usuario, string $mensagem, array $historico = []): array
    {
        $mensagem = trim(mb_substr($mensagem, 0, self::MAX_PERGUNTA));

        if ($mensagem !== '' && self::ativa()) {
            if (!self::dentroDoLimite((int) ($usuario['id'] ?? 0))) {
                return [
                    'resposta' => "Você atingiu o limite de perguntas para a IA nesta hora. Enquanto isso, respondo pelo modo básico:\n\n"
                        . (new Assistente($empresaId))->responder($mensagem),
                    'ia' => false,
                ];
            }
            try {
                return ['resposta' => (new self($empresaId, $usuario))->responder($mensagem, $historico), 'ia' => true];
            } catch (Throwable $e) {
                error_log('SICAPDA assistente IA: ' . $e->getMessage());
            }
        }

        return ['resposta' => (new Assistente($empresaId))->responder($mensagem), 'ia' => false];
    }

    /**
     * Pergunta à IA (com as ferramentas) e devolve o texto final.
     *
     * @throws ClaudeException|RuntimeException quando a IA não consegue responder (quem chama usa o motor local)
     */
    public function responder(string $mensagem, array $historico = []): string
    {
        $mensagem = trim(mb_substr($mensagem, 0, self::MAX_PERGUNTA));
        $mensagens = self::montarHistorico($historico);
        $mensagens[] = ['role' => 'user', 'content' => $mensagem];

        $prazo = microtime(true) + self::PRAZO_SEGUNDOS;
        $system = $this->system();
        $tools = AssistenteFerramentas::definicoes();

        for ($rodada = 1; $rodada <= self::MAX_RODADAS; $rodada++) {
            $pedido = [
                'max_tokens' => self::MAX_TOKENS,
                'system'     => $system,
                'tools'      => $tools,
                'messages'   => $mensagens,
            ];
            if ($rodada === self::MAX_RODADAS) {
                // Última rodada: responder com o que já consultou, sem chamar mais ferramentas.
                $pedido['tool_choice'] = ['type' => 'none'];
            }

            $r = Claude::mensagens($pedido, $prazo);
            $parada = (string) ($r['dados']['stop_reason'] ?? '');
            $conteudo = self::conteudoParaDevolver($r['conteudo']);

            if ($parada === 'refusal') {
                return 'Não posso ajudar com esse pedido. Posso responder sobre previsões de pessoas e refeições, '
                    . 'desperdício, acessos, cadastro de pessoas e cartões, dispositivos IoT e o uso do SICAPDA.';
            }

            $chamadas = array_values(array_filter($conteudo, fn($b) => ($b->type ?? '') === 'tool_use'));
            if ($parada === 'tool_use' && $chamadas) {
                // Devolve a resposta inteira (inclusive os blocos de raciocínio) e os resultados de todas as
                // ferramentas pedidas, numa única mensagem, como a API exige.
                $mensagens[] = ['role' => 'assistant', 'content' => $conteudo];
                $mensagens[] = ['role' => 'user', 'content' => array_map(fn($c) => $this->executar($c), $chamadas)];
                continue;
            }

            $texto = self::textoFinal($conteudo);
            if ($texto === '') {
                throw new RuntimeException("Resposta da IA sem texto (stop_reason={$parada}).");
            }
            return $texto;
        }

        throw new RuntimeException('A IA não concluiu a resposta no número máximo de rodadas.');
    }

    // ─── Prompt ──────────────────────────────────────────────────────────────

    /**
     * Dois blocos: o fixo (regras + conhecimento do sistema, com cache_control para a API reaproveitar o
     * processamento entre perguntas) e o de contexto (data, hora, empresa, usuário), que muda a cada pergunta
     * e por isso fica depois do ponto de cache.
     */
    private function system(): array
    {
        $fixo = (string) file_get_contents(self::ARQUIVO_CONHECIMENTO);
        return [
            ['type' => 'text', 'text' => $fixo, 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => $this->contexto()],
        ];
    }

    private function contexto(): string
    {
        $hoje = $this->agora->setTime(0, 0);
        $calendario = [];
        for ($i = 0; $i <= 14; $i++) {
            $d = $hoje->modify("+{$i} days");
            $rotulo = match ($i) { 0 => ' (hoje)', 1 => ' (amanhã)', default => '' };
            $feriado = Datas::feriado($d);
            $calendario[] = '• ' . Datas::nomeDiaSemana($d) . ' ' . $d->format('Y-m-d') . $rotulo . ($feriado ? " — feriado: {$feriado}" : '');
        }
        $ontem = $hoje->modify('-1 day');

        $perfil = ($this->usuario['perfil'] ?? '') === 'admin'
            ? 'administrador da empresa (pode cadastrar pessoas e cartões e gerenciar dispositivos)'
            : 'usuário comum (consulta tudo, mas cadastro de pessoas, cartões e dispositivos é com o administrador)';
        $nome = trim(explode(' ', trim((string) ($this->usuario['nome'] ?? '')))[0] ?? '');

        return "# Contexto desta conversa\n"
            . '• Agora: ' . Datas::nomeDiaSemana($this->agora) . ', ' . $this->agora->format('d/m/Y H:i') . " (horário de Brasília).\n"
            . '• Ontem: ' . Datas::nomeDiaSemana($ontem) . ' ' . $ontem->format('Y-m-d') . ".\n"
            . '• Empresa: ' . $this->nomeEmpresa() . ".\n"
            . '• Quem pergunta: ' . ($nome !== '' ? $nome . ', ' : '') . $perfil . ".\n"
            . "• Calendário dos próximos dias:\n" . implode("\n", $calendario);
    }

    private function nomeEmpresa(): string
    {
        try {
            $e = (new Empresa())->buscarPorId($this->empresaId);
            $nome = $e ? trim((string) ($e['nome_fantasia'] ?: $e['razao_social'])) : '';
            return $nome !== '' ? $nome : 'não informado';
        } catch (Throwable) {
            return 'não informado';
        }
    }

    // ─── Ferramentas ─────────────────────────────────────────────────────────

    private function executar(object $chamada): array
    {
        $resultado = ['type' => 'tool_result', 'tool_use_id' => (string) $chamada->id];
        $entrada = json_decode((string) json_encode($chamada->input ?? new stdClass()), true);

        try {
            $dados = $this->ferramentas->executar((string) $chamada->name, is_array($entrada) ? $entrada : []);
            $resultado['content'] = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (InvalidArgumentException $e) {
            $resultado['content'] = $e->getMessage();
            $resultado['is_error'] = true;
        } catch (Throwable $e) {
            error_log('SICAPDA assistente IA, ferramenta ' . $chamada->name . ': ' . $e->getMessage());
            $resultado['content'] = 'Não foi possível consultar este dado agora.';
            $resultado['is_error'] = true;
        }
        return $resultado;
    }

    // ─── Mensagens ───────────────────────────────────────────────────────────

    /**
     * Converte o histórico do chat no formato da API: começa com o usuário, alterna usuário/assistente
     * (mensagens seguidas do mesmo autor são juntadas) e termina com o assistente, porque a pergunta
     * nova entra depois. Os textos vêm do navegador/app, então são cortados e limpos aqui.
     */
    private static function montarHistorico(array $historico): array
    {
        $itens = [];
        foreach (array_slice(array_values($historico), -self::MAX_HISTORICO) as $h) {
            if (!is_array($h) || !is_string($h['texto'] ?? null)) {
                continue;
            }
            $papel = match ($h['autor'] ?? '') { 'usuario' => 'user', 'bot' => 'assistant', default => null };
            $texto = trim(mb_substr($h['texto'], 0, self::MAX_TEXTO_HISTORICO));
            if ($papel === null || $texto === '') {
                continue;
            }
            if ($itens && $itens[count($itens) - 1]['role'] === $papel) {
                $itens[count($itens) - 1]['content'] .= "\n\n" . $texto;
            } else {
                $itens[] = ['role' => $papel, 'content' => $texto];
            }
        }

        while ($itens && $itens[0]['role'] !== 'user') {
            array_shift($itens);       // a saudação inicial do assistente não entra
        }
        while ($itens && $itens[count($itens) - 1]['role'] !== 'assistant') {
            array_pop($itens);         // pergunta que ficou sem resposta (erro de rede, por exemplo)
        }
        return $itens;
    }

    /**
     * O "content" da resposta pronto para voltar à API. Se o filtro de segurança tiver trocado de modelo no
     * meio da resposta (bloco "fallback"), os blocos de raciocínio e de ferramenta anteriores à troca saem,
     * como a API pede; o texto e o próprio bloco "fallback" ficam no lugar.
     */
    private static function conteudoParaDevolver(array $conteudo): array
    {
        $ultimoFallback = null;
        foreach ($conteudo as $i => $bloco) {
            if (($bloco->type ?? '') === 'fallback') {
                $ultimoFallback = $i;
            }
        }
        if ($ultimoFallback === null) {
            return array_values($conteudo);
        }
        $saida = [];
        foreach ($conteudo as $i => $bloco) {
            if ($i < $ultimoFallback && !in_array($bloco->type ?? '', ['text', 'fallback'], true)) {
                continue;
            }
            $saida[] = $bloco;
        }
        return $saida;
    }

    /** Junta os blocos de texto da resposta e limpa marcações que o chat (texto puro) não mostra. */
    private static function textoFinal(array $conteudo): string
    {
        $partes = [];
        foreach ($conteudo as $bloco) {
            if (($bloco->type ?? '') === 'text' && trim((string) ($bloco->text ?? '')) !== '') {
                $partes[] = trim((string) $bloco->text);
            }
        }
        $texto = implode("\n\n", $partes);

        $texto = str_replace(['**', '__'], '', $texto);
        $texto = preg_replace('/^#{1,6}\s+/m', '', $texto);           // títulos em Markdown
        $texto = preg_replace('/^(\s*)[-*]\s+/m', '$1• ', $texto);     // listas com - ou *
        $texto = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $texto); // [texto](link)
        $texto = preg_replace("/\n{3,}/", "\n\n", $texto);
        return trim((string) $texto);
    }

    // ─── Limite de uso ───────────────────────────────────────────────────────

    /**
     * Conta as perguntas do usuário na última hora (arquivo em storage/ia/, fora de public/ e do Git).
     * Protege a conta da API contra uso exagerado. Se não der para gravar o arquivo, não bloqueia ninguém.
     */
    private static function dentroDoLimite(int $usuarioId): bool
    {
        $limite = (int) Env::get('ASSISTENTE_IA_LIMITE_HORA', (string) self::LIMITE_HORA_PADRAO);
        if ($limite <= 0 || $usuarioId <= 0) {
            return true;
        }

        $pasta = __DIR__ . '/../../storage/ia';
        if (!is_dir($pasta) && !@mkdir($pasta, 0700, true) && !is_dir($pasta)) {
            return true;
        }
        $f = @fopen($pasta . '/uso-' . $usuarioId . '.json', 'c+');
        if ($f === false) {
            return true;
        }

        try {
            flock($f, LOCK_EX);
            $agora = time();
            $marcas = json_decode((string) stream_get_contents($f), true);
            $marcas = array_values(array_filter(is_array($marcas) ? $marcas : [], fn($t) => is_int($t) && $t > $agora - 3600));
            if (count($marcas) >= $limite) {
                return false;
            }
            $marcas[] = $agora;
            ftruncate($f, 0);
            rewind($f);
            fwrite($f, json_encode($marcas));
            return true;
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }
}
