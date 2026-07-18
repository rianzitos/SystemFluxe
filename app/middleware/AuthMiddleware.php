<!-- AuthMiddleware.php -->

<?php

class AuthMiddleware {
    /**
     * Garante que o usuário está autenticado.
     * Redireciona para /login caso não esteja.
     */
    public static function autenticado(): void {
        if (empty($_SESSION['usuario_id'])) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Garante que o usuário tem o perfil exigido.
     * Ex.: AuthMiddleware::requerPerfil('admin');
     */
    public static function requerPerfil(string $perfil): void {
        self::autenticado();

        if (($_SESSION['usuario_perfil'] ?? '') !== $perfil) {
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }
    }
}