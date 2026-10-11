/* ---------------------------------------------------
   Vídeo "Como funciona" — modal do botão "Ver como funciona" (início da /sicapda)
   • <dialog> nativo: o navegador prende o foco, fecha com ESC e deixa o fundo inerte.
   • O vídeo só é baixado quando o modal abre (preload="none"); ao passar o mouse ou focar no botão
     ele já começa a baixar, para abrir mais rápido.
   • Capítulos: botões que pulam para cada parte do vídeo e acompanham o tempo atual.
   Fica num arquivo separado (como o temaSys.js) para funcionar mesmo se algum CDN (GSAP/Lenis) falhar.
--------------------------------------------------- */
(() => {
    'use strict';

    const botao = document.getElementById('branco'); // botão "Ver como funciona"
    const modal = document.getElementById('modalVideo');
    const video = document.getElementById('videoComoFunciona');
    if (!botao || !modal || !video) return;

    // Navegador sem <dialog> modal (muito antigo): abre o arquivo do vídeo numa nova aba
    if (typeof modal.showModal !== 'function') {
        botao.addEventListener('click', () => {
            const origem = video.querySelector('source');
            if (origem) window.open(origem.src, '_blank', 'noopener');
        });
        return;
    }

    const aviso = document.getElementById('modalVideoErro');
    const capitulos = Array.from(modal.querySelectorAll('[data-tempo]'));
    const reduzirMovimento = window.matchMedia('(prefers-reduced-motion: reduce)');
    let focoAnterior = null;

    /* Trava a rolagem da página por trás do modal. Usa o <html> e pausa o Lenis, como o menu mobile
       (scriptSys.js). O "typeof" evita erro se o Lenis não tiver carregado. */
    function travarRolagem() {
        document.documentElement.style.overflow = 'hidden';
        if (typeof lenis !== 'undefined') lenis.stop();
    }

    function liberarRolagem() {
        document.documentElement.style.overflow = '';
        if (typeof lenis !== 'undefined') lenis.start();
    }

    /* Começa a baixar o vídeo quando a pessoa mostra intenção de abrir (mouse em cima, foco ou toque).
       Respeita o "economizar dados" do navegador. */
    function preparar() {
        if (video.preload !== 'none') return;
        if (navigator.connection && navigator.connection.saveData) return;
        video.preload = 'auto';
        video.load();
    }

    function abrir() {
        focoAnterior = document.activeElement;
        // Uma falha anterior (sem internet, por exemplo) não pode ficar "grudada": ao abrir de novo, tenta carregar outra vez
        if (!aviso.hidden) {
            aviso.hidden = true;
            video.load();
        }
        modal.showModal();
        travarRolagem();

        video.currentTime = 0;
        marcarCapitulo();

        // Quem pediu "reduzir animações" vê o vídeo parado, com o pôster, e dá o play quando quiser
        if (!reduzirMovimento.matches) {
            const tocando = video.play();
            if (tocando && tocando.catch) tocando.catch(() => { /* autoplay bloqueado: os controles continuam ali */ });
        }
    }

    /* Idempotente: roda ao fechar pelo botão/clique fora e de novo no evento "close" (que o ESC também dispara). */
    function limpar() {
        video.pause();
        liberarRolagem();
        if (focoAnterior && focoAnterior.focus) focoAnterior.focus({ preventScroll: true });
        focoAnterior = null;
    }

    function fechar() {
        if (modal.open) modal.close();
        limpar();
    }

    botao.addEventListener('click', abrir);
    botao.addEventListener('pointerenter', preparar, { once: true });
    botao.addEventListener('focus', preparar, { once: true });
    botao.addEventListener('touchstart', preparar, { once: true, passive: true });
    modal.addEventListener('close', limpar);
    window.addEventListener('pagehide', () => { if (modal.open) fechar(); });

    // Qualquer elemento com data-fechar-video fecha o modal. Na fase de captura, para o modal já ter
    // devolvido a rolagem quando o clique chegar ao link de âncora (a rolagem suave do scriptSys.js).
    modal.addEventListener('click', (evento) => {
        if (evento.target.closest && evento.target.closest('[data-fechar-video]')) fechar();
    }, true);

    // Clique no fundo escuro fecha — mas só se o clique COMEÇOU nele. Sem isso, arrastar a barra de
    // progresso e soltar o mouse fora da caixa fecharia o vídeo sem querer.
    let comecouNoFundo = false;
    modal.addEventListener('pointerdown', (evento) => { comecouNoFundo = evento.target === modal; });
    modal.addEventListener('click', (evento) => {
        if (evento.target === modal && comecouNoFundo) fechar();
        comecouNoFundo = false;
    });

    /* ---------- Capítulos ---------- */
    function marcarCapitulo() {
        const agora = video.currentTime;
        let ativo = -1;
        capitulos.forEach((cap, i) => {
            // 0,25 s de folga: a legenda do capítulo aparece um pouco antes do ponto em que o botão pula
            if (agora >= parseFloat(cap.dataset.tempo) - 0.25) ativo = i;
        });
        capitulos.forEach((cap, i) => {
            cap.classList.toggle('is-ativo', i === ativo);
            if (i === ativo) cap.setAttribute('aria-current', 'true');
            else cap.removeAttribute('aria-current');
        });
    }

    capitulos.forEach((cap) => {
        cap.addEventListener('click', () => {
            video.currentTime = parseFloat(cap.dataset.tempo);
            const tocando = video.play();
            if (tocando && tocando.catch) tocando.catch(() => { });
        });
    });

    video.addEventListener('timeupdate', marcarCapitulo);
    video.addEventListener('seeked', marcarCapitulo);

    /* ---------- Falha ao carregar ---------- */
    function mostrarAviso() { aviso.hidden = false; }
    video.addEventListener('error', mostrarAviso);
    const origem = video.querySelector('source');
    if (origem) origem.addEventListener('error', mostrarAviso);
    video.addEventListener('playing', () => { aviso.hidden = true; });
})();
