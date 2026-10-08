<?php

/**
 * Autenticação da API do aplicativo mobile (Bearer token).
 *
 * A web usa sessão + cookie; o app não tem cookie, então o login da API devolve
 * um token assinado (HMAC-SHA256) com validade. O segredo fica em config/.env
 * (API_SECRET) e nunca sai do servidor.
 *
 * Formato: base64url(json{uid, exp}) . '.' . base64url(hmac)
 */
class ApiAuthMiddleware
{
    private const VALIDADE_SEGUNDOS = 30 * 24 * 3600; // 30 dias

    private static function segredo(): string
    {
        $env = @parse_ini_file(__DIR__ . '/../../config/.env') ?: [];
        $segredo = (string) ($env['API_SECRET'] ?? '');
        if (strlen($segredo) >= 32) {
            return $segredo;
        }

        // Sem API_SECRET no .env: gera um uma única vez e guarda em storage/ (fora de public/, fora do Git).
        $arquivo = __DIR__ . '/../../storage/api_secret.key';
        $segredo = trim((string) @file_get_contents($arquivo));
        if (strlen($segredo) < 32) {
            $segredo = bin2hex(random_bytes(32));
            if (@file_put_contents($arquivo, $segredo, LOCK_EX) === false) {
                error_log('SICAPDA API: defina API_SECRET (mín. 32 caracteres) em config/.env ou permita escrita em storage/.');
                Api::erro(500, 'API não configurada no servidor.');
            }
            @chmod($arquivo, 0600);
        }
        return $segredo;
    }

    private static function b64(string $dados): string
    {
        return rtrim(strtr(base64_encode($dados), '+/', '-_'), '=');
    }

    private static function deB64(string $dados): string|false
    {
        return base64_decode(strtr($dados, '-_', '+/'), true);
    }

    public static function emitir(int $usuarioId): array
    {
        $exp = time() + self::VALIDADE_SEGUNDOS;
        $payload = self::b64(json_encode(['uid' => $usuarioId, 'exp' => $exp]));
        $assinatura = self::b64(hash_hmac('sha256', $payload, self::segredo(), true));

        return ['token' => $payload . '.' . $assinatura, 'expira_em' => $exp];
    }

    /** Lê "Authorization: Bearer ..." (inclusive em Apache/CGI, que costuma escondê-lo). */
    private static function tokenDoCabecalho(): string
    {
        $cab = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? getallheaders()['authorization'] ?? '') : '');

        return preg_match('/^Bearer\s+(\S+)$/i', (string) $cab, $m) ? $m[1] : '';
    }

    /**
     * Valida o token e devolve o usuário atual (lido do banco a cada chamada,
     * então usuário removido perde acesso na hora). Encerra com 401 se inválido.
     */
    public static function autenticar(): array
    {
        $token = self::tokenDoCabecalho();
        $partes = explode('.', $token);
        if (count($partes) !== 2) {
            Api::erro(401, 'Não autenticado.');
        }

        [$payload, $assinatura] = $partes;
        $esperada = self::b64(hash_hmac('sha256', $payload, self::segredo(), true));
        if (!hash_equals($esperada, $assinatura)) {
            Api::erro(401, 'Token inválido.');
        }

        $dados = json_decode((string) self::deB64($payload), true);
        if (!is_array($dados) || empty($dados['uid']) || (int) ($dados['exp'] ?? 0) < time()) {
            Api::erro(401, 'Sessão expirada. Faça login novamente.');
        }

        $usuario = (new Usuario())->buscarPorId((int) $dados['uid']);
        if (!$usuario || empty($usuario['empresa_id'])) {
            Api::erro(401, 'Usuário não encontrado.');
        }

        return $usuario;
    }
}
