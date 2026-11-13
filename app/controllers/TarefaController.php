<?php

/**
 * GET /tarefas/emails?chave=CRON_SECRET — a mesma tarefa de bin/emails.php, chamada por URL, para
 * hospedagens em que o cron só consegue abrir um endereço (ou serviços como cron-job.org).
 * Sem CRON_SECRET (mínimo de 32 caracteres) no config/.env a rota fica desligada (404).
 */
class TarefaController
{
    public function emails(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $segredo = (string) Env::get('CRON_SECRET', '');
        $chave = (string) ($_GET['chave'] ?? '');
        if (strlen($segredo) < 32 || !hash_equals($segredo, $chave)) {
            http_response_code(404);
            echo json_encode(['sucesso' => false]);
            exit;
        }
        if (!Email::configurado()) {
            http_response_code(503);
            echo json_encode(['sucesso' => false, 'mensagem' => 'E-mail não configurado no servidor.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        ignore_user_abort(true);
        set_time_limit(300);
        echo json_encode(['sucesso' => true] + EmailsAutomaticos::rotina());
        exit;
    }
}
