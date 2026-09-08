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

window.addEventListener('load', () => {
    setTimeout(() => {
        const screen = document.getElementById('loading-screen');
        screen.classList.add('hidden');
        setTimeout(() => screen.remove(), 500);
    }, 1500);
});

document.body.classList.add("loading");
// Bloqueia a rolagem da página inserindo a trava de carregamento no corpo do site.
window.addEventListener("load", () => {
    // Espera o site carregar e, após 1.2 segundos, esconde a tela preta e libera o uso da página.
    const loadingScreen = document.getElementById("loading-screen");
    // Captura o container da tela de carregamento pelo ID para poder modificá-lo.
    setTimeout(() => {
        //  Cria um temporizador que aguarda exatamente 1,2 segundos antes de executar as ações internas.
        loadingScreen.classList.add("fade-out");
        // Adiciona a classe CSS que aplica o efeito de esmaecimento (sumir gradualmente).
        document.body.classList.remove("loading");
        // Remove a trava do corpo do site para liberar a rolagem da página para o usuário.
    }, 1200);

});

let lastScrollY = window.scrollY;

window.addEventListener('scroll', () => {
    const header = document.querySelector('#container-header');
    const currentScrollY = window.scrollY;

    if (currentScrollY > 100) {
        header.classList.add('header-fixed');
    }

    if (currentScrollY < lastScrollY) {
        header.classList.add('header-visible');
    } else {
        header.classList.remove('header-visible');
    }

    if (currentScrollY <= 100) {
        header.classList.remove('header-fixed');
        header.classList.remove('header-visible');
    }

    lastScrollY = currentScrollY;
});

const hamburgerBtn = document.getElementById('hamburgerBtn');
const menu = document.getElementById('menu');

hamburgerBtn.addEventListener('click', () => {
    menu.classList.toggle('open');
    hamburgerBtn.classList.toggle('active');
    hamburgerBtn.setAttribute('aria-expanded', menu.classList.contains('open'));
});

document.querySelectorAll('.itemMenu').forEach(link => {
    link.addEventListener('click', () => {
        menu.classList.remove('open');
        hamburgerBtn.classList.remove('active');
        hamburgerBtn.setAttribute('aria-expanded', 'false');
    });
});

/* ========================= */
/* TRANSIÇÃO DE SAÍDA - BOTÃO PROJETO */
/* ========================= */

const projectBtn = document.querySelector('#butProject a');
const transitionOverlay = document.getElementById('page-transition-overlay');

if (projectBtn && transitionOverlay) {
    projectBtn.addEventListener('click', (e) => {
        e.preventDefault();

        const destino = projectBtn.getAttribute('href');
        const rect = projectBtn.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;

        transitionOverlay.style.setProperty('--x', `${x}px`);
        transitionOverlay.style.setProperty('--y', `${y}px`);

        transitionOverlay.classList.add('active');

        setTimeout(() => {
            window.location.href = destino;
        }, 900);
    });
}

/* ---------------------------------------------------
   ScrollReveal — animações de entrada ao rolar a página
--------------------------------------------------- */
const revelar = ScrollReveal({
    distance: '40px',
    duration: 900,
    easing: 'cubic-bezier(0.5, 0, 0, 1)',
    reset: false,   // anima uma vez só, sem "piscar" ao subir e descer
    mobile: true,
});

// -------- HERO (Somos a Fluxe) --------
revelar.reveal('.titulo', {
    origin: 'left',
    distance: '30px',
    interval: 120,       // "SOMOS A" entra, depois "FLUXE" logo em seguida
});
revelar.reveal('.container-texto', { origin: 'left', distance: '30px', delay: 260 });
revelar.reveal('#butProject', { origin: 'bottom', delay: 380 });
revelar.reveal('.imageBox', { origin: 'right', distance: '60px', delay: 200 });

// Decorativos do hero — sutis, não competem com o texto
revelar.reveal('.meio-circulo', { origin: 'left', distance: '30px', scale: 0.9, duration: 700 });
revelar.reveal('.quadrado-amarelo', { origin: 'top', distance: '20px', delay: 300, duration: 600 });
revelar.reveal('.bolinhas', { origin: 'right', distance: '20px', delay: 400, duration: 600 });

// -------- SOBRE NÓS --------
revelar.reveal('.sobre .box-sobre .subTitulo1', { origin: 'left' });
revelar.reveal('.sobre .box-sobre .slash1', { origin: 'left', distance: '20px', delay: 100, duration: 600 });
revelar.reveal('.sobre .box-sobre .container-texto', { origin: 'left', delay: 180 });
revelar.reveal('.sobre .box-sobre .botao', { origin: 'left', delay: 280 });

revelar.reveal('.infos .card', {
    origin: 'bottom',
    interval: 150,
    distance: '30px',
});

// -------- PRINCIPAIS IMPLEMENTAÇÕES --------
revelar.reveal('.container-circuito', { origin: 'left', distance: '50px', duration: 1000 });
revelar.reveal('.implementacoes .subTitulo', { origin: 'left' });
revelar.reveal('.implementacoes .slash', { origin: 'left', distance: '20px', delay: 100, duration: 600 });

revelar.reveal('.quadro', {
    origin: 'bottom',
    interval: 150,
    distance: '40px',
    duration: 700,
});

// -------- EQUIPE --------
revelar.reveal('.equipe-label', { origin: 'top' });
revelar.reveal('.equipe-titulo', { origin: 'top', delay: 100 });
revelar.reveal('.equipe-subtitulo', { origin: 'top', delay: 180 });

revelar.reveal('.equipe-linha--top .membro-card:first-child', { origin: 'left', distance: '50px' });
revelar.reveal('.equipe-foto-central', { scale: 0.9, duration: 800, delay: 150 });
revelar.reveal('.equipe-linha--top .membro-card:last-child', { origin: 'right', distance: '50px' });

revelar.reveal('.equipe-linha--bottom .membro-card', {
    origin: 'bottom',
    interval: 150,
    distance: '30px',
});

revelar.reveal('.equipe-banner', { origin: 'bottom', delay: 150 });

// -------- RODAPÉ --------
revelar.reveal('#divirodape', { origin: 'left', distance: '30px' });
revelar.reveal('#rodapeUl li', {
    origin: 'right',
    interval: 100,
    distance: '20px',
    delay: 150,
});