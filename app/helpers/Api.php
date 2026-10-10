<?php

/**
 * Respostas JSON padronizadas da API do aplicativo.
 * Sucesso:  { "sucesso": true, ...dados }
 * Erro:     { "sucesso": false, "mensagem": "..." } com o código HTTP adequado.
 */
class Api
{
    public static function json(array $dados, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['sucesso' => true] + $dados, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    public static function erro(int $status, string $mensagem): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['sucesso' => false, 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Corpo da requisição JSON como array (vazio se inválido). */
    public static function corpo(): array
    {
        $corpo = json_decode((string) file_get_contents('php://input'), true);
        return is_array($corpo) ? $corpo : [];
    }

    /** URL absoluta de um arquivo de public/ (ex.: foto de perfil). */
    public static function urlPublica(?string $caminho): ?string
    {
        if ($caminho === null || $caminho === '') {
            return null;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . ltrim($caminho, '/');
    }
}
