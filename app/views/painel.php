<?php
// Bootstrap próprio: garante que esta página seja protegida mesmo que
// alguém tente acessá-la diretamente pela URL, sem passar pelo roteador
// em app/routes/web.php (ex: fluxeteam.com.br/app/views/painel.php).
require_once __DIR__ . '/../../config/app.php';

// Bloqueia o acesso e redireciona para /login se não houver sessão ativa.
AuthMiddleware::autenticado();

// -----------------------------------------------------------------------
// DADOS DO PAINEL — vêm do banco (model Painel), filtrados pela empresa do
// usuário logado. Veja app/models/Painel.php e app/models/Previsao.php.
// -----------------------------------------------------------------------

$paginaAtual = 'painel';

$painel = new Painel(Pagina::empresaId());
$dadosPainel = $painel->painel();

$cardsResumo = $dadosPainel['cardsResumo'];
$graficoDemanda = $dadosPainel['graficoDemanda'];
$graficoDistribuicao = $dadosPainel['graficoDistribuicao'];
$previsaoProximasHoras = $dadosPainel['previsaoProximasHoras'];
$alertas = $dadosPainel['alertas'];
$notificacoesNaoLidas = count($alertas);

// Itens do menu lateral: rota, ícone (chave) e rótulo.
// Trocar o "href" pelas rotas reais do seu app/routes/web.php.
$menu = Pagina::menu();

// Badge do sino: quantidade de alertas ativos (calculados com dados reais)
$notificacoesNaoLidas = $notificacoesNaoLidas ?? 0;

$nomeUsuario = $_SESSION['usuario_nome'] ?? 'Usuário';
$perfilUsuario = $_SESSION['usuario_perfil'] ?? '—';
$fotoPerfil = $_SESSION['usuario_foto'] ?? null;

// Função pequena só pra deixar o HTML dos ícones mais limpo lá embaixo.
function icone(string $nome): string
{
    $icones = [
        'grid' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
        'link' => '<path d="M9 12h6M8 7h1a4 4 0 0 1 0 8H8M16 17h-1a4 4 0 0 1 0-8h1"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6M16 8a3 3 0 1 1 0-6M22 20c0-2.7-2-5-5-5.5"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M9 12l2 2 4-4"/>',
        'meal' => '<path d="M6 2v8a2 2 0 0 0 4 0V2M8 10v12M17 2c-1.7 0-3 2.2-3 5s1.3 5 3 5v10"/>',
        'file' => '<path d="M7 2h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v5h5"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'bell' => '<path d="M6 8a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 12 6 8Z"/><path d="M10 18a2 2 0 0 0 4 0"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'trend-up' => '<path d="M4 15l5-5 4 4 7-7"/><path d="M15 7h5v5"/>',
        'trend-down' => '<path d="M4 9l5 5 4-4 7 7"/><path d="M15 17h5v-5"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'alert' => '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/>',
        'pulse' => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
        'card-users' => '<circle cx="8" cy="9" r="2.5"/><path d="M3 19c0-2.5 2-4.5 5-4.5s5 2 5 4.5M14 9h7M14 13h5"/>',
        'card-access' => '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="8" cy="12" r="2"/><path d="M13 10h5M13 14h3"/>',
    ];
    return $icones[$nome] ?? '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel · SICAPDA</title>

    <!-- Aplica o tema salvo (claro/escuro/sistema) antes do CSS carregar, pra evitar
         o "flash" da tela clara antes de escurecer. Esse mesmo bloco precisa estar
         em toda página que usa painel.css (acessos.php, pessoas.php, relatorios.php
         etc.) — é o que faz a preferência escolhida em Configurações valer aqui também. -->
    <script>
        (function () {
            var tema = localStorage.getItem('sicapda_tema') || 'claro';
            if (tema === 'sistema') {
                tema = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
            }
            if (tema === 'escuro') {
                document.documentElement.setAttribute('data-tema', 'escuro');
            }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="/css/painel.css">
    <link rel="shortcut icon" href="/img/logo_fluxe.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
</head>

<body>

    <div class="layout">

        <!-- ===================== SIDEBAR ===================== -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-topo">
                <div class="logo">
                    <span class="logo-sica">SICA<span class="logo-pda">PDA</span></span>
                    <span class="logo-by">by <strong>FLUXE</strong></span>
                </div>

                <nav class="menu">
                    <?php foreach ($menu as $item): ?>
                        <a href="<?= htmlspecialchars($item['rota']) ?>"
                            class="menu-item <?= $paginaAtual === $item['chave'] ? 'ativo' : '' ?>">
                            <div class="menu-icone menu-icone-<?= htmlspecialchars($item['chave']) ?>">
                                <i class="iconeMenu bi <?= htmlspecialchars($item['icone']) ?>"></i>
                            </div>
                            <span><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="sidebar-usuario">
                <?php if (!empty($fotoPerfil) && file_exists(__DIR__ . '/../../public/' . $fotoPerfil)): ?>
                    <div class="avatar avatar-foto">
                        <img src="/<?= htmlspecialchars($fotoPerfil) ?>"
                            alt="Foto de <?= htmlspecialchars($nomeUsuario) ?>">
                    </div>
                <?php else: ?>
                    <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($nomeUsuario, 0, 1))) ?></div>
                <?php endif; ?>
                <div class="sidebar-usuario-info">
                    <strong><?= htmlspecialchars($nomeUsuario) ?></strong>
                    <span><?= htmlspecialchars($perfilUsuario) ?> <i class="ponto-online"></i></span>
                </div>
                <a href="/logout" class="sair" title="Sair">
                    <i class="icone bi bi-box-arrow-right"></i>
                </a>
            </div>
        </aside>

        <!-- ===================== CONTEÚDO ===================== -->
        <div class="conteudo">

            <button class="btn-menu-mobile" id="btnMenuMobile" aria-label="Abrir menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <!-- Cabeçalho -->
            <header class="cabecalho">
                <div>
                    <h1>Painel Geral</h1>
                    <p>Visão geral do sistema</p>
                </div>

                <div class="cabecalho-acoes">
                    <div class="seletor-data">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><?= icone('calendar') ?></svg>
                        <span id="labelPeriodo">Carregando período…</span>
                    </div>

                    <button class="sino" id="btnNotificacoes" aria-label="Notificações">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><?= icone('bell') ?></svg>
                        <?php if ($notificacoesNaoLidas > 0): ?>
                            <span class="badge-sino"><?= (int) $notificacoesNaoLidas ?></span>
                        <?php endif; ?>
                    </button>
                </div>
            </header>

            <!-- Cards de resumo -->
            <section class="cards-resumo">
                <?php foreach ($cardsResumo as $card): ?>
                    <div class="card">
                        <div class="card-icone card-icone-<?= htmlspecialchars($card['chave']) ?>">
                            <i class="iconeCard bi <?= htmlspecialchars($card['icone']) ?>"></i>
                        </div>
                        <p class="card-label"><?= htmlspecialchars($card['label']) ?></p>
                        <p class="card-valor">
                            <?= $card['valor'] === null ? '—' : number_format($card['valor'], 0, ',', '.') . htmlspecialchars($card['sufixo'] ?? '') ?>
                        </p>
                        <?php if ($card['variacao'] !== null): ?>
                            <?php $sobe = $card['variacao'] >= 0; ?>
                            <p class="card-variacao <?= $sobe ? 'positiva' : 'negativa' ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"><?= icone($sobe ? 'trend-up' : 'trend-down') ?></svg>
                                <?= ($sobe ? '+' : '') . number_format($card['variacao'], 1, ',', '.') ?>%
                                <span><?= htmlspecialchars($card['periodo']) ?></span>
                            </p>
                        <?php else: ?>
                            <p class="card-variacao"><span>Sem base de comparação</span></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Gráficos -->
            <section class="graficos">
                <div class="painel-grafico grafico-linha">
                    <h2>Previsão de Demanda</h2>
                    <div class="legenda-grafico">
                        <span><i class="ponto ponto-previsto"></i> Previsto (em KG)</span>
                        <span><i class="ponto ponto-realizado"></i> Realizado (em KG)</span>
                    </div>
                    <div class="grafico-canvas">
                        <canvas id="graficoDemanda"></canvas>
                    </div>
                </div>

                <div class="painel-grafico grafico-rosca">
                    <h2>Distribuição de Refeições</h2>
                    <div class="grafico-canvas grafico-canvas-rosca">
                        <canvas id="graficoDistribuicao"></canvas>
                    </div>
                    <ul class="legenda-rosca" id="legendaDistribuicao"></ul>
                </div>
            </section>

            <!-- Previsão próximas horas + Alertas -->
            <section class="painel-inferior">
                <div class="painel-card">
                    <h2>Previsão · Próximas Horas</h2>
                    <?php if (!$previsaoProximasHoras): ?>
                        <p class="sem-dados">Sem movimento previsto para as próximas horas (fora do expediente ou sem histórico suficiente).</p>
                    <?php endif; ?>
                    <ul class="lista-previsao">
                        <?php foreach ($previsaoProximasHoras as $item): ?>
                            <li>
                                <span class="hora"><?= htmlspecialchars($item['hora']) ?></span>
                                <span class="status status-<?= htmlspecialchars($item['status']) ?>">
                                    <?= ucfirst(htmlspecialchars($item['status'])) ?>
                                </span>
                                <span class="pessoas"><?= (int) $item['pessoas'] ?> pessoas</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="painel-card">
                    <h2>Alertas e Recomendações</h2>
                    <div class="lista-alertas">
                        <?php if (!$alertas): ?>
                            <p class="sem-dados">Nenhum alerta no momento. Tudo dentro do esperado.</p>
                        <?php endif; ?>
                        <?php foreach ($alertas as $alerta): ?>
                            <div class="alerta alerta-<?= htmlspecialchars($alerta['tipo']) ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round">
                                    <?= icone($alerta['tipo'] === 'aviso' ? 'alert' : ($alerta['tipo'] === 'info' ? 'pulse' : 'trend-up')) ?>
                                </svg>
                                <div>
                                    <strong><?= htmlspecialchars($alerta['titulo']) ?></strong>
                                    <p><?= htmlspecialchars($alerta['descricao']) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

        </div>
    </div>

    <!-- Dados do PHP -> JS (o painel.js usa isso pra montar os gráficos) -->
    <script>
        window.DADOS_PAINEL = {
            demanda: <?= json_encode($graficoDemanda, JSON_UNESCAPED_UNICODE) ?>,
            distribuicao: <?= json_encode($graficoDistribuicao, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="/js/chart.umd.min.js"></script>
    <script src="/js/painel.js"></script>
</body>

</html>