/* =====================================================================
   APLICATIVO MOBILE — comportamento das seções da página /sicapda
   • Abas das telas (setas, Home/End, troca suave da imagem do celular)
   • QR code de download (gerado no navegador para o endereço real do site)
   • Botão "Copiar" do SHA-256
   • Aviso para iPhone/iPad (o instalador é só Android)
===================================================================== */
(() => {
    'use strict';

    const $ = (seletor, raiz = document) => raiz.querySelector(seletor);
    const $$ = (seletor, raiz = document) => Array.from(raiz.querySelectorAll(seletor));

    /* ------------------------------------------------------------------
       Abas das telas
    ------------------------------------------------------------------ */
    const abas = $$('.appAba');
    const imagens = $$('.appTelaImg');
    const painel = $('#appTelaPainel');
    const legenda = $('#appLegenda');

    function ativarAba(aba, { focar = false } = {}) {
        const tela = aba.dataset.tela;

        abas.forEach((item) => {
            const ativa = item === aba;
            item.classList.toggle('is-ativa', ativa);
            item.setAttribute('aria-selected', ativa ? 'true' : 'false');
            item.tabIndex = ativa ? 0 : -1;
        });

        imagens.forEach((img) => {
            const ativa = img.dataset.tela === tela;
            img.classList.toggle('is-ativa', ativa);
            img.setAttribute('aria-hidden', ativa ? 'false' : 'true');
        });

        if (painel) painel.setAttribute('aria-labelledby', aba.id);
        if (legenda && aba.dataset.legenda) legenda.textContent = aba.dataset.legenda;

        if (focar) aba.focus();

        // Em telas estreitas as abas rolam na horizontal: mantém a ativa à vista.
        if (aba.scrollIntoView && aba.parentElement && aba.parentElement.scrollWidth > aba.parentElement.clientWidth) {
            aba.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
        }
    }

    abas.forEach((aba, indice) => {
        aba.addEventListener('click', () => ativarAba(aba));

        aba.addEventListener('keydown', (evento) => {
            const passo = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[evento.key];
            let destino = null;

            if (passo) {
                destino = abas[(indice + passo + abas.length) % abas.length];
            } else if (evento.key === 'Home') {
                destino = abas[0];
            } else if (evento.key === 'End') {
                destino = abas[abas.length - 1];
            }

            if (destino) {
                evento.preventDefault();
                ativarAba(destino, { focar: true });
            }
        });
    });

    /* ------------------------------------------------------------------
       QR code (qrcode-generator, MIT) — aponta para /app/baixar do próprio site
    ------------------------------------------------------------------ */
    const qrBloco = $('#appQrBloco');
    const qrAlvo = $('#appQr');

    if (qrBloco && qrAlvo && typeof window.qrcode === 'function') {
        try {
            const endereco = new URL('/app/baixar', window.location.origin).href;
            const qr = window.qrcode(0, 'M');
            qr.addData(endereco);
            qr.make();
            qrAlvo.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });

            const svg = qrAlvo.querySelector('svg');
            if (svg) svg.setAttribute('aria-hidden', 'true');

            qrBloco.hidden = false;
        } catch (erro) {
            // Sem QR: o botão de download continua funcionando.
            qrBloco.hidden = true;
        }
    }

    /* ------------------------------------------------------------------
       Copiar SHA-256
    ------------------------------------------------------------------ */
    async function copiarTexto(texto) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(texto);
            return;
        }
        // Fallback (http fora de localhost, navegadores antigos)
        const campo = document.createElement('textarea');
        campo.value = texto;
        campo.setAttribute('readonly', '');
        campo.style.cssText = 'position:fixed;top:-1000px;opacity:0';
        document.body.appendChild(campo);
        campo.select();
        try {
            if (!document.execCommand('copy')) throw new Error('copy');
        } finally {
            document.body.removeChild(campo);
        }
    }

    $$('[data-copiar]').forEach((botao) => {
        const rotulo = $('span', botao);
        const icone = $('i', botao);
        const textoOriginal = rotulo ? rotulo.textContent : '';
        const iconeOriginal = icone ? icone.className : '';
        let temporizador;

        botao.addEventListener('click', async () => {
            const alvo = $(botao.dataset.copiar);
            if (!alvo) return;

            let ok = true;
            try {
                await copiarTexto(alvo.textContent.trim());
            } catch (erro) {
                ok = false;
            }

            clearTimeout(temporizador);
            botao.classList.toggle('is-copiado', ok);
            if (rotulo) rotulo.textContent = ok ? 'Copiado!' : 'Selecione e copie';
            if (icone) icone.className = ok ? 'bi bi-check2' : iconeOriginal;

            if (!ok) {
                // Deixa o código selecionado para o usuário copiar manualmente.
                const selecao = window.getSelection();
                const faixa = document.createRange();
                faixa.selectNodeContents(alvo);
                selecao.removeAllRanges();
                selecao.addRange(faixa);
            }

            temporizador = setTimeout(() => {
                botao.classList.remove('is-copiado');
                if (rotulo) rotulo.textContent = textoOriginal;
                if (icone) icone.className = iconeOriginal;
            }, 2200);
        });
    });

    /* ------------------------------------------------------------------
       Plataforma: aviso no iPhone/iPad; no Android o QR não faz sentido
    ------------------------------------------------------------------ */
    const agente = navigator.userAgent || '';
    const ehIos = /iPhone|iPad|iPod/i.test(agente) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const ehAndroid = /Android/i.test(agente);

    document.documentElement.classList.toggle('is-android', ehAndroid);
    document.documentElement.classList.toggle('is-ios', ehIos);

    const avisoIos = $('#appAvisoIos');
    if (avisoIos) avisoIos.hidden = !ehIos;
})();
