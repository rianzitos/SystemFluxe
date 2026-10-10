/* ---------------------------------------------------
   Fluxe — página inicial
   Rolagem suave (Lenis + GSAP/ScrollTrigger), tela de loading, header,
   menu, tema claro/escuro, transição de página e animações de entrada.
--------------------------------------------------- */

const raiz = document.documentElement;

// Cada biblioteca vem de um CDN. Se algum deles falhar (rede lenta, bloqueio
// de firewall, adblock...), a página continua funcionando com rolagem nativa
// em vez de travar na tela de loading.
const temGsap = typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined';
const temLenis = typeof Lenis !== 'undefined';
const temScrollReveal = typeof ScrollReveal !== 'undefined';

// Quem pede "reduzir movimento" no sistema recebe rolagem nativa e sem animações
const reduzirMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/* ---------------------------------------------------
   Rolagem suave (Lenis sincronizado com o GSAP)
--------------------------------------------------- */
let lenis = null;

if (temGsap) {
    gsap.registerPlugin(ScrollTrigger);
}

if (temLenis && !reduzirMovimento) {
    // Em telas de toque o Lenis não interfere: a rolagem nativa do celular já é fluida
    lenis = new Lenis({
        duration: 1.2,
        smoothWheel: true,
    });

    if (temGsap) {
        lenis.on('scroll', ScrollTrigger.update);

        gsap.ticker.add((time) => {
            lenis.raf(time * 1000);
        });

        gsap.ticker.lagSmoothing(0);
    } else {
        const quadro = (time) => {
            lenis.raf(time);
            requestAnimationFrame(quadro);
        };
        requestAnimationFrame(quadro);
    }

    // A página só rola depois que a tela de loading sair
    lenis.stop();
}

/* ---------------------------------------------------
   Tela de loading
--------------------------------------------------- */
raiz.classList.add('is-loading'); // trava a rolagem enquanto o loading está visível

let paginaLiberada = false;

function liberarPagina() {
    if (paginaLiberada) return;
    paginaLiberada = true;

    const telaLoading = document.getElementById('loading-screen');
    if (telaLoading) {
        telaLoading.classList.add('hidden');
        setTimeout(() => telaLoading.remove(), 500);
    }

    raiz.classList.remove('is-loading');

    if (lenis) lenis.start();
    if (temGsap) ScrollTrigger.refresh();
}

function agendarLiberacao() {
    setTimeout(liberarPagina, 1500);
}

if (document.readyState === 'complete') {
    agendarLiberacao();
} else {
    window.addEventListener('load', agendarLiberacao);
}

// Rede de segurança: em conexões lentas o "load" pode demorar muito (as
// ilustrações são pesadas). Depois de 6s o loading sai de qualquer jeito.
setTimeout(liberarPagina, 6000);

/* ---------------------------------------------------
   Header: some ao descer, volta ao subir
   O header segue "sticky" o tempo todo, então não há salto de layout.
--------------------------------------------------- */
const header = document.getElementById('container-header');
const hamburgerBtn = document.getElementById('hamburgerBtn');
const menu = document.getElementById('menu');

let ultimoScrollY = window.scrollY;
let headerAgendado = false;

function atualizarHeader() {
    headerAgendado = false;

    // No iOS a rolagem "elástica" gera valores negativos ou acima do máximo
    const maximo = Math.max(0, raiz.scrollHeight - window.innerHeight);
    const atual = Math.min(Math.max(window.scrollY, 0), maximo);
    const diferenca = atual - ultimoScrollY;

    header.classList.toggle('header-scrolled', atual > 100);

    if (atual <= 100 || menu.classList.contains('open')) {
        header.classList.remove('header-hidden');
        ultimoScrollY = atual;
        return;
    }

    // Ignora tremidas pequenas para o header não ficar piscando
    if (Math.abs(diferenca) < 6) return;

    header.classList.toggle('header-hidden', diferenca > 0);
    ultimoScrollY = atual;
}

window.addEventListener('scroll', () => {
    if (headerAgendado) return;
    headerAgendado = true;
    requestAnimationFrame(atualizarHeader);
}, { passive: true });

atualizarHeader();

/* ---------------------------------------------------
   Menu hambúrguer
--------------------------------------------------- */
function fecharMenu() {
    menu.classList.remove('open');
    hamburgerBtn.classList.remove('active');
    hamburgerBtn.setAttribute('aria-expanded', 'false');
    hamburgerBtn.setAttribute('aria-label', 'Abrir menu');
}

hamburgerBtn.addEventListener('click', () => {
    const aberto = menu.classList.toggle('open');
    hamburgerBtn.classList.toggle('active', aberto);
    hamburgerBtn.setAttribute('aria-expanded', String(aberto));
    hamburgerBtn.setAttribute('aria-label', aberto ? 'Fechar menu' : 'Abrir menu');
    header.classList.remove('header-hidden');
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') fecharMenu();
});

document.querySelectorAll('.itemMenu').forEach(link => {
    link.addEventListener('click', fecharMenu);
});

/* ---------------------------------------------------
   Links internos (#inicio, #sobrenos, #solucoes, ...)
   Usam o scroll do Lenis em vez do "scroll-behavior" nativo, que brigava
   com a rolagem suave e deixava a navegação travada/aos pulos.
--------------------------------------------------- */
document.querySelectorAll('a[href^="#"]').forEach(link => {
    link.addEventListener('click', (e) => {
        const id = link.getAttribute('href');
        if (id.length < 2) return; // href="#" (ex.: botão do WhatsApp ainda sem link)

        const alvo = document.querySelector(id);
        if (!alvo) return;

        e.preventDefault();

        const noTopo = id === '#inicio'; // o hero começa logo abaixo do header: volta ao topo da página

        if (lenis) {
            if (noTopo) {
                lenis.scrollTo(0, { duration: 1.4 });
            } else {
                // Subindo, o header reaparece e cobriria o topo da seção: compensa a altura dele
                const descendo = alvo.getBoundingClientRect().top > 0;
                lenis.scrollTo(alvo, { offset: descendo ? 0 : -header.offsetHeight, duration: 1.4 });
            }
        } else if (noTopo) {
            window.scrollTo({ top: 0, behavior: reduzirMovimento ? 'auto' : 'smooth' });
        } else {
            alvo.scrollIntoView({ behavior: reduzirMovimento ? 'auto' : 'smooth', block: 'start' });
        }

        history.replaceState(null, '', id);
    });
});

/* ---------------------------------------------------
   Tema claro / escuro
   O tema inicial é aplicado por um script no <head> (sem "piscar").
   Prioridade: escolha salva > tema do sistema > claro.
--------------------------------------------------- */
const botaoTema = document.getElementById('themeToggle');
const temaDoSistema = window.matchMedia('(prefers-color-scheme: dark)');

function lerTemaSalvo() {
    try {
        return localStorage.getItem('fluxe-tema');
    } catch (e) {
        return null;
    }
}

function aplicarTema(tema) {
    raiz.setAttribute('data-theme', tema);

    if (botaoTema) {
        botaoTema.setAttribute('aria-label', tema === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro');
        botaoTema.setAttribute('aria-pressed', String(tema === 'dark'));
    }
}

aplicarTema(raiz.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

if (botaoTema) {
    botaoTema.addEventListener('click', () => {
        const novoTema = raiz.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';

        if (!reduzirMovimento) {
            raiz.classList.add('tema-animando');
            setTimeout(() => raiz.classList.remove('tema-animando'), 500);
        }

        aplicarTema(novoTema);

        try {
            localStorage.setItem('fluxe-tema', novoTema);
        } catch (e) { /* navegação privada: o tema só vale nesta visita */ }
    });
}

// Sem escolha salva, acompanha o sistema em tempo real
temaDoSistema.addEventListener('change', (e) => {
    if (!lerTemaSalvo()) aplicarTema(e.matches ? 'dark' : 'light');
});

/* ---------------------------------------------------
   Transição de saída (botão do projeto e cards das implementações)
--------------------------------------------------- */
const transitionOverlay = document.getElementById('page-transition-overlay');

document.querySelectorAll('#butProject, [data-transicao]').forEach(link => {
    link.addEventListener('click', (e) => {
        const comModificador = e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0;
        if (comModificador || !transitionOverlay || reduzirMovimento) return;

        e.preventDefault();

        const destino = link.getAttribute('href');
        const rect = link.getBoundingClientRect();

        // O círculo nasce onde a pessoa clicou (ou no centro do link, se foi pelo teclado)
        const x = e.clientX || rect.left + rect.width / 2;
        const y = e.clientY || rect.top + rect.height / 2;

        transitionOverlay.style.setProperty('--x', `${x}px`);
        transitionOverlay.style.setProperty('--y', `${y}px`);

        transitionOverlay.classList.add('active');

        setTimeout(() => {
            window.location.href = destino;
        }, 900);
    });
});

// Ao voltar pelo botão "voltar" do navegador a página pode vir do cache
// ainda coberta pelo círculo amarelo: limpa o overlay.
window.addEventListener('pageshow', (e) => {
    if (e.persisted && transitionOverlay) transitionOverlay.classList.remove('active');
});

/* ---------------------------------------------------
   ScrollReveal — animações de entrada ao rolar a página
--------------------------------------------------- */
if (temScrollReveal && !reduzirMovimento) {
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

    // -------- PRINCIPAIS IMPLEMENTAÇÕES (título; os cards entram via GSAP abaixo) --------
    revelar.reveal('.container-circuito', { origin: 'left', distance: '50px', duration: 1000 });
    revelar.reveal('.implementacoes .subTitulo', { origin: 'bottom', distance: '30px' });
    revelar.reveal('.implementacoes .slash', { origin: 'left', distance: '20px', delay: 100, duration: 600 });
    revelar.reveal('.impl-intro', { origin: 'bottom', distance: '24px', delay: 160 });

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
}

/* ---------------------------------------------------
   GSAP + ScrollTrigger — entrada dos cards das implementações
   Sem GSAP (ou com "reduzir movimento") os cards simplesmente aparecem.
--------------------------------------------------- */
if (temGsap && !reduzirMovimento) {
    gsap.from('.impl-card', {
        y: 60,
        opacity: 0,
        duration: 0.9,
        ease: 'power3.out',
        stagger: 0.12,
        clearProps: 'transform,opacity',
        scrollTrigger: {
            trigger: '.impl-grid',
            start: 'top 88%',
            once: true,
        },
    });
}
