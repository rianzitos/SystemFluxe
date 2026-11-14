<?php
// Renderizada por DemandaController::diagnosticar() (rota /assistente/diagnostico, só administrador).
if (!isset($config)) {
    header('Location: /assistente');
    exit;
}
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$cor = ['ok' => '#15803d', 'aviso' => '#b45309', 'erro' => '#b91c1c'];
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Diagnóstico da IA · SICAPDA</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; padding: 24px 16px; background: #f4f5f8; color: #111; }
        main { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 1.4rem; margin: 0 0 4px; }
        h2 { font-size: 1.05rem; margin: 28px 0 10px; }
        p, li { line-height: 1.5; }
        .card { background: #fff; border-radius: 12px; padding: 4px 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .linha { display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid #eee; }
        .linha:last-child { border-bottom: 0; }
        .rotulo { flex: 0 0 38%; color: #555; }
        .valor { flex: 1; word-break: break-word; font-weight: 600; }
        .etapa { padding: 12px 0; border-bottom: 1px solid #eee; }
        .etapa:last-child { border-bottom: 0; }
        .etapa pre { white-space: pre-wrap; background: #f6f6f8; border-radius: 8px; padding: 10px; margin: 8px 0 0; font: inherit; }
        .botao { display: inline-block; background: #ffc107; color: #111; font-weight: 700; padding: 10px 18px; border-radius: 10px; text-decoration: none; }
        .sec { color: #555; margin-left: 12px; }
    </style>
</head>

<body>
    <main>
        <h1>Diagnóstico da IA do assistente</h1>
        <p>Página só para administradores. Mostra como a IA está configurada neste servidor e onde ela falha.</p>

        <h2>Configuração lida do servidor</h2>
        <div class="card">
            <?php foreach ($config as [$rotulo, $valor, $estado]): ?>
                <div class="linha">
                    <div class="rotulo"><?= $e($rotulo) ?></div>
                    <div class="valor" style="color: <?= $cor[$estado] ?>"><?= $e($valor) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <h2>Teste</h2>
        <?php if ($etapas === null): ?>
            <p>O teste faz de 3 a 5 chamadas reais à IA (usa um pouco da cota gratuita) e leva alguns segundos.</p>
            <p><a class="botao" href="/assistente/diagnostico?testar=1">Testar a IA agora</a><a class="sec" href="/assistente">Voltar ao assistente</a></p>
        <?php else: ?>
            <div class="card">
                <?php foreach ($etapas as $et): ?>
                    <div class="etapa">
                        <strong style="color: <?= $et['ok'] ? $cor['ok'] : $cor['erro'] ?>"><?= $et['ok'] ? '✔ Passou' : '✖ Falhou' ?></strong>
                        · <?= $e($et['titulo']) ?> <span style="color:#777">(<?= (int) $et['ms'] ?> ms)</span>
                        <pre><?= $e($et['detalhe']) ?></pre>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php $falhou = array_filter($etapas, fn($x) => !$x['ok']); ?>
            <p><?= $falhou
                ? 'O teste parou na primeira etapa que falhou. Siga o que está escrito nela. Se não resolver, copie esta tela e envie.'
                : 'Tudo certo: a IA está funcionando. Confira se a etapa 4 recusou a pergunta fora do assunto.' ?></p>
            <p><a class="botao" href="/assistente/diagnostico?testar=1">Testar de novo</a><a class="sec" href="/assistente">Voltar ao assistente</a></p>
        <?php endif; ?>
    </main>
</body>

</html>
