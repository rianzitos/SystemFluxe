<?php

/**
 * Proteção contra CSRF (requisições forjadas a partir de outro site).
 *
 * Uso:
 *   - em formulários HTML:  <?= CsrfMiddleware::campo() ?>
 *   - em fetch():           header 'X-CSRF-Token' com CsrfMiddleware::token()
 *   - no controller (POST): CsrfMiddleware::validar();
 */
class CsrfMiddleware
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /** Campo <input hidden> pronto para colar dentro de um <form>. */
    public static function campo(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    /** true se o token enviado (campo do form ou header) confere com o da sessão. */
    public static function valido(): bool
    {
        $esperado = $_SESSION['csrf_token'] ?? '';
        $enviado  = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

        return $esperado !== '' && is_string($enviado) && hash_equals($esperado, $enviado);
    }

    /** Encerra com 403 (JSON) se o token for inválido. */
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
