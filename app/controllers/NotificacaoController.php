<?php

/** GET /notificacoes — alertas que o sino do cabeçalho mostra (mesma regra do painel e das preferências do usuário). */
class NotificacaoController
{
    use RespondeJson;

    public function listar(): void
    {
        $this->exigirSessaoGet();

        try {
            $alertas = Notificacoes::listar(Pagina::empresaId());
        } catch (Throwable $e) {
            error_log('SICAPDA notificações: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível carregar os alertas agora.', 500);
        }

        $this->responder(true, 'ok', 200, ['total' => count($alertas), 'alertas' => $alertas]);
    }
}
