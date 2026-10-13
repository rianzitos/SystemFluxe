<?php

/**
 * Pedaços comuns dos controllers que respondem JSON para as telas internas (Configurações, Pessoas...):
 * conferir método, sessão e CSRF, exigir administrador e responder no formato padrão
 * {sucesso, mensagem, ...}. Reunidos aqui para que as regras de segurança fiquem em um lugar só.
 */
trait RespondeJson
{
    /** Valida POST + sessão ativa + CSRF e devolve o ID do usuário logado. */
    private function exigirSessao(): int
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->responder(false, 'Método não permitido.', 405);
        }

        return $this->exigirLogin(true);
    }

    /** Para consultas GET que devolvem JSON: só sessão ativa (não há o que forjar sem efeito colateral). */
    private function exigirSessaoGet(): int
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            $this->responder(false, 'Método não permitido.', 405);
        }

        return $this->exigirLogin(false);
    }

    private function exigirLogin(bool $comCsrf): int
    {
        if (!AuthMiddleware::sessaoAtiva()) {
            $this->responder(false, 'Sessão expirada. Faça login novamente.', 401);
        }
        if ($comCsrf) {
            CsrfMiddleware::validar();
        }

        return (int) $_SESSION['usuario_id'];
    }

    /** Só o administrador da empresa gerencia $oQue. Devolve o ID da empresa. */
    private function exigirAdmin(string $oQue): int
    {
        $empresaId = Pagina::empresaId();
        if (($_SESSION['usuario_perfil'] ?? '') !== 'admin' || $empresaId <= 0) {
            $this->responder(false, 'Somente o administrador da empresa pode gerenciar ' . $oQue . '.', 403);
        }
        return $empresaId;
    }

    private function responder(bool $sucesso, string $mensagem, int $status = 200, array $extra = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');   // algumas respostas carregam dados sensíveis (token de dispositivo, UIDs)
        echo json_encode(['sucesso' => $sucesso, 'mensagem' => $mensagem] + $extra);
        exit;
    }
}
