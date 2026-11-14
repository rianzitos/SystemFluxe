<?php
// Renderizada pelo ProducaoController (rota /producao). Aberta direto pela URL, volta para a rota.
if (!isset($diaInicial)) {
    header('Location: /producao');
    exit;
}
$paginaAtual = 'producao';
$v = fn(string $arquivo) => (int) @filemtime(__DIR__ . '/../../public/' . $arquivo);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produção · SICAPDA</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(CsrfMiddleware::token()) ?>">

<?php require __DIR__ . '/partials/tema.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="/css/painel.css?v=<?= $v('css/painel.css') ?>">
    <link rel="stylesheet" href="/css/producao.css?v=<?= $v('css/producao.css') ?>">
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
                    <h1>Produção</h1>
                    <p>Registre quantos quilos foram produzidos e desperdiçados em cada refeição.</p>
                </div>
                <div class="cabecalho-acoes">
                    <?php require __DIR__ . '/partials/acoes-topo.php'; ?>
                </div>
            </header>

            <!-- Resumo do dia escolhido (preenchido pelo producao.js) -->
            <section class="cards-resumo" aria-label="Resumo do dia">
                <div class="card">
                    <div class="card-icone"><i class="icone bi bi-basket2-fill" aria-hidden="true"></i></div>
                    <p class="card-label">Produzido no dia</p>
                    <p class="card-valor" id="resumoProduzido">—</p>
                    <p class="card-variacao"><span id="resumoPrevisto">&nbsp;</span></p>
                </div>
                <div class="card">
                    <div class="card-icone"><i class="icone bi bi-trash3-fill" aria-hidden="true"></i></div>
                    <p class="card-label">Desperdiçado</p>
                    <p class="card-valor" id="resumoDesperdicado">—</p>
                    <p class="card-variacao"><span id="resumoFonteDesperdicio">&nbsp;</span></p>
                </div>
                <div class="card">
                    <div class="card-icone"><i class="icone bi bi-percent" aria-hidden="true"></i></div>
                    <p class="card-label">Desperdício</p>
                    <p class="card-valor" id="resumoPercentual">—</p>
                    <p class="card-variacao" id="resumoMeta"><span>&nbsp;</span></p>
                </div>
                <div class="card">
                    <div class="card-icone"><i class="icone bi bi-graph-up-arrow" aria-hidden="true"></i></div>
                    <p class="card-label">Previsão para o dia</p>
                    <p class="card-valor" id="resumoPessoas">—</p>
                    <p class="card-variacao"><span id="resumoSugestao">&nbsp;</span></p>
                </div>
            </section>

            <div class="producao-grade">
                <!-- Formulário do dia -->
                <section class="painel-card producao-form" aria-labelledby="tituloRegistro">
                    <div class="producao-topo">
                        <h2 id="tituloRegistro">Registrar produção</h2>
                        <div class="producao-data">
                            <button type="button" class="btn-dia" id="diaAnterior" aria-label="Dia anterior" title="Dia anterior">
                                <i class="bi bi-chevron-left" aria-hidden="true"></i>
                            </button>
                            <label class="campo-data">
                                <span class="visualmente-oculto">Data da produção</span>
                                <input type="date" id="dataProducao" value="<?= htmlspecialchars($diaInicial['data']) ?>"
                                    max="<?= htmlspecialchars($diaInicial['hoje']) ?>" min="2000-01-01">
                            </label>
                            <button type="button" class="btn-dia" id="diaSeguinte" aria-label="Dia seguinte" title="Dia seguinte">
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn-hoje" id="irHoje">Hoje</button>
                        </div>
                    </div>

                    <p class="producao-dica" id="dicaBalanca" hidden>
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        O desperdício chega sozinho pela balança. Se precisar, corrija aqui.
                    </p>

                    <form id="formProducao" novalidate>
                        <table class="tabela-producao">
                            <thead>
                                <tr>
                                    <th scope="col">Refeição</th>
                                    <th scope="col">Previsto</th>
                                    <th scope="col">Produzido (kg)</th>
                                    <th scope="col">Desperdiçado (kg)</th>
                                    <th scope="col">Desperdício</th>
                                </tr>
                            </thead>
                            <tbody id="linhasProducao"></tbody>
                            <tfoot>
                                <tr>
                                    <th scope="row">Total</th>
                                    <td id="totalPrevisto" data-rotulo="Previsto">—</td>
                                    <td id="totalProduzido" data-rotulo="Produzido">—</td>
                                    <td id="totalDesperdicado" data-rotulo="Desperdiçado">—</td>
                                    <td id="totalPercentual" data-rotulo="Desperdício">—</td>
                                </tr>
                            </tfoot>
                        </table>

                        <p class="producao-status" id="statusProducao" role="status" aria-live="polite" hidden></p>

                        <div class="producao-acoes">
                            <p class="producao-nota">Deixe em branco a refeição que não foi servida. Os números atualizam o painel,
                                a análise mensal e a previsão de demanda.</p>
                            <button type="submit" class="btn-salvar-producao" id="btnSalvarProducao">
                                <i class="bi bi-check2" aria-hidden="true"></i> Salvar produção
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Últimos dias -->
                <section class="painel-card producao-historico" aria-labelledby="tituloHistorico">
                    <h2 id="tituloHistorico">Últimos <?= RegistroProducao::DIAS_HISTORICO ?> dias</h2>
                    <p class="producao-nota">Toque em um dia para ver ou corrigir.</p>
                    <div id="listaHistorico"></div>
                </section>
            </div>
        </div>
    </div>

    <script type="application/json" id="dadosProducao"><?= json_encode($diaInicial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="/js/producao.js?v=<?= $v('js/producao.js') ?>"></script>
</body>

</html>
