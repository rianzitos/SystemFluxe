<?php
// Esta view é renderizada pelo DemandaController (rota /assistente).
if (!isset($dados)) {
    header('Location: /assistente');
    exit;
}

$paginaAtual = 'previsao';
$notificacoesNaoLidas = $dados['notificacoesNaoLidas'];
$precisao = $dados['precisao'];
$tipoIcone = ['aviso' => 'bi-exclamation-circle-fill', 'info' => 'bi-info-circle-fill', 'sucesso' => 'bi-check-circle-fill'];
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistente Virtual · SICAPDA</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(CsrfMiddleware::token()) ?>">

<?php require __DIR__ . '/partials/tema.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="/css/painel.css">
    <link rel="stylesheet" href="/css/assistente.css">
    <link rel="shortcut icon" href="/img/logo_fluxe.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
</head>

<body>
    <div class="layout">

<?php require __DIR__ . '/partials/sidebar.php'; ?>

        <div class="conteudo">

            <button class="btn-menu-mobile" id="btnMenuMobile" aria-label="Abrir menu">
                <?= Pagina::svg('menu') ?>
            </button>

            <header class="cabecalho">
                <div>
                    <h1>Assistente Virtual</h1>
                    <p>Tire suas dúvidas, obtenha insights e previsões em tempo real.</p>
                </div>

                <div class="cabecalho-acoes">
                    <div class="seletor-data">
                        <?= Pagina::svg('calendar') ?>
                        <span><?= htmlspecialchars(Datas::porExtenso(new DateTimeImmutable('today'))) ?></span>
                    </div>
                    <a class="sino" href="/assistente" id="btnNotificacoes" aria-label="Alertas">
                        <?= Pagina::svg('bell') ?>
                        <?php if ($notificacoesNaoLidas > 0): ?>
                            <span class="badge-sino"><?= (int) $notificacoesNaoLidas ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </header>

            <div class="assistente-grid">

                <!-- ============ CHAT ============ -->
                <section class="chat-card" aria-label="Conversa com o assistente">
                    <div class="chat-topo">
                        <div class="chat-avatar"><i class="bi bi-robot"></i></div>
                        <div>
                            <h2>Assistente Inteligente</h2>
                            <p>Previsões e insights em tempo real</p>
                        </div>
                    </div>

                    <div class="chat-mensagens" id="chatMensagens" role="log" aria-live="polite">
                        <div class="msg msg-bot">
                            <div class="chat-avatar"><i class="bi bi-robot"></i></div>
                            <div class="msg-balao">
                                <p><?= htmlspecialchars($dados['boasVindas']) ?></p>
                                <time id="horaBoasVindas"></time>
                            </div>
                        </div>
                    </div>

                    <form class="chat-form" id="chatForm" autocomplete="off">
                        <label class="chat-campo">
                            <i class="bi bi-stars"></i>
                            <input type="text" id="chatEntrada" maxlength="300"
                                placeholder="Pergunte sobre previsões, tendências ou recomendações…"
                                aria-label="Mensagem para o assistente">
                        </label>
                        <button type="submit" class="chat-enviar" id="chatEnviar" aria-label="Enviar">
                            <?= Pagina::svg('send') ?>
                        </button>
                    </form>
                </section>

                <!-- ============ LATERAL ============ -->
                <aside class="assistente-lateral">

                    <section class="painel-card">
                        <h2><i class="bi bi-lightning-charge-fill"></i> Perguntas Rápidas</h2>
                        <div class="lista-rapidas">
                            <?php foreach ($dados['perguntasRapidas'] as $pergunta): ?>
                                <button type="button" class="pergunta-rapida" data-pergunta="<?= htmlspecialchars($pergunta) ?>">
                                    <span><?= htmlspecialchars($pergunta) ?></span>
                                    <?= Pagina::svg('chevron-r') ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="painel-card">
                        <h2><i class="bi bi-calendar-event"></i> Eventos e Alertas</h2>
                        <?php if (!$dados['eventos']): ?>
                            <p class="sem-eventos">Nenhum feriado ou dia de baixa previsão nos próximos 10 dias.</p>
                        <?php else: ?>
                            <div class="lista-eventos">
                                <?php foreach ($dados['eventos'] as $ev): ?>
                                    <button type="button" class="evento evento-<?= htmlspecialchars($ev['tipo']) ?> pergunta-rapida"
                                        data-pergunta="Previsão para <?= htmlspecialchars($ev['data']) ?>">
                                        <i class="bi <?= $tipoIcone[$ev['tipo']] ?? 'bi-info-circle-fill' ?>"></i>
                                        <span class="evento-texto">
                                            <strong><?= htmlspecialchars($ev['data']) ?></strong>
                                            <small><?= htmlspecialchars($ev['rotulo'] ?? $ev['titulo']) ?></small>
                                        </span>
                                        <?= Pagina::svg('chevron-r') ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="card-precisao">
                        <p class="precisao-rotulo"><i class="bi bi-bullseye"></i> Precisão da IA</p>
                        <?php if ($precisao): ?>
                            <p class="precisao-valor"><?= number_format($precisao['precisao'], 1, ',', '.') ?>%</p>
                            <p class="precisao-nota">Baseado nos últimos 30 dias (<?= (int) $precisao['dias'] ?> dias avaliados)</p>
                        <?php else: ?>
                            <p class="precisao-valor">—</p>
                            <p class="precisao-nota">Dados insuficientes: são necessários ao menos 5 dias com movimento.</p>
                        <?php endif; ?>
                    </section>

                </aside>
            </div>
        </div>
    </div>

    <script src="/js/assistente.js"></script>
</body>

</html>
