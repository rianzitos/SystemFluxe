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

const revelar = ScrollReveal({
    origin: 'bottom', // Origem da animação
    distance: '50px', // Distância da animação
    duration: 800,   // Duração em milissegundos
    reset: true      // Se a animação repete ao scrollar de volta
});

revelar.reveal('.box-sobre', {// revelar.reveal() serve para animar elementos conforme o usuário rola a página
    delay: 400
});

revelar.reveal('.box', {// revelar.reveal() serve para animar elementos conforme o usuário rola a página
    delay: 400
});

revelar.reveal('.meio-circulo', {// '.meio-circulo' são Classes que servem apenas para identificar quais elementos do HTML serão afetados.
    delay: 400 // delay tempo de espera antes da animação iniciar
})


revelar.reveal('.quadrado-amarelo', {
    delay: 400
})
// Anima o bloco visual amarelo decorativo após 0.4 segundos.

revelar.reveal('.bolinhas', {
    delay: 400
})
// Anima o elemento de bolinhas decorativas em segundo plano após 0.4 segundos.
revelar.reveal('.imageBox', {
    delay: 600
});
// Faz a caixa da imagem principal surgir na tela após 0.6 segundos.
revelar.reveal('.circulo', {
    delay: 400
});
// Ativa a animação de círculos visuais ou decorativos com atraso de 0.4 segundos.
revelar.reveal('.idea', {
    delay: 400
})
// Revela o ícone circular de inovação (lâmpada) nos cards após 0.4 segundos.
revelar.reveal('.target', {
    delay: 600
})
// Exibe o ícone de foco/alvo do segundo card após 0.6 segundos.
revelar.reveal('.people', {
    delay: 800
})
// Mostra o ícone de parceria do terceiro card após 0.8 segundos.
revelar.reveal('.card', {
    delay: 400
})
// Inicia a animação de surgimento dos cards de conteúdo após 0.4 segundos.
revelar.reveal('.row', {
    delay: 400
})
// Aplica o efeito de revelação nas linhas internas que organizam os cards após 0.4 segundos.
revelar.reveal('.quadro1', {
    delay: 400
})
// faz o primeiro quadro de conteúdo ou imagem surgir após 0.4 segundos.
revelar.reveal('.quadro2', {
    delay: 600
})
// Revela o segundo quadro em sequência com atraso de 0.6 segundos.
revelar.reveal('.quadro3', {
    delay: 800
})
// Exibe o terceiro quadro de forma sequencial após 0.8 segundos.
revelar.reveal('.quadro4', {
    delay: 1000
})
// Finaliza a sequência exibindo o quarto quadro após 1 segundo completo.

revelar.reveal('.equipe-header', {
    delay: 800
});

// revelar.reveal('.equipe-titulo', {
//     delay: 1000
// })

// revelar.reveal('.quadro4', {
//     delay: 1000
// })

/* ========================= */
/* LOADING SCREEN */
/* ========================= */

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