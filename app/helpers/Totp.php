<?php

/**
 * Códigos de 6 dígitos que mudam a cada 30 segundos (TOTP, RFC 6238), os mesmos que o Google Authenticator,
 * o Microsoft Authenticator, o Authy e outros aplicativos autenticadores mostram. Sem biblioteca.
 *
 * O segredo (20 bytes aleatórios) fica no servidor e no aplicativo da pessoa, que o lê pelo QR Code ou digitando a
 * chave em base32. Os dois calculam o mesmo código a partir do relógio, então funciona sem internet no celular.
 */
final class Totp
{
    public const DIGITOS = 6;
    public const PERIODO = 30;
    /** Aceita o código do intervalo anterior e do seguinte (relógio do celular adiantado ou atrasado até ~30 s). */
    public const JANELA = 1;

    private const ALFABETO_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Segredo novo, em base32 (32 caracteres = 160 bits, o tamanho recomendado para HMAC-SHA1). */
    public static function novoSegredo(): string
    {
        return self::base32(random_bytes(20));
    }

    /** Intervalo de 30 s de um instante (o "passo" do contador). */
    public static function passo(?int $instante = null): int
    {
        return intdiv($instante ?? time(), self::PERIODO);
    }

    /** Código de 6 dígitos (com zeros à esquerda) do segredo num passo. */
    public static function codigo(string $segredoBase32, int $passo): string
    {
        $chave = self::deBase32($segredoBase32);
        $hash = hash_hmac('sha1', pack('J', $passo), $chave, true);
        $deslocamento = ord($hash[19]) & 0x0F;
        $numero = ((ord($hash[$deslocamento]) & 0x7F) << 24)
            | (ord($hash[$deslocamento + 1]) << 16)
            | (ord($hash[$deslocamento + 2]) << 8)
            | ord($hash[$deslocamento + 3]);
        return str_pad((string) ($numero % 10 ** self::DIGITOS), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    /**
     * Confere o código e devolve o passo em que ele bate (ou null). Só aceita passos depois de $depoisDe: assim o
     * mesmo código não serve duas vezes (alguém que o viu por cima do ombro não entra com ele logo depois).
     */
    public static function verificar(string $segredoBase32, string $codigo, ?int $depoisDe = null, ?int $instante = null): ?int
    {
        if (!preg_match('/^\d{' . self::DIGITOS . '}$/', $codigo)) {
            return null;
        }
        $atual = self::passo($instante);
        $encontrado = null;
        // Percorre a janela inteira sempre (mesmo tempo de resposta acertando ou não).
        for ($p = $atual - self::JANELA; $p <= $atual + self::JANELA; $p++) {
            if (hash_equals(self::codigo($segredoBase32, $p), $codigo) && ($depoisDe === null || $p > $depoisDe)) {
                $encontrado ??= $p;
            }
        }
        return $encontrado;
    }

    /**
     * Endereço otpauth:// que vai no QR Code. O nome aparece no aplicativo como "SICAPDA (maria@empresa.com)".
     * https://github.com/google/google-authenticator/wiki/Key-Uri-Format
     */
    public static function uri(string $segredoBase32, string $conta, string $emissor = 'SICAPDA'): string
    {
        return 'otpauth://totp/' . rawurlencode($emissor) . ':' . rawurlencode($conta)
            . '?secret=' . $segredoBase32
            . '&issuer=' . rawurlencode($emissor)
            . '&algorithm=SHA1&digits=' . self::DIGITOS . '&period=' . self::PERIODO;
    }

    /** "JBSW Y3DP EHPK ..." para digitar à mão no aplicativo. */
    public static function formatar(string $segredoBase32): string
    {
        return trim(chunk_split($segredoBase32, 4, ' '));
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $saida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $saida .= self::ALFABETO_BASE32[bindec(str_pad($grupo, 5, '0'))];
        }
        return $saida;
    }

    public static function deBase32(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[\s=-]+/', '', $texto));
        $bits = '';
        foreach (str_split($texto) as $c) {
            $v = strpos(self::ALFABETO_BASE32, $c);
            if ($v === false) {
                throw new InvalidArgumentException('Segredo em base32 inválido.');
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $saida = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $saida .= chr(bindec($byte));
            }
        }
        return $saida;
    }
}
