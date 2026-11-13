<?php
// Menu lateral compartilhado por todas as telas internas. Espera $paginaAtual (chave do item ativo).
// Pode receber $nomeUsuario / $fotoPerfil já lidos do banco (Configurações); senão usa os da sessão.
//
// Computador: o botão « recolhe o menu para uma faixa só com os ícones (mais espaço para a página) e » abre
// de novo; a escolha fica salva no navegador. Celular: o menu é uma gaveta aberta pelo botão ☰ do cabeçalho.
// Comportamento em public/js/sidebar.js; estilos em painel.css (seção SIDEBAR).
$nomeUsuario   = $nomeUsuario ?? ($_SESSION['usuario_nome'] ?? 'Usuário');
$perfilUsuario = $_SESSION['usuario_perfil'] ?? '—';
$fotoPerfil    = $fotoPerfil ?? ($_SESSION['usuario_foto'] ?? null);
$versaoSidebarJs = (int) @filemtime(__DIR__ . '/../../../public/js/sidebar.js');
?>
        <script>
            // Aplica o menu recolhido antes de desenhar a tela (sem "piscar" aberto e depois fechar).
            try {
                if (localStorage.getItem('sicapda_menu') === 'recolhido') {
                    document.documentElement.classList.add('menu-recolhido');
                }
            } catch (e) { /* localStorage indisponível: menu aberto */ }
        </script>
        <aside class="sidebar" id="sidebar" aria-label="Menu principal">
            <div class="sidebar-topo">
                <div class="sidebar-cabeca">
                    <div class="logo">
                        <span class="logo-sica">SICA<span class="logo-pda">PDA</span></span>
                        <span class="logo-by">by <strong>FLUXE</strong></span>
                    </div>

                    <!-- Computador: recolher/abrir o menu -->
                    <button type="button" class="btn-recolher" id="btnRecolherMenu" aria-controls="sidebar"
                        aria-expanded="true" title="Recolher menu" aria-label="Recolher menu">
                        <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
                    </button>
                    <!-- Celular: fechar a gaveta -->
                    <button type="button" class="btn-fechar-menu" id="btnFecharMenu" aria-label="Fechar menu">
                        <i class="bi bi-x-lg" aria-hidden="true"></i>
                    </button>
                </div>

                <nav class="menu">
                    <?php foreach (Pagina::menu() as $item): ?>
                        <a href="<?= htmlspecialchars($item['rota']) ?>" data-rotulo="<?= htmlspecialchars($item['label']) ?>"
                            class="menu-item <?= $paginaAtual === $item['chave'] ? 'ativo' : '' ?>"
                            <?= $paginaAtual === $item['chave'] ? 'aria-current="page"' : '' ?>>
                            <div class="menu-icone menu-icone-<?= htmlspecialchars($item['chave']) ?>">
                                <i class="iconeMenu bi <?= htmlspecialchars($item['icone']) ?>" aria-hidden="true"></i>
                            </div>
                            <span class="menu-rotulo"><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="sidebar-usuario">
                <a href="/configuracoes#perfil" class="sidebar-usuario-perfil" data-rotulo="<?= htmlspecialchars($nomeUsuario) ?>"
                    title="Meu perfil">
                    <?php if (!empty($fotoPerfil) && file_exists(__DIR__ . '/../../../public/' . $fotoPerfil)): ?>
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
                </a>
                <a href="/logout" class="sair" title="Sair" aria-label="Sair">
                    <i class="icone bi bi-box-arrow-right" aria-hidden="true"></i>
                </a>
            </div>
        </aside>
        <!-- Fundo escuro atrás da gaveta no celular (toque fecha o menu) -->
        <div class="sidebar-fundo" id="sidebarFundo" hidden></div>
        <script src="/js/sidebar.js?v=<?= $versaoSidebarJs ?>" defer></script>
