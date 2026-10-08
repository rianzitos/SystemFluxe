<?php

/**
 * Informações do aplicativo mobile (APK) oferecido para download na página /sicapda.
 *
 * O APK fica FORA de public/ (storage/downloads/SICAPDA.apk) e só sai pela rota /app/baixar
 * (AppMobileController). Ao lado dele fica o app.json (versão, tamanho, SHA-256, data),
 * gerado pelo CI do fluxe_app (GitHub Actions) ou por tool/publicar_apk.ps1.
 *
 * O manifesto só é usado se conferir com o arquivo (mesmo tamanho). Se alguém trocar o APK
 * e esquecer o app.json, a página mostra apenas o que dá para confirmar (tamanho e data do
 * arquivo) em vez de exibir uma versão/SHA-256 errados.
 */
class AppMobile
{
    public const ARQUIVO = 'SICAPDA.apk';
    public const MANIFESTO = 'app.json';
    public const URL_DOWNLOAD = '/app/baixar';

    /** minSdk padrão do Flutter atual (Android 7.0), usado se o manifesto não informar. */
    private const MIN_SDK_PADRAO = 24;

    private const VERSOES_ANDROID = [
        21 => '5.0', 22 => '5.1', 23 => '6.0', 24 => '7.0', 25 => '7.1', 26 => '8.0', 27 => '8.1',
        28 => '9', 29 => '10', 30 => '11', 31 => '12', 32 => '12L', 33 => '13', 34 => '14', 35 => '15', 36 => '16',
    ];

    public static function diretorio(): string
    {
        return dirname(__DIR__, 2) . '/storage/downloads';
    }

    public static function caminhoApk(): string
    {
        return self::diretorio() . '/' . self::ARQUIVO;
    }

    /** O arquivo existe, é legível e não está vazio? */
    public static function disponivel(): bool
    {
        $caminho = self::caminhoApk();
        return is_file($caminho) && is_readable($caminho) && (int) @filesize($caminho) > 0;
    }

    /** Lê o app.json; null se não existir ou for inválido (aceita BOM UTF-8). */
    private static function lerManifesto(): ?array
    {
        $caminho = self::diretorio() . '/' . self::MANIFESTO;
        if (!is_file($caminho) || !is_readable($caminho)) {
            return null;
        }
        $json = json_decode(ltrim((string) @file_get_contents($caminho), "\xEF\xBB\xBF"), true);
        return is_array($json) ? $json : null;
    }

    /** "24,3 MB" / "812 KB" */
    public static function formatarTamanho(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.') . ' KB';
    }

    /** minSdk -> "7.0" (null se for um nível desconhecido) */
    public static function versaoAndroid(int $minSdk): ?string
    {
        return self::VERSOES_ANDROID[$minSdk] ?? null;
    }

    /**
     * Tudo que a página precisa. Sempre devolve todas as chaves.
     *  disponivel, versao, tamanho_bytes, tamanho, atualizado_em (d/m/Y), atualizado_iso, sha256,
     *  android_minimo ("Android 7.0 ou superior"), nome_arquivo, url
     */
    public static function info(): array
    {
        $info = [
            'disponivel'     => false,
            'versao'         => null,
            'tamanho_bytes'  => 0,
            'tamanho'        => null,
            'atualizado_em'  => null,
            'atualizado_iso' => null,
            'sha256'         => null,
            'android_minimo' => 'Android ' . self::VERSOES_ANDROID[self::MIN_SDK_PADRAO] . ' ou superior',
            'nome_arquivo'   => self::ARQUIVO,
            'url'            => self::URL_DOWNLOAD,
        ];

        if (!self::disponivel()) {
            return $info;
        }

        $caminho = self::caminhoApk();
        $tamanho = (int) filesize($caminho);
        $mtime = (int) filemtime($caminho);

        $info['disponivel'] = true;
        $info['tamanho_bytes'] = $tamanho;
        $info['tamanho'] = self::formatarTamanho($tamanho);

        $data = (new DateTimeImmutable('@' . $mtime))->setTimezone(new DateTimeZone('America/Sao_Paulo'));

        $m = self::lerManifesto();
        if ($m !== null && (int) ($m['tamanho'] ?? -1) === $tamanho) {
            $versao = (string) ($m['versao'] ?? '');
            if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $versao)) {
                $info['versao'] = $versao;
                $info['nome_arquivo'] = 'SICAPDA-' . $versao . '.apk';
            }

            $sha = strtolower((string) ($m['sha256'] ?? ''));
            if (preg_match('/^[a-f0-9]{64}$/', $sha)) {
                $info['sha256'] = $sha;
            }

            $rotulo = self::versaoAndroid((int) ($m['min_sdk'] ?? 0));
            if ($rotulo !== null) {
                $info['android_minimo'] = 'Android ' . $rotulo . ' ou superior';
            }

            try {
                if (!empty($m['atualizado_em'])) {
                    $data = (new DateTimeImmutable((string) $m['atualizado_em']))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
                }
            } catch (Exception) {
                // data inválida no manifesto: fica a do arquivo
            }
        }

        $info['atualizado_em'] = $data->format('d/m/Y');
        $info['atualizado_iso'] = $data->format('Y-m-d');

        return $info;
    }
}
