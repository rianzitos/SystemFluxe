<?php
// Fixture exclusiva do teste local da verificação em duas etapas (tests/dois_fatores_http.py); nunca publicar em public/.
require __DIR__ . '/../config/app.php';
$db = Database::connect();
$acao = $argv[1] ?? '';

if ($acao === 'usuario') {
    $q = $db->prepare(
        'SELECT id, dois_fatores_habilitado, dois_fatores_metodo, dois_fatores_segredo, dois_fatores_ativado_em,
                dois_fatores_ultimo_passo FROM usuarios WHERE email = ?'
    );
    $q->execute([$argv[2]]);
    echo json_encode($q->fetch() ?: null);
    exit;
}
if ($acao === 'recuperacao') {
    // Quantos códigos de recuperação a conta tem e quantos já foram usados; e se algum foi guardado sem hash.
    $q = $db->prepare('SELECT COUNT(*) AS total, SUM(usado_em IS NOT NULL) AS usados, MAX(LENGTH(codigo_hash)) AS tamanho
                       FROM dois_fatores_recuperacao WHERE usuario_id = ?');
    $q->execute([(int) $argv[2]]);
    echo json_encode($q->fetch());
    exit;
}
if ($acao === 'desafios') {
    $q = $db->prepare('SELECT finalidade, metodo, tentativas, envios, usado_em IS NOT NULL AS usado, LENGTH(token_hash) AS tamanho
                       FROM dois_fatores_desafios WHERE usuario_id = ? ORDER BY id');
    $q->execute([(int) $argv[2]]);
    echo json_encode($q->fetchAll());
    exit;
}
if ($acao === 'limpar-desafios') {
    // Zera o histórico de tentativas (o bloqueio de 15 minutos) entre as partes do teste.
    $db->prepare('DELETE FROM dois_fatores_desafios WHERE usuario_id = ?')->execute([(int) $argv[2]]);
    exit;
}
if ($acao === 'atrasar-envio') {
    // Simula que o último código foi enviado há 2 minutos (libera o "Reenviar código" sem esperar).
    $db->prepare('UPDATE dois_fatores_desafios SET ultimo_envio = ?, criado_em = ? WHERE usuario_id = ?')
        ->execute([date('Y-m-d H:i:s', time() - 120), date('Y-m-d H:i:s', time() - 120), (int) $argv[2]]);
    exit;
}
fwrite(STDERR, "ação desconhecida\n");
exit(1);
