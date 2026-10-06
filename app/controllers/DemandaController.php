<?php

class DemandaController
{
    /** GET /assistente — tela do assistente virtual. */
    public function exibir(): void
    {
        AuthMiddleware::autenticado();

        $empresaId = Pagina::empresaId();
        $previsao  = new Previsao($empresaId);
        $assistente = new Assistente($empresaId);

        $precisao = $previsao->precisao();
        $alertas  = $previsao->alertas();

        $dados = [
            'boasVindas' => $assistente->boasVindas(),
            'precisao'   => $precisao,
            'eventos'    => $previsao->eventos(3),
            'notificacoesNaoLidas' => count($alertas),
            'perguntasRapidas' => $this->perguntasRapidas(),
        ];

        require __DIR__ . '/../views/assistente.php';
    }

    /** POST /assistente/perguntar — recebe {mensagem} em JSON e responde em JSON. */
    public function perguntar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (empty($_SESSION['usuario_id'])) {
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada. Faça login novamente.']);
            return;
        }

        CsrfMiddleware::validar();

        $corpo = json_decode((string) file_get_contents('php://input'), true);
        $mensagem = is_array($corpo) && is_string($corpo['mensagem'] ?? null) ? $corpo['mensagem'] : '';

        try {
            $resposta = (new Assistente(Pagina::empresaId()))->responder($mensagem);
        } catch (Throwable $e) {
            error_log('SICAPDA assistente: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Não consegui calcular isso agora. Tente novamente em instantes.']);
            return;
        }

        echo json_encode(['sucesso' => true, 'resposta' => $resposta, 'hora' => date('H:i')], JSON_UNESCAPED_UNICODE);
    }

    /** Textos dos botões "Perguntas Rápidas" (o "sexta" vira o próximo dia útil se hoje for sexta). */
    private function perguntasRapidas(): array
    {
        return ['Previsão para amanhã', 'Previsão para sexta', 'Previsão semanal'];
    }
}
