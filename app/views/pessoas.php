<?php
// Bootstrap próprio: garante que esta página seja protegida mesmo que
// alguém tente acessá-la diretamente pela URL, sem passar pelo roteador
// em app/routes/web.php (ex: fluxeteam.com.br/app/views/pessoas.php).
require_once __DIR__ . '/../../config/app.php';

// Bloqueia o acesso e redireciona para /login se não houver sessão ativa.
AuthMiddleware::autenticado();

// -----------------------------------------------------------------------
// DADOS DA PÁGINA DE PESSOAS — quem está no local agora, vindo do banco
// (último registro autorizado de hoje é uma entrada). Veja Acesso::presentesAgora().
// -----------------------------------------------------------------------

$paginaAtual = 'pessoas';

$painel = new Painel(Pagina::empresaId());
$dadosPessoas = $painel->pessoas();

$cardsResumo = $dadosPessoas['cardsResumo'];
$grupos = $dadosPessoas['grupos'];
$notificacoesNaoLidas = $painel->totalAlertas();

// Itens do menu lateral: rota, ícone (chave) e rótulo.
$menu = Pagina::menu();

// Badge do sino: quantidade de alertas ativos (calculados com dados reais)
$notificacoesNaoLidas = $notificacoesNaoLidas ?? 0;

$nomeUsuario = $_SESSION['usuario_nome'] ?? 'Usuário';
$perfilUsuario = $_SESSION['usuario_perfil'] ?? '—';
$fotoPerfil = $_SESSION['usuario_foto'] ?? null;

// Cores dos avatares da lista de pessoas, alternadas por índice pra dar
// variedade visual sem sair da paleta da marca (roxo, preto, âmbar).
$coresAvatar = ['avatar-cor-1', 'avatar-cor-2', 'avatar-cor-3'];

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
        'badge' => '<circle cx="12" cy="9" r="3.5"/><path d="M6 21c0-3 2.7-5 6-5s6 2 6 5"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    ];
    return $icones[$nome] ?? '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pessoas · SICAPDA</title>

    <!-- Aplica o tema salvo (claro/escuro/sistema) antes do CSS carregar, pra evitar
         o "flash" da tela clara antes de escurecer. Mesmo bloco de painel.php e
         configuracoes.php — é o que faz a preferência escolhida em Configurações
         valer aqui também. -->
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
    <link rel="stylesheet" href="/css/pessoas.css">
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
                    <h1>Pessoas</h1>
                    <p id="dataHoje">Carregando data…</p>
                </div>

                <div class="cabecalho-acoes">
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
            <section class="cards-resumo cards-resumo-4">
                <?php foreach ($cardsResumo as $card): ?>
                    <div class="card">
                        <div class="card-icone card-icone-<?= htmlspecialchars($card['chave']) ?>">
                            <i class="iconeCard bi <?= htmlspecialchars($card['icone']) ?>"></i>
                        </div>
                        <p class="card-label"><?= htmlspecialchars($card['label']) ?></p>
                        <p class="card-valor"><?= number_format($card['valor'], 0, ',', '.') ?></p>
                        <p class="card-extra"><?= htmlspecialchars($card['extra']) ?></p>
                    </div>
                <?php endforeach; ?>
            </section>

            <!-- Busca -->
            <div class="busca-pessoas">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round"><?= icone('search') ?></svg>
                <input type="text" id="buscaPessoas" placeholder="Buscar por nome ou função...">
            </div>

            <!-- Grupos de pessoas -->
            <?php foreach ($grupos as $grupo): ?>
                <section class="painel-card grupo-pessoas">
                    <div class="grupo-cabecalho">
                        <div class="grupo-titulo">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round"><?= icone($grupo['icone']) ?></svg>
                            <?= htmlspecialchars($grupo['titulo']) ?>
                        </div>
                        <span class="badge-presentes"><?= (int) $grupo['presentes'] ?> presentes</span>
                    </div>

                    <div class="lista-pessoas">
                        <?php foreach ($grupo['pessoas'] as $i => $pessoa): ?>
                            <div class="pessoa-item" data-nome="<?= htmlspecialchars(mb_strtolower($pessoa['nome'])) ?>"
                                data-funcao="<?= htmlspecialchars(mb_strtolower($pessoa['funcao'])) ?>">
                                <div class="pessoa-avatar <?= $coresAvatar[$i % count($coresAvatar)] ?>">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($pessoa['nome'], 0, 1))) ?>
                                </div>
                                <div class="pessoa-info">
                                    <span class="pessoa-nome"><?= htmlspecialchars($pessoa['nome']) ?></span>
                                    <span class="pessoa-funcao">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round"><?= icone('clock') ?></svg>
                                        Entrada: <?= htmlspecialchars($pessoa['entrada']) ?>
                                    </span>
                                </div>
                                <span class="pessoa-status" title="Presente"></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($grupo['restantes'] > 0): ?>
                        <p class="grupo-rodape">+ <?= (int) $grupo['restantes'] ?> outros colaboradores presentes</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

        </div>
    </div>

    <script src="/js/pessoas.js"></script>
</body>

</html>