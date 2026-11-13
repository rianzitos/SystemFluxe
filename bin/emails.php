<?php

/**
 * Tarefa agendada dos e-mails automáticos do SICAPDA (EmailsAutomaticos::rotina):
 *   - reenvia mensagens de contato que falharam;
 *   - alerta de desperdício alto (ontem e hoje, uma vez por empresa e por dia);
 *   - a partir das 8h, o resumo diário de quem ligou em Configurações → Notificações.
 *
 * Agende de hora em hora. No cPanel: "Cron Jobs" → "Uma vez por hora" (0 * * * *) e o comando
 *   /usr/local/bin/php /home/USUARIO/SystemFluxe/bin/emails.php >/dev/null 2>&1
 * (troque USUARIO e o caminho pela pasta do projeto). Pode rodar à vontade: nada é enviado duas vezes.
 *
 * Teste manual: php bin/emails.php   (mostra o que foi enviado)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/app.php';

if (!Email::configurado()) {
    fwrite(STDERR, "E-mail não configurado: preencha MAIL_* no config/.env (veja config/.env.example).\n");
    exit(1);
}

// Uma execução por vez (se o servidor de e-mail estiver lento, a próxima hora não atropela esta).
$trava = fopen(sys_get_temp_dir() . '/sicapda-emails-' . md5(__DIR__) . '.lock', 'c');
if ($trava === false || !flock($trava, LOCK_EX | LOCK_NB)) {
    echo "Já existe uma execução em andamento.\n";
    exit(0);
}

$resultado = EmailsAutomaticos::rotina();
printf("[%s] contatos reenviados: %d | alertas de desperdício: %d | resumos diários: %d\n",
    date('Y-m-d H:i:s'), $resultado['contatos'], $resultado['desperdicio'], $resultado['resumos']);
