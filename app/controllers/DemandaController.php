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
            'notificacoesNaoLidas' => Notificacoes::contar(count($alertas), $empresaId),
            'perguntasRapidas' => Assistente::perguntasRapidas(),
            'ia'         => AssistenteIA::ativa(),
        ];

        require __DIR__ . '/../views/assistente.php';
    }

    /**
     * POST /assistente/perguntar — recebe {mensagem, historico?} em JSON e responde em JSON.
     * historico: mensagens anteriores do chat ([{autor: 'usuario'|'bot', texto}]), para a IA entender
     * perguntas como "e para sexta?". Com a IA desligada ou fora do ar, responde o motor local.
     */
    public function perguntar(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!AuthMiddleware::sessaoAtiva()) {
            http_response_code(401);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada. Faça login novamente.']);
            return;
        }

        CsrfMiddleware::validar();

        $corpo = json_decode((string) file_get_contents('php://input'), true);
        $mensagem = is_array($corpo) && is_string($corpo['mensagem'] ?? null) ? $corpo['mensagem'] : '';
        $historico = is_array($corpo) && is_array($corpo['historico'] ?? null) ? $corpo['historico'] : [];

        // Libera a sessão antes de esperar a IA (alguns segundos): assim as outras abas do sistema e o sino
        // não ficam travados esperando o fim desta requisição.
        $usuario = [
            'id'     => (int) ($_SESSION['usuario_id'] ?? 0),
            'nome'   => (string) ($_SESSION['usuario_nome'] ?? ''),
            'perfil' => (string) ($_SESSION['usuario_perfil'] ?? ''),
        ];
        $empresaId = Pagina::empresaId();
        session_write_close();
        @set_time_limit(120);

        try {
            $r = AssistenteIA::responderOuLocal($empresaId, $usuario, $mensagem, $historico);
        } catch (Throwable $e) {
            error_log('SICAPDA assistente: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Não consegui calcular isso agora. Tente novamente em instantes.']);
            return;
        }

        echo json_encode(['sucesso' => true, 'resposta' => $r['resposta'], 'ia' => $r['ia'], 'hora' => date('H:i')], JSON_UNESCAPED_UNICODE);
    }
}
