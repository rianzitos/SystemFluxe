<?php

/**
 * Teste passo a passo da IA do assistente, para o administrador descobrir por que ela não responde
 * (tela /assistente/diagnostico). Cada etapa mostra se passou ou o motivo da falha. Nunca mostra a chave inteira.
 */
class AssistenteDiagnostico
{
    /** Configuração lida do servidor (sem chamar a IA). Cada linha: [rótulo, valor, ok|aviso|erro]. */
    public static function configuracao(): array
    {
        $chave = Llm::chave();
        $curl = function_exists('curl_init') ? (curl_version()['version'] ?? '?') . ' (' . (curl_version()['ssl_version'] ?? 'sem SSL') . ')' : null;

        return [
            ['PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'aviso'],
            ['Extensão cURL', $curl ?? 'NÃO instalada', $curl ? 'ok' : 'erro'],
            ['IA_API_KEY', $chave === null ? 'vazia ou não encontrada no config/.env' : substr($chave, 0, 4) . '… (' . strlen($chave) . ' caracteres)', $chave === null ? 'erro' : 'ok'],
            ['IA_MODEL', Llm::modelo(), 'ok'],
            ['IA_BASE_URL', Env::get('IA_BASE_URL', Llm::BASE_PADRAO), 'ok'],
            ['Arquivo de regras (app/conhecimento/assistente.md)', is_readable(__DIR__ . '/../conhecimento/assistente.md') ? 'encontrado' : 'NÃO encontrado', is_readable(__DIR__ . '/../conhecimento/assistente.md') ? 'ok' : 'erro'],
            ['IA ligada no assistente', AssistenteIA::ativa() ? 'sim' : 'não', AssistenteIA::ativa() ? 'ok' : 'erro'],
        ];
    }

    /**
     * Roda as etapas que chamam a IA. Cada uma: ['titulo', 'ok' => bool, 'detalhe' => string, 'ms' => int].
     * Gasta de 3 a 4 chamadas da cota gratuita.
     */
    public static function testar(int $empresaId, array $usuario): array
    {
        $etapas = [];
        $executar = function (string $titulo, callable $f) use (&$etapas): bool {
            $t0 = microtime(true);
            try {
                $detalhe = $f();
                $etapas[] = ['titulo' => $titulo, 'ok' => true, 'detalhe' => $detalhe, 'ms' => (int) ((microtime(true) - $t0) * 1000)];
                return true;
            } catch (Throwable $e) {
                $etapas[] = ['titulo' => $titulo, 'ok' => false, 'detalhe' => AssistenteIA::explicarFalha($e), 'ms' => (int) ((microtime(true) - $t0) * 1000)];
                return false;
            }
        };

        $prazo = fn() => microtime(true) + 40;

        // 1) Conexão, chave e modelo, com o pedido mais simples possível (sem ferramentas).
        $ok = $executar('1. Conexão, chave e modelo (pedido simples, sem ferramentas)', function () use ($prazo) {
            $r = Llm::conversar(['messages' => [['role' => 'user', 'content' => 'Responda apenas com a palavra OK.']]], $prazo());
            $t = $r['choices'][0]['message']['content'] ?? null;
            return 'A IA respondeu: ' . mb_substr(is_string($t) ? trim($t) : json_encode($t), 0, 120);
        });
        if (!$ok) {
            return $etapas;
        }

        // 2) Pedido do assistente de verdade: regras + 8 ferramentas, pergunta de uso (não precisa de dados).
        $assistente = new AssistenteIA($empresaId, $usuario);
        $ok = $executar('2. Pergunta de uso com regras e ferramentas ("Como vincular um cartão?")', function () use ($assistente) {
            return mb_substr($assistente->responder('Como vincular um cartão?'), 0, 400);
        });
        if (!$ok) {
            return $etapas;
        }

        // 3) Pergunta que obriga a IA a consultar uma ferramenta e continuar a conversa com o resultado.
        $executar('3. Pergunta com consulta aos dados ("Qual a precisão das previsões?")', function () use ($assistente) {
            return mb_substr($assistente->responder('Qual a precisão das previsões?'), 0, 400);
        });

        // 4) Pergunta fora do escopo: precisa ser recusada.
        $executar('4. Pergunta fora do assunto ("Me dá uma receita de bolo de cenoura") deve ser recusada', function () use ($assistente) {
            return mb_substr($assistente->responder('Me dá uma receita de bolo de cenoura'), 0, 400);
        });

        return $etapas;
    }
}
