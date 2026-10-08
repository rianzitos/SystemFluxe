<?php

/**
 * Download do aplicativo mobile (APK).
 *
 *   GET /app          → atalho curto para a seção de download da página /sicapda
 *   GET|HEAD /app/baixar → entrega o APK (público, sem login)
 *
 * O arquivo é servido por PHP (e não direto da pasta public/) para garantir o Content-Type e o
 * nome corretos em qualquer servidor, aceitar retomada de download (Range) e revalidação
 * (ETag / If-Modified-Since). Nenhum dado vindo do usuário entra no caminho do arquivo.
 */
class AppMobileController
{
    private const TAMANHO_BLOCO = 65536;

    /** GET /app — bom para QR codes e materiais impressos. */
    public function atalho(): void
    {
        header('Location: /sicapda#baixar', true, 302);
        exit;
    }

    public function baixar(): void
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($metodo !== 'GET' && $metodo !== 'HEAD') {
            header('Allow: GET, HEAD');
            http_response_code(405);
            exit;
        }

        $info = AppMobile::info();
        if (!$info['disponivel']) {
            $this->indisponivel($metodo);
        }

        $caminho = AppMobile::caminhoApk();
        $tamanho = $info['tamanho_bytes'];
        $mtime = (int) filemtime($caminho);
        // ETag barata (não calcula o hash de dezenas de MB a cada requisição)
        $etag = '"' . substr(hash('sha256', $tamanho . ':' . $mtime), 0, 32) . '"';
        $ultimaModificacao = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';

        // config/app.php abre a sessão em toda requisição; solta o lock para o download longo
        // não travar as outras abas do mesmo usuário.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // O bootstrap do site inicia a sessão e, com ela, envia Set-Cookie/Pragma/Expires "anti-cache".
        // Para um arquivo público isso só atrapalha (impede cache em proxies/CDN): remove.
        header_remove('Set-Cookie');
        header_remove('Pragma');
        header_remove('Expires');

        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="' . $info['nome_arquivo'] . '"');
        header('Accept-Ranges: bytes');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $ultimaModificacao);
        header('Cache-Control: public, no-cache'); // pode guardar, mas sempre revalida (ETag → 304)
        header('X-Content-Type-Options: nosniff');

        // Requisição condicional: o navegador já tem exatamente este arquivo.
        $inm = trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        $ims = trim($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($inm !== '' ? $this->etagConfere($inm, $etag) : ($ims !== '' && ($t = strtotime($ims)) !== false && $t >= $mtime)) {
            http_response_code(304);
            exit;
        }

        [$inicio, $fim, $parcial] = $this->intervalo($tamanho, $etag, $ultimaModificacao);
        $comprimento = $fim - $inicio + 1;

        $arquivo = @fopen($caminho, 'rb');
        if ($arquivo === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Não foi possível ler o arquivo.';
            exit;
        }

        if ($parcial) {
            http_response_code(206);
            header("Content-Range: bytes {$inicio}-{$fim}/{$tamanho}");
        }
        header('Content-Length: ' . $comprimento);

        if ($metodo === 'HEAD') {
            fclose($arquivo);
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @ini_set('zlib.output_compression', '0');
        @set_time_limit(0);
        ignore_user_abort(false);

        fseek($arquivo, $inicio);
        $restante = $comprimento;
        while ($restante > 0 && !feof($arquivo) && !connection_aborted()) {
            $bloco = fread($arquivo, min(self::TAMANHO_BLOCO, $restante));
            if ($bloco === false || $bloco === '') {
                break;
            }
            echo $bloco;
            $restante -= strlen($bloco);
            flush();
        }
        fclose($arquivo);
        exit;
    }

    /**
     * Interpreta o cabeçalho Range (um único intervalo). Retorna [inicio, fim, parcial].
     * Formatos inválidos ou com vários intervalos são ignorados (devolve o arquivo todo, como
     * o RFC 9110 permite); intervalo fora do arquivo responde 416 e encerra.
     */
    private function intervalo(int $tamanho, string $etag, string $ultimaModificacao): array
    {
        $inteiro = [0, $tamanho - 1, false];

        $range = trim($_SERVER['HTTP_RANGE'] ?? '');
        if ($range === '') {
            return $inteiro;
        }

        // If-Range: só vale o Range se o arquivo continua o mesmo.
        $ifRange = trim($_SERVER['HTTP_IF_RANGE'] ?? '');
        if ($ifRange !== '' && $ifRange !== $etag && $ifRange !== $ultimaModificacao) {
            return $inteiro;
        }

        if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) || ($m[1] === '' && $m[2] === '')) {
            return $inteiro;
        }

        if ($m[1] === '') {                       // bytes=-N → últimos N bytes
            $ultimos = (int) $m[2];
            if ($ultimos <= 0) {
                $this->intervaloInvalido($tamanho);
            }
            $inicio = max(0, $tamanho - $ultimos);
            $fim = $tamanho - 1;
        } else {                                  // bytes=N- ou bytes=N-M
            $inicio = (int) $m[1];
            $fim = $m[2] === '' ? $tamanho - 1 : min((int) $m[2], $tamanho - 1);
        }

        if ($inicio >= $tamanho || $inicio > $fim) {
            $this->intervaloInvalido($tamanho);
        }

        return [$inicio, $fim, true];
    }

    private function intervaloInvalido(int $tamanho): never
    {
        http_response_code(416);
        header("Content-Range: bytes */{$tamanho}");
        exit;
    }

    /** If-None-Match pode trazer vários valores, "*" ou validadores fracos (W/"..."). */
    private function etagConfere(string $cabecalho, string $etag): bool
    {
        if ($cabecalho === '*') {
            return true;
        }
        foreach (explode(',', $cabecalho) as $candidato) {
            if (preg_replace('/^W\//', '', trim($candidato)) === $etag) {
                return true;
            }
        }
        return false;
    }

    private function indisponivel(string $metodo): never
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        if ($metodo !== 'HEAD') {
            echo '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<title>Aplicativo indisponível · SICAPDA</title></head>'
                . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
                . 'background:#171717;color:#fff;font-family:sans-serif;text-align:center;padding:1.5rem">'
                . '<main><h1 style="margin:0 0 .6rem">Aplicativo ainda não disponível</h1>'
                . '<p style="margin:0 0 1.4rem;color:#b8b8b8">O instalador do SICAPDA para Android será publicado em breve.</p>'
                . '<a href="/sicapda#baixar" style="display:inline-block;background:#FFC400;color:#171717;'
                . 'font-weight:bold;text-decoration:none;padding:.85rem 1.5rem;border-radius:10px">Voltar à página do SICAPDA</a>'
                . '</main></body></html>';
        }
        exit;
    }
}
