<?php

/**
 * Segredo do servidor: o API_SECRET do config/.env (mínimo de 32 caracteres) ou, sem ele, um gerado uma única vez
 * em storage/api_secret.key (fora de public/ e fora do Git). Assina os tokens do aplicativo (ApiAuthMiddleware) e
 * dele saem as chaves que protegem os dados da verificação em duas etapas (DoisFatores).
 */
class Segredo
{
    private static ?string $mestre = null;

    /** @throws RuntimeException se não houver API_SECRET e não der para gravar storage/api_secret.key */
    public static function mestre(): string
    {
        if (self::$mestre !== null) {
            return self::$mestre;
        }

        $env = @parse_ini_file(__DIR__ . '/../../config/.env') ?: [];
        $segredo = (string) ($env['API_SECRET'] ?? '');
        if (strlen($segredo) >= 32) {
            return self::$mestre = $segredo;
        }

        $arquivo = __DIR__ . '/../../storage/api_secret.key';
        $segredo = trim((string) @file_get_contents($arquivo));
        if (strlen($segredo) < 32) {
            $segredo = bin2hex(random_bytes(32));
            if (@file_put_contents($arquivo, $segredo, LOCK_EX) === false) {
                throw new RuntimeException('Defina API_SECRET (mín. 32 caracteres) em config/.env ou permita escrita em storage/.');
            }
            @chmod($arquivo, 0600);
        }
        return self::$mestre = $segredo;
    }

    /** Chave de 32 bytes para um uso específico (cada uso tem a sua; nenhuma revela o segredo). */
    public static function chave(string $uso): string
    {
        return hash_hmac('sha256', 'sicapda:' . $uso, self::mestre(), true);
    }
}
