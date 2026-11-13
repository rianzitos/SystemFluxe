// Menu lateral das telas internas (app/views/partials/sidebar.php).
//
// Computador (mais de 780px): o botão « recolhe o menu para uma faixa só com os ícones e » abre de novo. A escolha
// fica salva no navegador (localStorage "sicapda_menu") e vale para todas as telas. Atalho: Ctrl + B.
// Celular: o menu é uma gaveta. O botão ☰ do cabeçalho abre; fecha no X, no fundo escuro, com Esc ou ao escolher
// uma tela.
(function () {
    'use strict';

    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;

    const raiz = document.documentElement;
    const btnRecolher = document.getElementById('btnRecolherMenu');
    const btnFechar = document.getElementById('btnFecharMenu');
    const btnAbrir = document.getElementById('btnMenuMobile');
    const fundo = document.getElementById('sidebarFundo');
    const celular = window.matchMedia('(max-width: 780px)');
    const CHAVE = 'sicapda_menu';

    // ─── Computador: recolher / abrir ───────────────────────────────────────

    function atualizarBotaoRecolher() {
        if (!btnRecolher) return;
        const recolhido = raiz.classList.contains('menu-recolhido');
        const texto = recolhido ? 'Abrir menu' : 'Recolher menu';
        btnRecolher.setAttribute('aria-expanded', String(!recolhido));
        btnRecolher.setAttribute('aria-label', texto);
        btnRecolher.title = texto + ' (Ctrl + B)';
    }

    function alternarRecolhido() {
        // A animação só liga depois do primeiro clique: ao carregar a página o menu já aparece no estado salvo.
        raiz.classList.add('menu-animado');
        const recolhido = raiz.classList.toggle('menu-recolhido');
        try {
            localStorage.setItem(CHAVE, recolhido ? 'recolhido' : 'aberto');
        } catch (e) { /* sem localStorage: vale só até recarregar */ }
        atualizarBotaoRecolher();
        // Gráficos (Chart.js) e outras telas que medem a largura se ajustam ao novo espaço.
        setTimeout(() => window.dispatchEvent(new Event('resize')), 220);
    }

    btnRecolher?.addEventListener('click', alternarRecolhido);
    atualizarBotaoRecolher();

    document.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && !ev.shiftKey && ev.key.toLowerCase() === 'b' && !celular.matches) {
            ev.preventDefault();
            alternarRecolhido();
        }
    });

    // ─── Celular: gaveta ────────────────────────────────────────────────────

    btnAbrir?.setAttribute('aria-controls', 'sidebar');
    btnAbrir?.setAttribute('aria-expanded', 'false');

    function abrirGaveta() {
        sidebar.classList.add('aberta');
        if (fundo) fundo.hidden = false;
        btnAbrir?.setAttribute('aria-expanded', 'true');
        document.body.classList.add('menu-gaveta-aberta');
        (btnFechar || sidebar.querySelector('.menu-item'))?.focus();
    }

    function fecharGaveta(devolverFoco = true) {
        if (!sidebar.classList.contains('aberta')) return;
        sidebar.classList.remove('aberta');
        if (fundo) fundo.hidden = true;
        btnAbrir?.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('menu-gaveta-aberta');
        if (devolverFoco) btnAbrir?.focus();
    }

    btnAbrir?.addEventListener('click', () => (sidebar.classList.contains('aberta') ? fecharGaveta() : abrirGaveta()));
    btnFechar?.addEventListener('click', () => fecharGaveta());
    fundo?.addEventListener('click', () => fecharGaveta());
    sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => fecharGaveta(false)));
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') fecharGaveta();
    });

    // Girou o celular / aumentou a janela: a gaveta não fica presa aberta no layout de computador.
    celular.addEventListener('change', (ev) => {
        if (!ev.matches) fecharGaveta(false);
    });
})();
