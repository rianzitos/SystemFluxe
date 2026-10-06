<?php
// Esta view é renderizada pelo RelatorioController (rota /relatorios).
// Se alguém abrir o arquivo direto pela URL, volta para a rota correta.
if (!isset($dados)) {
    header('Location: /relatorios');
    exit;
}

$paginaAtual = 'relatorios';
$f = $dados['filtros'];
$notificacoesNaoLidas = $dados['notificacoesNaoLidas'];
$hoje = date('Y-m-d');

$fmt = fn(string $ymd): string => date('d/m/Y', strtotime($ymd));
$classeCategoria = ['operador' => 'badge-cat-operador', 'supervisor' => 'badge-cat-supervisor', 'prestador' => 'badge-cat-prestador'];

// Querystring dos filtros atuais (para paginação e exportação)
$qs = fn(array $extra = []): string => http_build_query(array_filter(
    array_merge(['busca' => $f['busca'], 'categoria' => $f['categoria'], 'de' => $f['de'], 'ate' => $f['ate']], $extra),
    fn($v) => $v !== '' && $v !== null
));

$de = $dados['pagina'] > 0 ? ($dados['pagina'] - 1) * $dados['porPagina'] + 1 : 0;
$ate = min($dados['total'], $dados['pagina'] * $dados['porPagina']);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios · SICAPDA</title>

<?php require __DIR__ . '/partials/tema.php'; ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="/css/painel.css">
    <link rel="stylesheet" href="/css/relatorios.css">
    <link rel="shortcut icon" href="/img/logo_fluxe.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
</head>

<body>
    <div class="layout">

        <!-- ===================== SIDEBAR ===================== -->
<?php require __DIR__ . '/partials/sidebar.php'; ?>

        <!-- ===================== CONTEÚDO ===================== -->
        <div class="conteudo">

            <button class="btn-menu-mobile" id="btnMenuMobile" aria-label="Abrir menu">
                <?= Pagina::svg('menu') ?>
            </button>

            <header class="cabecalho">
                <div>
                    <h1>Relatórios</h1>
                    <p>Visualize e acompanhe todos os registros e dados do sistema.</p>
                </div>

                <div class="cabecalho-acoes">
                    <a class="btn-exportar" href="/relatorios/exportar?<?= htmlspecialchars($qs()) ?>">
                        <?= Pagina::svg('download') ?> Exportar CSV
                    </a>

                    <label class="seletor-data seletor-periodo" title="Período do relatório">
                        <?= Pagina::svg('calendar') ?>
                        <select id="presetPeriodo" aria-label="Período">
                            <option value="" selected><?= $fmt($f['de']) ?> - <?= $fmt($f['ate']) ?></option>
                            <option value="hoje">Hoje</option>
                            <option value="7d">Últimos 7 dias</option>
                            <option value="30d">Últimos 30 dias</option>
                            <option value="mes">Este mês</option>
                            <option value="mesant">Mês anterior</option>
                        </select>
                        <?= Pagina::svg('chevron') ?>
                    </label>

                    <a class="sino" href="/assistente" id="btnNotificacoes" aria-label="Notificações">
                        <?= Pagina::svg('bell') ?>
                        <?php if ($notificacoesNaoLidas > 0): ?>
                            <span class="badge-sino"><?= (int) $notificacoesNaoLidas ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </header>

            <!-- Filtros -->
            <form class="filtros-relatorio" id="formFiltros" method="get" action="/relatorios">
                <label class="campo-filtro campo-busca">
                    <?= Pagina::svg('search') ?>
                    <input type="search" name="busca" maxlength="100" placeholder="Buscar por nome ou função…"
                        value="<?= htmlspecialchars($f['busca']) ?>" aria-label="Buscar por nome">
                </label>

                <label class="campo-filtro">
                    <?= Pagina::svg('calendar') ?>
                    <input type="date" name="de" id="campoDe" value="<?= htmlspecialchars($f['de']) ?>"
                        max="<?= $hoje ?>" aria-label="Data inicial">
                </label>

                <label class="campo-filtro">
                    <?= Pagina::svg('calendar') ?>
                    <input type="date" name="ate" id="campoAte" value="<?= htmlspecialchars($f['ate']) ?>"
                        max="<?= $hoje ?>" aria-label="Data final">
                </label>

                <label class="campo-filtro campo-select">
                    <?= Pagina::svg('filter') ?>
                    <select name="categoria" aria-label="Filtrar por função">
                        <option value="">Todas as funções</option>
                        <?php foreach (Acesso::CATEGORIAS as $chave => $rotulo): ?>
                            <option value="<?= $chave ?>" <?= $f['categoria'] === $chave ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rotulo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit" class="btn-filtrar">Filtrar</button>
                <a class="btn-limpar" href="/relatorios">Limpar</a>
            </form>

            <!-- Cards de resumo -->
            <section class="cards-resumo">
                <div class="card">
                    <div class="card-icone"><i class="icone bi bi-person-fill"></i></div>
                    <p class="card-label">Total de Registros</p>
                    <p class="card-valor"><?= number_format($dados['cards']['total'], 0, ',', '.') ?></p>
                </div>
                <div class="card">
                    <div class="card-icone"><i class="icone bi bi-calendar-event"></i></div>
                    <p class="card-label">Hoje</p>
                    <p class="card-valor"><?= number_format($dados['cards']['hoje'], 0, ',', '.') ?></p>
                </div>
                <div class="card">
                    <div class="card-icone"><i class="icone bi bi-clock"></i></div>
                    <p class="card-label">Duração Média</p>
                    <p class="card-valor"><?= htmlspecialchars($dados['cards']['duracao']) ?></p>
                </div>
                <div class="card">
                    <div class="card-icone"><i class="icone bi bi-clock-history"></i></div>
                    <p class="card-label">Pico de Entrada</p>
                    <p class="card-valor"><?= htmlspecialchars($dados['cards']['pico']) ?></p>
                </div>
            </section>

            <!-- Tabela -->
            <section class="painel-card tabela-card">
                <h2>Registros</h2>

                <?php if (!$dados['registros']): ?>
                    <div class="estado-vazio">
                        <i class="bi bi-inbox"></i>
                        <strong>Nenhum registro encontrado</strong>
                        <span>Ajuste os filtros ou o período para ver mais resultados.</span>
                    </div>
                <?php else: ?>
                    <div class="tabela-scroll">
                        <table class="tabela-registros">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Função</th>
                                    <th>Data</th>
                                    <th>Entrada</th>
                                    <th>Saída</th>
                                    <th>Duração</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dados['registros'] as $r): ?>
                                    <tr>
                                        <td>
                                            <div class="celula-nome">
                                                <span class="mini-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($r['nome'], 0, 1))) ?></span>
                                                <span class="nome-bloco">
                                                    <span><?= htmlspecialchars($r['nome']) ?></span>
                                                    <small><?= htmlspecialchars($r['cargo']) ?></small>
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge-cat <?= $classeCategoria[$r['categoria']] ?? '' ?>">
                                                <?= htmlspecialchars(Acesso::CATEGORIAS[$r['categoria']] ?? $r['categoria']) ?>
                                            </span>
                                        </td>
                                        <td class="muted"><?= $fmt($r['dia']) ?></td>
                                        <td>
                                            <?php if ($r['entrada']): ?>
                                                <span class="hora hora-entrada"><i class="bi bi-clock"></i> <?= date('H:i', strtotime($r['entrada'])) ?></span>
                                            <?php else: ?><span class="muted">—</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($r['saida']): ?>
                                                <span class="hora hora-saida"><i class="bi bi-clock"></i> <?= date('H:i', strtotime($r['saida'])) ?></span>
                                            <?php elseif ($r['dia'] === $hoje): ?>
                                                <span class="badge-andamento">No local</span>
                                            <?php else: ?><span class="muted" title="Saída não registrada">—</span><?php endif; ?>
                                        </td>
                                        <td class="duracao"><?= htmlspecialchars(Datas::duracao($r['duracao_min'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="paginacao">
                        <span>Mostrando <?= $de ?>–<?= $ate ?> de <?= number_format($dados['total'], 0, ',', '.') ?> registros</span>
                        <?php if ($dados['paginas'] > 1): ?>
                            <nav aria-label="Paginação">
                                <?php if ($dados['pagina'] > 1): ?>
                                    <a href="/relatorios?<?= htmlspecialchars($qs(['pagina' => $dados['pagina'] - 1])) ?>">‹ Anterior</a>
                                <?php endif; ?>
                                <strong>Página <?= $dados['pagina'] ?> de <?= $dados['paginas'] ?></strong>
                                <?php if ($dados['pagina'] < $dados['paginas']): ?>
                                    <a href="/relatorios?<?= htmlspecialchars($qs(['pagina' => $dados['pagina'] + 1])) ?>">Próxima ›</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

        </div>
    </div>

    <script src="/js/relatorios.js"></script>
</body>

</html>
