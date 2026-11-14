<?php

/**
 * Cliente mínimo de IA no formato "chat completions", o mesmo que o Gemini (Google), o Groq, o OpenRouter,
 * o Ollama e a OpenAI aceitam: POST {IA_BASE_URL}/chat/completions, com a extensão cURL do PHP.
 *
 * Sem biblioteca de propósito: o site roda em hospedagem compartilhada, sem Composer (o mesmo motivo do
 * Smtp.php). Usado pelo AssistenteIA.
 *
 * Configuração no config/.env (veja config/.env.example). Para trocar de provedor, mude só estas três:
 *   IA_API_KEY   chave da API. Vazia = assistente sem IA (motor local).
 *   IA_MODEL     nome do modelo (padrão gemini-2.5-flash).
 *   IA_BASE_URL  endereço da API (padrão: o do Gemini, https://generativelanguage.googleapis.com/v1beta/openai).
 */
class Llm
{
    public const BASE_PADRAO = 'https://generativelanguage.googleapis.com/v1beta/openai';
    public const MODELO_PADRAO = 'gemini-2.5-flash';

    private const TENTATIVAS = 3;
    /** Respostas que valem uma nova tentativa. O 429 (limite de uso) só se o servidor pedir uma espera curta. */
    private const STATUS_RETENTAVEIS = [408, 409, 500, 502, 503, 504, 529];
    private const ESPERA_MAXIMA_429 = 8;

    /** Segundos pedidos pela API no cabeçalho retry-after da última resposta (null = não pediu). */
    private static ?int $ultimoRetryAfter = null;

    public static function chave(): ?string
    {
        $chave = Env::get('IA_API_KEY') ?? (getenv('IA_API_KEY') ?: null);
        return $chave !== null && trim($chave) !== '' ? trim($chave) : null;
    }

    /** A IA está disponível neste servidor (chave configurada e extensão cURL instalada). */
    public static function configurado(): bool
    {
        return self::chave() !== null && function_exists('curl_init');
    }

    public static function modelo(): string
    {
        return Env::get('IA_MODEL', self::MODELO_PADRAO);
    }

    private static function baseUrl(): string
    {
        return rtrim(Env::get('IA_BASE_URL', self::BASE_PADRAO), '/');
    }

    /**
     * Envia um pedido para /chat/completions e devolve a resposta decodificada (array).
     *
     * $corpo segue o formato da API (messages, tools, tool_choice, max_tokens...); "model" é preenchido
     * aqui se faltar. $prazo é o instante (microtime) em que a pergunta toda precisa terminar: as novas
     * tentativas param antes dele.
     *
     * @throws LlmException
     */
    public static function conversar(array $corpo, float $prazo): array
    {
        $chave = self::chave();
        if ($chave === null || !function_exists('curl_init')) {
            throw new LlmException('IA não configurada (IA_API_KEY vazia ou extensão cURL ausente).');
        }

        $corpo['model'] ??= self::modelo();
        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new LlmException('Não foi possível montar o pedido: ' . json_last_error_msg());
        }

        $url = self::baseUrl() . '/chat/completions';
        $cabecalhos = ['Content-Type: application/json', 'Authorization: Bearer ' . $chave];

        for ($tentativa = 1; ; $tentativa++) {
            $restante = $prazo - microtime(true);
            if ($restante < 3) {
                throw new LlmException('Tempo esgotado esperando a IA.', 0, true);
            }

            try {
                return self::enviar($url, $cabecalhos, $json, (int) min(60, $restante));
            } catch (LlmException $e) {
                if (!$e->retentavel() || $tentativa >= self::TENTATIVAS) {
                    throw $e;
                }
                // Espera curta e crescente (1 s, 3 s), ou o retry-after da API se for pequeno.
                $espera = self::$ultimoRetryAfter !== null && self::$ultimoRetryAfter <= self::ESPERA_MAXIMA_429
                    ? max(1, self::$ultimoRetryAfter)
                    : ($tentativa === 1 ? 1 : 3);
                if (microtime(true) + $espera + 3 > $prazo) {
                    throw $e;
                }
                sleep($espera);
            }
        }
    }

    /** @throws LlmException */
    private static function enviar(string $url, array $cabecalhos, string $json, int $timeout): array
    {
        self::$ultimoRetryAfter = null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $cabecalhos,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, $timeout),
            CURLOPT_HEADERFUNCTION => function ($ch, string $linha): int {
                if (preg_match('/^retry-after:\s*(\d+)/i', $linha, $m)) {
                    self::$ultimoRetryAfter = (int) $m[1];
                }
                return strlen($linha);
            },
        ]);

        $bruto = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erroRede = curl_errno($ch) ? curl_error($ch) : '';
        curl_close($ch);

        if ($bruto === false || $erroRede !== '') {
            throw new LlmException('Falha de conexão com a IA: ' . $erroRede, 0, true);
        }

        $dados = json_decode((string) $bruto, true);

        if ($status >= 400 || !is_array($dados)) {
            // Alguns provedores (o Gemini, por exemplo) devolvem o erro dentro de uma lista: [{"error": {...}}].
            $erro = is_array($dados) ? ($dados['error'] ?? ($dados[0]['error'] ?? null)) : null;
            $msg = is_array($erro) ? (string) ($erro['message'] ?? '') : (is_string($erro) ? $erro : mb_substr((string) $bruto, 0, 200));
            $retentavel = in_array($status, self::STATUS_RETENTAVEIS, true)
                || ($status === 429 && self::$ultimoRetryAfter !== null && self::$ultimoRetryAfter <= self::ESPERA_MAXIMA_429);
            throw new LlmException("IA respondeu {$status}: " . mb_substr($msg, 0, 300), $status, $retentavel);
        }

        return $dados;
    }
}
