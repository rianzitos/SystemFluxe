/* =====================================================================
   APLICATIVO — comportamento das seções do app na página /sicapda
   • Abas das telas (setas, Home/End, troca suave da imagem do celular)
   O botão de download e o aviso para iPhone/Mac/Linux são montados no servidor (PHP),
   pelo aparelho de quem abriu a página; aqui não há nada a fazer para eles.
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
})();
