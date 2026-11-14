<?php

/**
 * Assistente virtual com IA, restrito ao SICAPDA.
 *
 * - O comportamento e tudo o que a IA sabe sobre o sistema ficam em app/conhecimento/assistente.md
 *   (texto em português: para mudar o que ela responde ou recusa, edite esse arquivo).
 * - Os dados da empresa chegam pelas ferramentas de AssistenteFerramentas (só leitura, sempre da empresa
 *   de quem está logado; a IA nunca escolhe a empresa).
 * - A IA é qualquer serviço no formato "chat completions" (Gemini, Groq, OpenRouter...), configurado em
 *   IA_API_KEY / IA_MODEL / IA_BASE_URL no config/.env (veja Llm).
 * - Sem IA_API_KEY, com a IA fora do ar ou acima do limite de perguntas, quem responde é o motor local
 *   (Assistente, por palavras-chave), então o chat nunca fica mudo.
 *
 * Usado pela tela /assistente (DemandaController) e pela API do aplicativo (ApiController), com o mesmo código.
 */
class AssistenteIA
{
    /** Chamadas ao modelo por pergunta (cada consulta a ferramentas gasta uma). */
    private const MAX_RODADAS = 6;
    /** A pergunta inteira (todas as rodadas e novas tentativas) precisa caber neste tempo. */
    private const PRAZO_SEGUNDOS = 75;

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
        return Llm::configurado() && is_readable(self::ARQUIVO_CONHECIMENTO);
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
                self::registrarFalha($e);
                // Em vez de cair em silêncio no modo básico (que nem sempre entende a pergunta), avisa. Só o
                // administrador vê o motivo técnico e o caminho para resolver.
                $aviso = "\n\n⚠ A IA não respondeu agora, então usei o modo básico.";
                if (($usuario['perfil'] ?? '') === 'admin') {
                    $aviso .= "\nMotivo (só o administrador vê): " . self::explicarFalha($e)
                        . "\nPara testar passo a passo, abra /assistente/diagnostico.";
                }
                return ['resposta' => (new Assistente($empresaId))->responder($mensagem) . $aviso, 'ia' => false, 'erro' => $e->getMessage()];
            }
        }

        return ['resposta' => (new Assistente($empresaId))->responder($mensagem), 'ia' => false];
    }

    /**
     * Explica em português, para o administrador, por que a IA falhou e o que fazer.
     * (A mensagem original do provedor vai junto, cortada.)
     */
    public static function explicarFalha(Throwable $e): string
    {
        $msg = trim(mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 220));
        if (!$e instanceof LlmException) {
            return "resposta inesperada da IA ({$msg}).";
        }

        $status = $e->status();
        $causa = match (true) {
            str_contains($msg, 'IA não configurada') => 'a chave não foi lida: confira se IA_API_KEY está no config/.env do servidor (sem aspas nem espaços) e se a extensão cURL do PHP está ativa.',
            $status === 0 && str_contains($msg, 'Tempo esgotado') => 'a IA demorou demais para responder. Tente de novo.',
            $status === 0 => 'o servidor não conseguiu conectar ao serviço de IA (internet de saída bloqueada ou certificado SSL do servidor). Peça à hospedagem para liberar conexões HTTPS de saída (cURL) para generativelanguage.googleapis.com.',
            $status === 400 && preg_match('/api key/i', $msg) => 'o Google disse que a chave é inválida. Crie outra em aistudio.google.com/apikey e confira se foi colada inteira.',
            $status === 400 && preg_match('/tool|function|schema|parameter/i', $msg) => 'o provedor recusou as ferramentas que o assistente envia. Copie a mensagem abaixo e me mande.',
            $status === 400 => 'o provedor recusou o pedido. Copie a mensagem abaixo e me mande.',
            $status === 401, $status === 403 => 'a chave foi recusada ou não tem permissão (chave de outro projeto, API não liberada na sua região ou conta). Crie uma chave nova em aistudio.google.com/apikey.',
            $status === 404 => 'o modelo informado em IA_MODEL não existe para essa chave. Use gemini-2.5-flash ou gemini-2.5-flash-lite.',
            $status === 429 => 'a cota gratuita acabou (por minuto ou por dia). Aguarde um pouco, ou troque IA_MODEL para gemini-2.5-flash-lite, que costuma ter limite maior.',
            $status >= 500 => 'o serviço de IA está instável no momento. Tente de novo em instantes.',
            default => 'erro não classificado.',
        };
        return $causa . ($msg !== '' ? " [{$msg}]" : '');
    }

    /** Guarda a falha em storage/ia/erros.log (fora de public/ e do Git), além do error_log do servidor. */
    private static function registrarFalha(Throwable $e): void
    {
        error_log('SICAPDA assistente IA: ' . $e->getMessage());

        $pasta = __DIR__ . '/../../storage/ia';
        if (!is_dir($pasta) && !@mkdir($pasta, 0700, true) && !is_dir($pasta)) {
            return;
        }
        $arquivo = $pasta . '/erros.log';
        if (is_file($arquivo) && filesize($arquivo) > 200000) {
            @file_put_contents($arquivo, '');   // não deixa o arquivo crescer sem fim
        }
        @file_put_contents($arquivo, date('Y-m-d H:i:s') . ' ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 500) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Pergunta à IA (com as ferramentas) e devolve o texto final.
     *
     * @throws LlmException|RuntimeException quando a IA não consegue responder (quem chama usa o motor local)
     */
    public function responder(string $mensagem, array $historico = []): string
    {
        $mensagem = trim(mb_substr($mensagem, 0, self::MAX_PERGUNTA));
        $mensagens = [['role' => 'system', 'content' => $this->system()]];
        array_push($mensagens, ...self::montarHistorico($historico));
        $mensagens[] = ['role' => 'user', 'content' => $mensagem];

        $prazo = microtime(true) + self::PRAZO_SEGUNDOS;
        $tools = AssistenteFerramentas::definicoes();

        for ($rodada = 1; $rodada <= self::MAX_RODADAS; $rodada++) {
            // Só os campos essenciais: quanto menos o pedido leva, menos chance de um provedor recusar.
            $pedido = ['messages' => $mensagens, 'tools' => $tools];
            if ($rodada === self::MAX_RODADAS) {
                $pedido['tool_choice'] = 'none'; // última rodada: responde com o que já consultou
            }

            $r = Llm::conversar($pedido, $prazo);
            $escolha = $r['choices'][0] ?? null;
            $msg = is_array($escolha) ? ($escolha['message'] ?? null) : null;
            if (!is_array($msg)) {
                throw new RuntimeException('Resposta da IA sem mensagem.');
            }
            $motivo = (string) ($escolha['finish_reason'] ?? '');

            if ($motivo === 'content_filter') {
                return 'Não posso ajudar com esse pedido. Posso responder sobre previsões de pessoas e refeições, '
                    . 'desperdício, acessos, cadastro de pessoas e cartões, dispositivos IoT e o uso do SICAPDA.';
            }

            $chamadas = is_array($msg['tool_calls'] ?? null) ? array_values($msg['tool_calls']) : [];
            if ($chamadas) {
                // Devolve o pedido da IA e o resultado de cada ferramenta, cada um com o id da chamada.
                $chamadas = self::normalizarChamadas($chamadas, $rodada);
                $resultados = array_map(fn($c) => $this->executar($c), $chamadas);
                // Ao reenviar, argumentos que não são um objeto JSON viram "{}" (o erro já foi informado à IA no
                // resultado da ferramenta); provedores como o Gemini recusam a conversa inteira se vier lixo.
                foreach ($chamadas as &$c) {
                    $c['function']['arguments'] = self::argumentosValidos($c['function']['arguments']);
                }
                unset($c);
                $mensagens[] = ['role' => 'assistant', 'content' => $msg['content'] ?? null, 'tool_calls' => $chamadas];
                array_push($mensagens, ...$resultados);
                continue;
            }

            $texto = self::textoFinal($msg['content'] ?? null);
            if ($texto === '') {
                throw new RuntimeException("Resposta da IA sem texto (finish_reason={$motivo}).");
            }
            return $texto;
        }

        throw new RuntimeException('A IA não concluiu a resposta no número máximo de rodadas.');
    }

    // ─── Prompt ──────────────────────────────────────────────────────────────

    /** Regras e conhecimento do sistema (arquivo de texto) seguidos do contexto da conversa (data, empresa, usuário). */
    private function system(): string
    {
        return rtrim((string) file_get_contents(self::ARQUIVO_CONHECIMENTO)) . "\n\n" . $this->contexto();
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

    /** Executa uma chamada de ferramenta pedida pela IA e devolve a mensagem "tool" com o resultado. */
    private function executar(array $chamada): array
    {
        $nome = (string) $chamada['function']['name'];
        $resultado = ['role' => 'tool', 'tool_call_id' => $chamada['id'], 'name' => $nome];

        $argumentos = trim((string) ($chamada['function']['arguments'] ?? ''));
        $entrada = $argumentos === '' ? [] : json_decode($argumentos, true);

        try {
            if (!is_array($entrada)) {
                throw new InvalidArgumentException('Argumentos inválidos: envie um objeto JSON.');
            }
            $dados = $this->ferramentas->executar($nome, $entrada);
            $resultado['content'] = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (InvalidArgumentException $e) {
            $resultado['content'] = 'Erro: ' . $e->getMessage();
        } catch (Throwable $e) {
            error_log('SICAPDA assistente IA, ferramenta ' . $nome . ': ' . $e->getMessage());
            $resultado['content'] = 'Erro: não foi possível consultar este dado agora.';
        }
        return $resultado;
    }

    private static function argumentosValidos(string $argumentos): string
    {
        return trim($argumentos) !== '' && is_array(json_decode($argumentos, true)) ? $argumentos : '{}';
    }

    /**
     * Deixa as chamadas de ferramenta só com os campos do padrão (id, type, function) e garante um id em cada
     * uma. O "extra_content" é mantido: o Gemini usa para reconhecer o raciocínio da rodada anterior.
     */
    private static function normalizarChamadas(array $chamadas, int $rodada): array
    {
        $saida = [];
        foreach ($chamadas as $i => $c) {
            if (!is_array($c) || !is_string($c['function']['name'] ?? null)) {
                continue;
            }
            $args = $c['function']['arguments'] ?? '';
            $n = [
                'id' => is_string($c['id'] ?? null) && $c['id'] !== '' ? $c['id'] : "call_{$rodada}_{$i}",
                'type' => 'function',
                'function' => ['name' => $c['function']['name'], 'arguments' => is_string($args) ? $args : (string) json_encode($args)],
            ];
            if (isset($c['extra_content'])) {
                $n['extra_content'] = $c['extra_content'];
            }
            $saida[] = $n;
        }
        if (!$saida) {
            throw new RuntimeException('A IA pediu uma ferramenta sem nome.');
        }
        return $saida;
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

    /** Texto da resposta (string ou lista de partes), sem as marcações que o chat de texto puro não mostra. */
    private static function textoFinal(mixed $conteudo): string
    {
        if (is_array($conteudo)) {
            $partes = [];
            foreach ($conteudo as $p) {
                $t = is_array($p) ? ($p['text'] ?? '') : $p;
                if (is_string($t) && trim($t) !== '') {
                    $partes[] = trim($t);
                }
            }
            $conteudo = implode("\n\n", $partes);
        }
        $texto = is_string($conteudo) ? trim($conteudo) : '';

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
