<?php

/**
 * Aplicativo do SICAPDA (Windows e Android) oferecido para download na página /sicapda.
 *
 * Os instaladores ficam FORA de public/ (storage/downloads) e só saem pelas rotas /app/baixar...
 * (AppMobileController). Ao lado de cada um fica um manifesto .json (versão, tamanho, data),
 * gerado pelo CI do fluxe_app (GitHub Actions) ou por tool/publicar_app.ps1:
 *
 *   Windows → SICAPDA-Setup.exe + app-windows.json
 *   Android → SICAPDA.apk       + app.json
 *
 * O manifesto só é usado se conferir com o arquivo (mesmo tamanho). Se alguém trocar o instalador
 * e esquecer o manifesto, a página mostra apenas o que dá para confirmar (tamanho e data do
 * arquivo) em vez de exibir uma versão errada.
 */
class AppMobile
{
    public const URL_DOWNLOAD = '/app/baixar';

    /** minSdk padrão do Flutter atual (Android 7.0), usado se o manifesto não informar. */
    private const MIN_SDK_PADRAO = 24;

    private const VERSOES_ANDROID = [
        21 => '5.0', 22 => '5.1', 23 => '6.0', 24 => '7.0', 25 => '7.1', 26 => '8.0', 27 => '8.1',
        28 => '9', 29 => '10', 30 => '11', 31 => '12', 32 => '12L', 33 => '13', 34 => '14', 35 => '15', 36 => '16',
    ];

    /** Ordem = ordem em que aparecem na página. */
    private const PLATAFORMAS = [
        'windows' => [
            'rotulo'    => 'Windows',
            'arquivo'   => 'SICAPDA-Setup.exe',
            'manifesto' => 'app-windows.json',
            'prefixo'   => 'SICAPDA-Setup',
            'extensao'  => 'exe',
            'mime'      => 'application/vnd.microsoft.portable-executable',
            'requer'    => 'Windows 10 ou superior',
        ],
        'android' => [
            'rotulo'    => 'Android',
            'arquivo'   => 'SICAPDA.apk',
            'manifesto' => 'app.json',
            'prefixo'   => 'SICAPDA',
            'extensao'  => 'apk',
            'mime'      => 'application/vnd.android.package-archive',
            'requer'    => null, // calculado a partir do min_sdk
        ],
    ];

    /** @return string[] windows, android */
    public static function plataformas(): array
    {
        return array_keys(self::PLATAFORMAS);
    }

    public static function plataformaValida(?string $plataforma): bool
    {
        return $plataforma !== null && isset(self::PLATAFORMAS[$plataforma]);
    }

    public static function diretorio(): string
    {
        return dirname(__DIR__, 2) . '/storage/downloads';
    }

    public static function caminho(string $plataforma): string
    {
        return self::diretorio() . '/' . self::PLATAFORMAS[$plataforma]['arquivo'];
    }

    /** O arquivo existe, é legível e não está vazio? */
    public static function disponivel(string $plataforma): bool
    {
        $caminho = self::caminho($plataforma);
        return is_file($caminho) && is_readable($caminho) && (int) @filesize($caminho) > 0;
    }

    /** Lê o manifesto; null se não existir ou for inválido (aceita BOM UTF-8). */
    private static function lerManifesto(string $plataforma): ?array
    {
        $caminho = self::diretorio() . '/' . self::PLATAFORMAS[$plataforma]['manifesto'];
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
     * Tudo que a página precisa de uma plataforma. Sempre devolve todas as chaves:
     *  plataforma, rotulo, disponivel, versao, tamanho_bytes, tamanho, atualizado_em (d/m/Y),
     *  atualizado_iso, requer ("Windows 10 ou superior"), nome_arquivo, mime, url
     */
    public static function info(string $plataforma): array
    {
        $cfg = self::PLATAFORMAS[$plataforma];

        $requer = $cfg['requer'];
        if ($plataforma === 'android') {
            $requer = 'Android ' . self::VERSOES_ANDROID[self::MIN_SDK_PADRAO] . ' ou superior';
        }

        $info = [
            'plataforma'     => $plataforma,
            'rotulo'         => $cfg['rotulo'],
            'disponivel'     => false,
            'versao'         => null,
            'tamanho_bytes'  => 0,
            'tamanho'        => null,
            'atualizado_em'  => null,
            'atualizado_iso' => null,
            'requer'         => $requer,
            'nome_arquivo'   => $cfg['arquivo'],
            'mime'           => $cfg['mime'],
            'url'            => self::URL_DOWNLOAD . '/' . $plataforma,
        ];

        if (!self::disponivel($plataforma)) {
            return $info;
        }

        $caminho = self::caminho($plataforma);
        $tamanho = (int) filesize($caminho);
        $mtime = (int) filemtime($caminho);

        $info['disponivel'] = true;
        $info['tamanho_bytes'] = $tamanho;
        $info['tamanho'] = self::formatarTamanho($tamanho);

        $data = (new DateTimeImmutable('@' . $mtime))->setTimezone(new DateTimeZone('America/Sao_Paulo'));

        $m = self::lerManifesto($plataforma);
        if ($m !== null && (int) ($m['tamanho'] ?? -1) === $tamanho) {
            $versao = (string) ($m['versao'] ?? '');
            if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,31}$/', $versao)) {
                $info['versao'] = $versao;
                $info['nome_arquivo'] = $cfg['prefixo'] . '-' . $versao . '.' . $cfg['extensao'];
            }

            if ($plataforma === 'android') {
                $rotulo = self::versaoAndroid((int) ($m['min_sdk'] ?? 0));
                if ($rotulo !== null) {
                    $info['requer'] = 'Android ' . $rotulo . ' ou superior';
                }
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

    /** @return array<string, array> info() de cada plataforma, indexado por plataforma */
    public static function todas(): array
    {
        $todas = [];
        foreach (self::plataformas() as $plataforma) {
            $todas[$plataforma] = self::info($plataforma);
        }
        return $todas;
    }

    /**
     * Aparelho de quem está vendo a página, pelo User-Agent:
     * windows | android | ios | mac | linux | outro.
     * (iPad com iPadOS 13+ se apresenta como Mac: cai em "mac", que mostra o mesmo aviso do iPhone.)
     */
    public static function aparelhoDoVisitante(?string $userAgent = null): string
    {
        $ua = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/i', $ua)    => 'ios',
            (bool) preg_match('/Android/i', $ua)             => 'android',
            (bool) preg_match('/Windows NT/i', $ua)          => 'windows',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua)  => 'mac',
            (bool) preg_match('/Linux|X11|CrOS/i', $ua)      => 'linux',
            default                                          => 'outro',
        };
    }

    /** windows | android, ou null se o aparelho não tem instalador (iPhone, Mac, Linux...). */
    public static function plataformaDoVisitante(?string $userAgent = null): ?string
    {
        $aparelho = self::aparelhoDoVisitante($userAgent);
        return self::plataformaValida($aparelho) ? $aparelho : null;
    }
}
