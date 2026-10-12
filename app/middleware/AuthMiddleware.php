<?php

class AuthMiddleware {
    /**
     * Sessão aberta e que não foi encerrada em outro lugar (Configurações → Sessões ativas).
     * Se foi encerrada, limpa a sessão do PHP e devolve false.
     */
    public static function sessaoAtiva(): bool {
        if (empty($_SESSION['usuario_id'])) {
            return false;
        }
        if (!Sessao::valida()) {
            session_unset();
            session_destroy();
            return false;
        }
        return true;
    }

    /**
     * Garante que o usuário está autenticado.
     * Redireciona para /login caso não esteja.
     */
    public static function autenticado(): void {
        if (!self::sessaoAtiva()) {
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
