gsap.registerPlugin(ScrollTrigger);

const lenis = new Lenis({
    duration: 1.2,
    smoothWheel: true,
});

lenis.on('scroll', ScrollTrigger.update);

gsap.ticker.add((time) => {
    lenis.raf(time * 1000);
});

gsap.ticker.lagSmoothing(0);

/* ---------------------------------------------------
   Header: mostra/esconde ao rolar a página
--------------------------------------------------- */
// let lastScrollY = window.scrollY;

// window.addEventListener('scroll', () => {
//     const header = document.querySelector('.containerHeader');
//     const currentScrollY = window.scrollY;

//     if (currentScrollY > 100) {
//         header.classList.add('header-fixed');
//     }

//     if (currentScrollY < lastScrollY) {
//         header.classList.add('header-visible');
//     } else {
//         header.classList.remove('header-visible');
//     }

//     if (currentScrollY <= 100) {
//         header.classList.remove('header-fixed');
//         header.classList.remove('header-visible');
//     }

//     lastScrollY = currentScrollY;
// });

/* ---------------------------------------------------
   Menu hamburguer (mobile/tablet)
--------------------------------------------------- */
const header = document.querySelector('.containerHeader');
const menuToggle = document.getElementById('menuToggle');
const headerActions = document.getElementById('headerActions');
const menuIcon = menuToggle.querySelector('i');

function openMenu() {
    headerActions.classList.add('is-open');
    menuToggle.setAttribute('aria-expanded', 'true');
    menuIcon.classList.remove('bi-list');
    menuIcon.classList.add('bi-x-lg');

    // Garante que o header esteja visível enquanto o menu está aberto,
    // mesmo que ele estivesse escondido pelo scroll no momento do clique.
    header.classList.add('header-visible');

    // Trava o scroll da página por trás do menu aberto.
    document.body.style.overflow = 'hidden';
}

function closeMenu() {
    headerActions.classList.remove('is-open');
    menuToggle.setAttribute('aria-expanded', 'false');
    menuIcon.classList.remove('bi-x-lg');
    menuIcon.classList.add('bi-list');
    document.body.style.overflow = '';
}

menuToggle.addEventListener('click', () => {
    const isOpen = headerActions.classList.contains('is-open');
    isOpen ? closeMenu() : openMenu();
});

// Fecha o menu ao clicar em qualquer link dele (rolagem para a seção)
headerActions.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => closeMenu());
});

// Se a tela for redimensionada para o layout de desktop, fecha o menu
window.addEventListener('resize', () => {
    if (window.innerWidth > 900 && headerActions.classList.contains('is-open')) {
        closeMenu();
    }
});

/* ---------------------------------------------------
   Rolagem suave até as âncoras do menu, usando o Lenis
   (mantém a mesma sensação de scroll do resto do site)
--------------------------------------------------- */
document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener('click', (event) => {
        const targetId = anchor.getAttribute('href');
        const target = document.querySelector(targetId);

        if (!target) return;

        event.preventDefault();
        lenis.scrollTo(target, { offset: -110 });
    });
});

/* ---------------------------------------------------
   Planos e Preços: alterna valores mensal/anual
--------------------------------------------------- */
const toggleAnual = document.getElementById('toggleAnual');

if (toggleAnual) {
    const valores = document.querySelectorAll('.valor');
    const periodos = document.querySelectorAll('.periodo');

    toggleAnual.addEventListener('change', () => {
        const anual = toggleAnual.checked;

        valores.forEach((valor) => {
            valor.textContent = anual ? valor.dataset.anual : valor.dataset.mensal;
        });

        periodos.forEach((periodo) => {
            periodo.textContent = anual ? '/mês no plano anual' : '/mês';
        });
    });
}

/* ---------------------------------------------------
   ScrollReveal — animações de entrada ao rolar a página
--------------------------------------------------- */
if (typeof ScrollReveal !== 'undefined') {

    const sr = ScrollReveal({
        distance: '40px',
        duration: 900,
        easing: 'cubic-bezier(0.5, 0, 0, 1)',
        reset: false,
        mobile: true,
    });

    // -------- HERO --------
    sr.reveal('.heroAppBadge', { origin: 'left', distance: '20px', duration: 700 });
    sr.reveal('#containerSICAPDA h1', { origin: 'left', distance: '30px' });
    sr.reveal('#containerSICAPDA h2', { origin: 'left', distance: '30px', delay: 120 });
    sr.reveal('#containerSICAPDA .slash', { origin: 'left', distance: '20px', delay: 200, duration: 600 });
    sr.reveal('#containerSICAPDA p', { origin: 'left', distance: '30px', delay: 260 });
    sr.reveal('#buttonRow', { origin: 'bottom', delay: 340 });
    sr.reveal('#icones', { origin: 'bottom', delay: 420 });
    sr.reveal('#painelGeral', { origin: 'right', distance: '60px', delay: 200 });

    // -------- FUNCIONALIDADES --------
    sr.reveal('.boxes', {
        origin: 'bottom',
        interval: 150,
        distance: '30px',
    });

    // -------- SOBRE O SISTEMA --------
    sr.reveal('.sobreSistema .miniTitulo', { origin: 'left' });
    sr.reveal('.sobreSistema h1', { origin: 'left', delay: 100 });
    sr.reveal('.sobreSistema p', { origin: 'left', delay: 180 });
    sr.reveal('.sobreSistema .botaoSobre', { origin: 'left', delay: 260 });
    sr.reveal('#imagemSobre', { origin: 'right', distance: '60px', delay: 150 });

    // -------- APLICATIVO MOBILE --------
    // Listas aparecem em cascata com um reveal POR ITEM (delay crescente). Não uso "interval" aqui:
    // o interval do ScrollReveal monta uma sequência que trava itens quando a página "pula"
    // (barra de rolagem arrastada, Home/End, grids com 2ª linha).
    const emCascata = (seletor, opcoes, passo, colunas = 0) => {
        document.querySelectorAll(seletor).forEach((el, i) => {
            const posicao = colunas ? i % colunas : i;
            sr.reveal(el, Object.assign({}, opcoes, { delay: (opcoes.delay || 0) + posicao * passo }));
        });
    };

    sr.reveal('.appHeroTexto .miniTitulo', { origin: 'left' });
    sr.reveal('.appHeroTexto h2', { origin: 'left', delay: 100 });
    sr.reveal('.appHeroTexto > p', { origin: 'left', delay: 180 });
    sr.reveal('.appChips', { origin: 'left', delay: 240 });
    sr.reveal('.appAcoes', { origin: 'bottom', delay: 300 });
    sr.reveal('.appPalco', { origin: 'right', distance: '60px', delay: 150 });

    sr.reveal('.appTelasTexto .miniTitulo', { origin: 'left' });
    sr.reveal('.appTelasTexto h2', { origin: 'left', delay: 100 });
    sr.reveal('.appTelasTexto > p', { origin: 'left', delay: 180 });
    emCascata('.appAba', { origin: 'left', distance: '30px', duration: 700 }, 90);
    sr.reveal('.appTelaPalco', { origin: 'right', distance: '60px', delay: 150 });

    emCascata('.appRecursosTopo > *', { origin: 'top' }, 100);
    emCascata('.appCard', { origin: 'bottom', distance: '40px', duration: 700 }, 120, 3);
    sr.reveal('.appIntegracao', { origin: 'bottom', distance: '40px' });

    emCascata('.appBaixarTexto > :not(.appAvisoIos)', { origin: 'left' }, 100);
    sr.reveal('.appBaixarCartao', { origin: 'right', distance: '50px', delay: 150 });
    sr.reveal('.appFaq h2', { origin: 'top' });
    emCascata('.appFaqLista details', { origin: 'bottom', distance: '20px', duration: 600 }, 90, 2);

    // -------- PLANOS E PREÇOS --------
    sr.reveal('.precosMiniTitulo', { origin: 'top' });
    sr.reveal('.precosTitulo', { origin: 'top', delay: 100 });
    sr.reveal('.precosSubtitulo', { origin: 'top', delay: 180 });
    sr.reveal('.toggleWrapper', { origin: 'top', delay: 240 });
    sr.reveal('.planoCard', {
        origin: 'bottom',
        interval: 180,
        distance: '50px',
        duration: 700,
    });
    sr.reveal('.planoFooterItem', {
        origin: 'bottom',
        interval: 120,
        distance: '20px',
        duration: 600,
    });

    // -------- CONTATO --------
    sr.reveal('.contatoTexto .miniTitulo', { origin: 'left' });
    sr.reveal('.contatoTexto h1', { origin: 'left', delay: 100 });
    sr.reveal('.contatoTexto p', { origin: 'left', delay: 180 });
    sr.reveal('.contatoInfo', { origin: 'left', delay: 240 });
    sr.reveal('.contatoTexto .ctaOutline', { origin: 'left', delay: 300 });
    sr.reveal('.formCard', { origin: 'right', distance: '50px', delay: 150 });

    // -------- FOOTER --------
    sr.reveal('.footerBrand', { origin: 'bottom' });
    sr.reveal('.info', {
        origin: 'bottom',
        interval: 120,
        distance: '20px',
        duration: 600,
    });
}