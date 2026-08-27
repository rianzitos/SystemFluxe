<?php
// Bootstrap próprio: garante que esta página seja protegida mesmo que
// alguém tente acessá-la diretamente pela URL, sem passar pelo roteador
// em app/routes/web.php (ex: fluxeteam.com.br/app/views/acessos.php).
require_once __DIR__ . '/../../config/app.php';

// Bloqueia o acesso e redireciona para /login se não houver sessão ativa.
AuthMiddleware::autenticado();

// -----------------------------------------------------------------------
// DADOS DA PÁGINA DE ACESSOS
// -----------------------------------------------------------------------
// Os dados abaixo são estáticos (mock), no mesmo espírito do painel.php,
// só para a tela ter o que exibir enquanto o AcessoController/Acesso não
// tem as consultas reais no banco. Quando o back-end estiver pronto, é só
// substituir cada bloco pelo retorno do Model (Acesso::buscarResumoMensal(),
// por exemplo) sem precisar mexer no HTML abaixo — os arrays já têm o
// formato certo.
// -----------------------------------------------------------------------

$paginaAtual = 'acessos';

// Cards de resumo do topo (3 cards, iguais aos da referência de "Análise Mensal":
// Total Produzido / Total Desperdiçado / Média de Pessoas)
$cardsResumo = [
    [
        'chave'    => 'produzido',
        'label'    => 'Total Produzido',
        'valor'    => 1602,
        'sufixo'   => ' kg',
        'variacao' => 12.5,
        'positiva' => true,
        'periodo'  => 'este mês',
        'extra'    => 'Média: 53.4 kg/dia',
        'icone'    => 'bi-graph-up-arrow',
    ],
    [
        'chave'    => 'desperdicado',
        'label'    => 'Total Desperdiçado',
        'valor'    => 193,
        'sufixo'   => ' kg',
        'variacao' => 8.1,
        'positiva' => false,
        'periodo'  => 'este mês',
        'extra'    => '12,0% do total',
        'icone'    => 'bi-graph-down-arrow',
    ],
    [
        'chave'    => 'pessoas',
        'label'    => 'Média de Pessoas',
        'valor'    => 198,
        'sufixo'   => '',
        'variacao' => 6.3,
        'positiva' => true,
        'periodo'  => 'este mês',
        'extra'    => 'Por dia útil',
        'icone'    => 'bi-people-fill',
    ],
];

// Gráfico de área: Número de Pessoas por Dia (linha 1-30)
$graficoPessoasDia = [
    'labels' => range(1, 30),
    'valores' => [165, 148, 172, 210, 218, 138, 152, 158, 163, 246, 199, 190, 187, 195, 168, 196, 172, 190, 141, 178, 209, 196, 188, 231, 199, 195, 199, 232, 168, 200],
];

// Gráfico de barras: Produção Diária (kg)
$graficoProducao = [
    'labels' => range(1, 30),
    'valores' => [46, 65, 58, 61, 66, 52, 47, 63, 59, 64, 55, 51, 60, 68, 57, 62, 65, 54, 58, 61, 66, 59, 63, 68, 71, 66, 60, 71, 43, 63],
];

// Gráfico de barras: Desperdício Diário (kg)
$graficoDesperdicio = [
    'labels' => range(1, 30),
    'valores' => [7, 3, 5, 6, 4, 8, 6, 4, 9, 8, 5, 3, 9, 9, 5, 4, 5, 4, 6, 9, 3, 5, 6, 4, 8, 7, 6, 8, 3, 4],
];

// Insights do mês (mesma ideia dos 4 cards inferiores da referência)
$insights = [
    [
        'label'   => 'Dia com maior fluxo',
        'valor'   => 'Dia 15 - 245 pessoas',
        'icone'   => 'trend-up',
    ],
    [
        'label'   => 'Dia com menor desperdício',
        'valor'   => 'Dia 2 - 2 kg',
        'icone'   => 'trend-up',
    ],
    [
        'label'   => 'Economia possível',
        'valor'   => '-57,9 kg',
        'icone'   => 'pulse',
    ],
    [
        'label'   => 'Tendência',
        'valor'   => 'Estável',
        'icone'   => 'trend-up',
    ],
];

// Itens do menu lateral: rota, ícone (chave) e rótulo.
$menu = [
    ['chave' => 'painel', 'rota' => '/painel', 'label' => 'Painel', 'icone' => 'bi-pie-chart-fill'],
    ['chave' => 'acessos', 'rota' => '/acessos', 'label' => 'Análise mensal', 'icone' => 'bi-calendar'],
    ['chave' => 'pessoas', 'rota' => '/pessoas', 'label' => 'Pessoas', 'icone' => ' bi-people-fill'],
    ['chave' => 'previsao', 'rota' => '/assistente', 'label' => 'Assistente IA', 'icone' => 'bi-chat-left'],
    ['chave' => 'relatorios', 'rota' => '/relatorios', 'label' => 'Relatórios', 'icone' => 'bi-clipboard-data'],
    ['chave' => 'config', 'rota' => '/configuracoes', 'label' => 'Configurações', 'icone' => 'bi-gear-fill'],
];

// Notificações não lidas (só pra alimentar o badge do sininho)
$notificacoesNaoLidas = 2;

$nomeUsuario   = $_SESSION['usuario_nome'] ?? 'Usuário';
$perfilUsuario = $_SESSION['usuario_perfil'] ?? '—';

// Função pequena só pra deixar o HTML dos ícones mais limpo lá embaixo.
function icone(string $nome): string
{
    $icones = [
        'grid'      => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
        'link'      => '<path d="M9 12h6M8 7h1a4 4 0 0 1 0 8H8M16 17h-1a4 4 0 0 1 0-8h1"/>',
        'users'     => '<circle cx="9" cy="8" r="3"/><path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6M16 8a3 3 0 1 1 0-6M22 20c0-2.7-2-5-5-5.5"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M9 12l2 2 4-4"/>',
        'meal'      => '<path d="M6 2v8a2 2 0 0 0 4 0V2M8 10v12M17 2c-1.7 0-3 2.2-3 5s1.3 5 3 5v10"/>',
        'file'      => '<path d="M7 2h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v5h5"/>',
        'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'bell'      => '<path d="M6 8a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 12 6 8Z"/><path d="M10 18a2 2 0 0 0 4 0"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'chevron'   => '<path d="M6 9l6 6 6-6"/>',
        'trend-up'  => '<path d="M4 15l5-5 4 4 7-7"/><path d="M15 7h5v5"/>',
        'trend-down' => '<path d="M4 9l5 5 4-4 7 7"/><path d="M15 17h5v-5"/>',
        'pulse'     => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
        'shield'    => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3Z"/><path d="M9.5 12l1.8 1.8L14.5 10"/>',
        'badge'     => '<circle cx="12" cy="9" r="3.5"/><path d="M6 21c0-3 2.7-5 6-5s6 2 6 5"/>',
        'door'      => '<rect x="5" y="3" width="12" height="18" rx="1"/><circle cx="14" cy="12" r="1"/>',
    ];
    return $icones[$nome] ?? '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acessos · SICAPDA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../public/css/painel.css">
    <link rel="stylesheet" href="../../public/css/acessos.css">
    <link rel="shortcut icon" href="../../public/img/logo_fluxe.png" type="image/x-icon">
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
                <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($nomeUsuario, 0, 1))) ?></div>
                <div class="sidebar-usuario-info">
                    <strong><?= htmlspecialchars($nomeUsuario) ?></strong>
                    <span><?= htmlspecialchars($perfilUsuario) ?> <i class="ponto-online"></i></span>
                </div>
                <a href="/logout" class="sair" title="Sair">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                        <path d="M16 17l5-5-5-5" />
                        <path d="M21 12H9" />
                    </svg>
                </a>
            </div>
        </aside>

        <!-- ===================== CONTEÚDO ===================== -->
        <div class="conteudo">

            <button class="btn-menu-mobile" id="btnMenuMobile" aria-label="Abrir menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <!-- Cabeçalho -->
            <header class="cabecalho">
                <div>
                    <h1>Análise Mensal</h1>
                    <p id="dataHoje">Carregando data…</p>
                </div>

                <div class="cabecalho-acoes">
                    <button class="seletor-data" id="seletorMes" type="button">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><?= icone('calendar') ?></svg>
                        <span id="labelPeriodo">Carregando período…</span>
                        <svg class="seletor-data-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><?= icone('chevron') ?></svg>
                    </button>

                    <button class="sino" id="btnNotificacoes" aria-label="Notificações">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><?= icone('bell') ?></svg>
                        <?php if ($notificacoesNaoLidas > 0): ?>
                            <span class="badge-sino"><?= (int)$notificacoesNaoLidas ?></span>
                        <?php endif; ?>
                    </button>
                </div>
            </header>

            <!-- Cards de resumo (3 colunas, igual à referência) -->
            <section class="cards-resumo cards-resumo-3">
                <?php foreach ($cardsResumo as $card): ?>
                    <div class="card">
                        <div class="card-icone card-icone-<?= htmlspecialchars($card['chave']) ?>">
                            <i class="iconeCard bi <?= htmlspecialchars($card['icone']) ?>"></i>
                        </div>
                        <p class="card-label"><?= htmlspecialchars($card['label']) ?></p>
                        <p class="card-valor">
                            <?= number_format($card['valor'], 0, ',', '.') ?><?= htmlspecialchars($card['sufixo'] ?? '') ?>
                        </p>
                        <p class="card-variacao <?= $card['positiva'] ? 'positiva' : 'negativa' ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"><?= icone($card['positiva'] ? 'trend-up' : 'trend-down') ?></svg>
                            <?= number_format($card['variacao'], 1, ',', '.') ?>% <span><?= htmlspecialchars($card['periodo']) ?></span>
                        </p>
                        <p class="card-extra"><?= htmlspecialchars($card['extra']) ?></p>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Gráfico de área: Número de Pessoas por Dia -->
            <section class="painel-grafico grafico-area-full">
                <h2>Número de Pessoas por Dia</h2>
                <div class="grafico-canvas grafico-canvas-area">
                    <canvas id="graficoPessoasDia"></canvas>
                </div>
            </section>

            <!-- Dois gráficos de barra lado a lado -->
            <section class="graficos-duplos">
                <div class="painel-grafico">
                    <h2>Produção Diária (kg)</h2>
                    <div class="grafico-canvas">
                        <canvas id="graficoProducao"></canvas>
                    </div>
                </div>

                <div class="painel-grafico">
                    <h2>Desperdício Diário (kg)</h2>
                    <div class="grafico-canvas">
                        <canvas id="graficoDesperdicio"></canvas>
                    </div>
                </div>
            </section>

            <!-- Insights do mês -->
            <section class="painel-card insights-card">
                <h2>Insights do Mês</h2>
                <div class="grid-insights">
                    <?php foreach ($insights as $insight): ?>
                        <div class="insight-item">
                            <div class="insight-icone">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"><?= icone($insight['icone']) ?></svg>
                            </div>
                            <div>
                                <p class="insight-label"><?= htmlspecialchars($insight['label']) ?></p>
                                <p class="insight-valor"><?= htmlspecialchars($insight['valor']) ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

        </div>
    </div>

    <!-- Dados do PHP -> JS (o acessos.js usa isso pra montar os gráficos) -->
    <script>
        window.DADOS_ACESSOS = {
            pessoasDia: <?= json_encode($graficoPessoasDia, JSON_UNESCAPED_UNICODE) ?>,
            producao: <?= json_encode($graficoProducao, JSON_UNESCAPED_UNICODE) ?>,
            desperdicio: <?= json_encode($graficoDesperdicio, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="../../public/js/chart.umd.min.js"></script>
    <script src="../../public/js/acessos.js"></script>
</body>

</html>