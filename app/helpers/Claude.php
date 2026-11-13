<?php

/**
 * Cliente mínimo da API de mensagens do Claude (Anthropic): POST /v1/messages com a extensão cURL do PHP.
 *
 * Sem biblioteca de propósito: o site roda em hospedagem compartilhada, sem Composer (o mesmo motivo do
 * Smtp.php). Usado pelo AssistenteIA.
 *
 * Configuração no config/.env (veja config/.env.example):
 *   ANTHROPIC_API_KEY  chave da API (console.anthropic.com). Vazia = assistente sem IA (motor local).
 *   ANTHROPIC_MODEL    modelo (padrão claude-opus-5-5).
 *   ANTHROPIC_EFFORT   esforço de raciocínio: low (padrão, respostas mais rápidas), medium ou high.
 *   ANTHROPIC_BASE_URL endereço da API (padrão https://api.anthropic.com; troque só para testes ou proxy).
 */
class Claude
{
    public const MODELO_PADRAO = 'claude-opus-5-5';
    private const VERSAO_API = '2026-10-21';
    private const URL_PADRAO = 'https://api.anthropic.com';

    /**
     * Modelos que aceitam o "fallbacks" do servidor: se o filtro de segurança do modelo recusar uma pergunta
     * legítima por engano, a própria API refaz a pergunta com o modelo recomendado, na mesma chamada.
     */
    private const MODELOS_COM_FALLBACK = ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-2', 'claude-fable-5-1'];
    private const BETA_FALLBACK = 'server-side-fallback-2026-07-01';

    private const ESFORCOS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** Respostas que valem uma nova tentativa: limite de uso, erro do servidor, sobrecarga. */
    private const STATUS_RETENTAVEIS = [408, 409, 429, 500, 502, 503, 504, 529];
    private const TENTATIVAS = 3;

    /** Segundos pedidos pela API no cabeçalho retry-after da última resposta (null = não pediu). */
    private static ?int $ultimoRetryAfter = null;

    public static function chave(): ?string
    {
        $chave = Env::get('ANTHROPIC_API_KEY') ?? (getenv('ANTHROPIC_API_KEY') ?: null);
        return $chave !== null && trim($chave) !== '' ? trim($chave) : null;
    }

    /** A IA está disponível neste servidor (chave configurada e extensão cURL instalada). */
    public static function configurado(): bool
    {
        return self::chave() !== null && function_exists('curl_init');
    }

    public static function modelo(): string
    {
        return Env::get('ANTHROPIC_MODEL', self::MODELO_PADRAO);
    }

    /** Esforço de raciocínio (output_config.effort). "padrao" deixa a API decidir. */
    public static function esforco(): ?string
    {
        $e = strtolower(Env::get('ANTHROPIC_EFFORT', 'low'));
        return in_array($e, self::ESFORCOS, true) ? $e : null;
    }

    /**
     * Envia um pedido para /v1/messages e devolve a resposta.
     *
     * $corpo segue o formato da API (model, max_tokens, system, tools, messages...); "model" e o esforço
     * são preenchidos aqui se faltarem. $prazo é o instante (microtime) em que a pergunta toda precisa
     * terminar: as novas tentativas param antes dele.
     *
     * Retorna ['dados' => resposta como array associativo, 'conteudo' => o mesmo "content" decodificado como
     * objetos]. Use 'conteudo' para devolver a resposta à API no próximo pedido: com arrays, um "input": {}
     * vazio viraria [] no json_encode e a API recusaria o pedido.
     *
     * @throws ClaudeException
     */
    public static function mensagens(array $corpo, float $prazo): array
    {
        $chave = self::chave();
        if ($chave === null || !function_exists('curl_init')) {
            throw new ClaudeException('IA não configurada (ANTHROPIC_API_KEY vazia ou extensão cURL ausente).');
        }

        $corpo['model'] ??= self::modelo();
        if (!isset($corpo['output_config']['effort']) && ($esforco = self::esforco()) !== null) {
            $corpo['output_config']['effort'] = $esforco;
        }

        $cabecalhos = [
            'Content-Type: application/json',
            'x-api-key: ' . $chave,
            'anthropic-version: ' . self::VERSAO_API,
        ];
        if (in_array($corpo['model'], self::MODELOS_COM_FALLBACK, true) && !isset($corpo['fallbacks'])) {
            $corpo['fallbacks'] = 'default';
            $cabecalhos[] = 'anthropic-beta: ' . self::BETA_FALLBACK;
        }

        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new ClaudeException('Não foi possível montar o pedido: ' . json_last_error_msg());
        }

        $url = rtrim(Env::get('ANTHROPIC_BASE_URL', self::URL_PADRAO), '/') . '/v1/messages';

        for ($tentativa = 1; ; $tentativa++) {
            $restante = $prazo - microtime(true);
            if ($restante < 3) {
                throw new ClaudeException('Tempo esgotado esperando a IA.', 0, 'timeout', true);
            }

            try {
                return self::enviar($url, $cabecalhos, $json, (int) min(60, $restante));
            } catch (ClaudeException $e) {
                if (!$e->retentavel() || $tentativa >= self::TENTATIVAS) {
                    throw $e;
                }
                // Espera curta e crescente (1 s, 3 s), ou o retry-after da API se for pequeno.
                $espera = self::$ultimoRetryAfter !== null && self::$ultimoRetryAfter <= 8
                    ? self::$ultimoRetryAfter
                    : ($tentativa === 1 ? 1 : 3);
                if (microtime(true) + $espera + 3 > $prazo) {
                    throw $e;
                }
                sleep($espera);
            }
        }
    }

    /** @throws ClaudeException */
    private static function enviar(string $url, array $cabecalhos, string $json, int $timeout): array
    {
        self::$ultimoRetryAfter = null;
        $requestId = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $cabecalhos,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, $timeout),
            CURLOPT_HEADERFUNCTION => function ($ch, string $linha) use (&$requestId): int {
                if (preg_match('/^retry-after:\s*(\d+)/i', $linha, $m)) {
                    self::$ultimoRetryAfter = (int) $m[1];
                } elseif (preg_match('/^request-id:\s*(\S+)/i', $linha, $m)) {
                    $requestId = $m[1];
                }
                return strlen($linha);
            },
        ]);

        $bruto = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erroRede = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        if ($bruto === false || $erroRede !== '') {
            throw new ClaudeException('Falha de conexão com a API do Claude: ' . $erroRede, 0, 'conexao', true);
        }

        $dados = json_decode((string) $bruto, true);
        $sufixo = $requestId !== '' ? " (request-id {$requestId})" : '';

        if ($status >= 400 || !is_array($dados)) {
            $tipo = is_array($dados) ? (string) ($dados['error']['type'] ?? '') : '';
            $msg  = is_array($dados) ? (string) ($dados['error']['message'] ?? '') : mb_substr((string) $bruto, 0, 200);
            throw new ClaudeException(
                "API do Claude respondeu {$status} {$tipo}: {$msg}{$sufixo}",
                $status,
                $tipo,
                in_array($status, self::STATUS_RETENTAVEIS, true) || $status === 0,
            );
        }

        $objeto = json_decode((string) $bruto);
        return ['dados' => $dados, 'conteudo' => is_array($objeto->content ?? null) ? $objeto->content : []];
    }
}
