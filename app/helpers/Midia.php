<?php

/**
 * Entrega os vídeos de public/video/ com suporte a "Range", só para o servidor embutido do PHP.
 *
 * Por que existe: o servidor embutido (php -S, usado no desenvolvimento) não aceita requisições
 * "Range". Sem isso o navegador não consegue pular para um ponto do vídeo da página /sicapda (barra de
 * progresso e capítulos) e o Safari nem chega a tocar o arquivo. Apache e Nginx já aceitam, então em
 * produção esta classe nunca é chamada: ela só é usada pelo bloco "cli-server" de app/routes/web.php.
 */
class Midia
{
    private const TIPOS = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
    private const TAMANHO_BLOCO = 65536;

    /**
     * Entrega o vídeo (GET ou HEAD) e encerra a requisição. Devolve false, sem enviar nada, se o
     * caminho não for um .mp4/.webm dentro de public/video/ — assim um caminho qualquer vindo da URL
     * nunca é lido por aqui.
     */
    public static function servirVideo(string $caminho): bool
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $pasta = realpath(__DIR__ . '/../../public/video');
        $real = realpath($caminho);
        $tipo = $real === false ? null : (self::TIPOS[strtolower(pathinfo($real, PATHINFO_EXTENSION))] ?? null);

        if (
            ($metodo !== 'GET' && $metodo !== 'HEAD') || $pasta === false || $tipo === null
            || !str_starts_with($real, $pasta . DIRECTORY_SEPARATOR)
        ) {
            return false;
        }

        $tamanho = (int) filesize($real);
        $inicio = 0;
        $fim = $tamanho - 1;
        $parcial = false;

        // Um único intervalo (bytes=N-M, bytes=N- ou bytes=-N); qualquer outro formato devolve o arquivo todo.
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $inicio = max(0, $tamanho - (int) $m[2]);
            } else {
                $inicio = (int) $m[1];
                $fim = $m[2] === '' ? $fim : min((int) $m[2], $tamanho - 1);
            }
            if ($inicio >= $tamanho || $inicio > $fim) {
                http_response_code(416);
                header("Content-Range: bytes */{$tamanho}");
                exit;
            }
            $parcial = true;
        }

        // config/app.php abre a sessão em toda requisição: para um arquivo público isso só atrapalha.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header_remove('Set-Cookie');
        header_remove('Pragma');
        header_remove('Expires');

        header('Content-Type: ' . $tipo);
        header('Accept-Ranges: bytes');
        header('Cache-Control: no-cache');
        header('X-Content-Type-Options: nosniff');
        if ($parcial) {
            http_response_code(206);
            header("Content-Range: bytes {$inicio}-{$fim}/{$tamanho}");
        }
        header('Content-Length: ' . ($fim - $inicio + 1));

        if ($metodo === 'HEAD') {
            exit;
        }

        $arquivo = fopen($real, 'rb');
        fseek($arquivo, $inicio);
        $restante = $fim - $inicio + 1;
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
}
