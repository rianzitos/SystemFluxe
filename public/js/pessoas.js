// =========================================================
// PESSOAS — interações da página
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    definirDataHoje();
    montarMenuMobile();
    montarBusca();
});

/** Preenche "quarta-feira, 26 de agosto de 2026" no subtítulo do cabeçalho. */
function definirDataHoje() {
    const label = document.getElementById('dataHoje');
    if (!label) return;

    const hoje = new Date();
    const texto = hoje.toLocaleDateString('pt-BR', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
    label.textContent = texto.charAt(0).toUpperCase() + texto.slice(1);
}

/** Abre/fecha a sidebar no mobile. */
function montarMenuMobile() {
    const botao = document.getElementById('btnMenuMobile');
    const sidebar = document.getElementById('sidebar');
    if (!botao || !sidebar) return;

    botao.addEventListener('click', () => sidebar.classList.toggle('aberta'));

    document.addEventListener('click', (evento) => {
        const cliqueForaDoMenu = !sidebar.contains(evento.target) && !botao.contains(evento.target);
        if (cliqueForaDoMenu) sidebar.classList.remove('aberta');
    });
}

/**
 * Filtra as pessoas listadas por nome ou função, em tempo real.
 * Esconde o grupo inteiro se nenhuma pessoa dele bater com a busca,
 * e esconde o rodapé "+N outros..." enquanto a busca estiver ativa
 * (já que ele conta os que não estão sendo exibidos).
 */
function montarBusca() {
    const input = document.getElementById('buscaPessoas');
    const grupos = document.querySelectorAll('.grupo-pessoas');
    if (!input || !grupos.length) return;

    input.addEventListener('input', () => {
        const termo = input.value.trim().toLowerCase();

        grupos.forEach((grupo) => {
            let algumVisivel = false;

            grupo.querySelectorAll('.pessoa-item').forEach((item) => {
                const nome = item.dataset.nome || '';
                const funcao = item.dataset.funcao || '';
                const bateComBusca = nome.includes(termo) || funcao.includes(termo);

                item.style.display = bateComBusca ? '' : 'none';
                if (bateComBusca) algumVisivel = true;
            });

            grupo.style.display = (termo === '' || algumVisivel) ? '' : 'none';

            const rodape = grupo.querySelector('.grupo-rodape');
            if (rodape) rodape.style.display = termo === '' ? '' : 'none';
        });
    });
}