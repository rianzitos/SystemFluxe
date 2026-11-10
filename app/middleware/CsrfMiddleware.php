<?php

class CsrfMiddleware
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function campo(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function valido(): bool
    {
        $esperado = $_SESSION['csrf_token'] ?? '';
        $enviado  = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        return $esperado !== '' && is_string($enviado) && hash_equals($esperado, $enviado);
    }

    public static function validar(): void
    {
        if (!self::valido()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada. Recarregue a página e tente novamente.']);
            exit;
        }
    }
}