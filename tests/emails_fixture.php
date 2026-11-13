<?php
// Fixture exclusiva do teste local dos e-mails (tests/emails_http.py); nunca publicar em public/.
// Usa a mesma empresa temporária do balanca_fixture.php e acrescenta o que o teste de e-mails precisa.
require __DIR__ . '/../config/app.php';
$db = Database::connect();
$acao = $argv[1] ?? '';

if ($acao === 'historico') {
    // 10 dias anteriores com ~10 kg de desperdício por dia (só balança, sem produção informada)
    [$empresa, $kg] = [(int) $argv[2], (float) ($argv[3] ?? 10)];
    for ($i = 1; $i <= 10; $i++) {
        (new Producao())->registrarDesperdicio($empresa, 'almoco', $kg + ($i % 3) * 0.5, date('Y-m-d', strtotime("-{$i} days")));
    }
    exit;
}
if ($acao === 'pref') {
    (new Preferencia())->salvarNotificacao((int) $argv[2], $argv[3], $argv[4] === '1');
    exit;
}
if ($acao === 'contatos') {
    $q = $db->prepare('SELECT id, origem, nome, email, telefone, enviado_em, tentativas FROM contatos WHERE email = ? ORDER BY id');
    $q->execute([$argv[2]]);
    echo json_encode($q->fetchAll());
    exit;
}
if ($acao === 'enviados') {
    $q = $db->prepare('SELECT tipo, referencia, destinatario FROM emails_enviados WHERE empresa_id = ? ORDER BY id');
    $q->execute([(int) $argv[2]]);
    echo json_encode($q->fetchAll());
    exit;
}
if ($acao === 'limpar-contatos') {
    $db->prepare('DELETE FROM contatos WHERE email LIKE ? OR ip = ?')->execute([$argv[2], $argv[3] ?? '']);
    exit;
}
if ($acao === 'limpar-empresa') {
    // usuarios → senha_redefinicoes/emails_enviados/preferencias caem em cascata; empresa → produção/dispositivos
    $db->prepare('DELETE FROM usuarios WHERE empresa_id = ?')->execute([(int) $argv[2]]);
    $db->prepare('DELETE FROM empresas WHERE id = ?')->execute([(int) $argv[2]]);
    exit;
}
if ($acao === 'empresa-por-cnpj') {
    $q = $db->prepare('SELECT id FROM empresas WHERE cnpj = ?');
    $q->execute([$argv[2]]);
    echo (int) $q->fetchColumn();
    exit;
}
fwrite(STDERR, "ação desconhecida\n");
exit(1);
