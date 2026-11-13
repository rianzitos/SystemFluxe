<?php

/**
 * Leitura do config/.env (o mesmo arquivo do banco e do API_SECRET), lido uma vez por requisição.
 * Uso: Env::get('MAIL_HOST'), Env::get('MAIL_PORT', '587').
 */
class Env
{
    private static ?array $valores = null;

    public static function get(string $chave, ?string $padrao = null): ?string
    {
        if (self::$valores === null) {
            // INI_SCANNER_RAW: senhas com caracteres como ! ; ( ) não quebram a leitura nem viram booleanos.
            self::$valores = @parse_ini_file(__DIR__ . '/../../config/.env', false, INI_SCANNER_RAW) ?: [];
        }
        $valor = self::$valores[$chave] ?? null;
        if ($valor === null) {
            return $padrao;
        }
        $valor = trim((string) $valor);
        // No modo RAW as aspas ficam no valor: MAIL_FROM_NAME="SICAPDA by Fluxe" → SICAPDA by Fluxe
        if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[strlen($valor) - 1] === $valor[0]) {
            $valor = substr($valor, 1, -1);
        }
        return $valor === '' ? $padrao : $valor;
    }

    /** Endereço público do site (links dos e-mails), sem barra no fim. */
    public static function urlSite(): string
    {
        $url = self::get('APP_URL');
        if ($url) {
            return rtrim($url, '/');
        }
        // Sem APP_URL. Nunca confiamos no cabeçalho Host em produção: quem pede "esqueci a senha" poderia
        // forjá-lo e fazer o link do e-mail apontar para outro site. Só no servidor de desenvolvimento (php -S).
        if (PHP_SAPI === 'cli-server' && preg_match('/^[a-z0-9.\-]+(:\d+)?$/i', (string) ($_SERVER['HTTP_HOST'] ?? ''))) {
            return 'http://' . $_SERVER['HTTP_HOST'];
        }
        return 'https://fluxeteam.com.br';
    }
}
