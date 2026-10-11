/* ---------------------------------------------------
   Tema claro / escuro da página /sicapda
   Mesma chave ("fluxe-tema") da página inicial: a escolha vale para o site todo.
   O tema inicial é aplicado por um script no <head> (sem "piscar").
   Prioridade: escolha salva > tema do sistema > claro.
   Fica num arquivo separado para funcionar mesmo se algum CDN falhar.
--------------------------------------------------- */
(() => {
    const raiz = document.documentElement;
    const botao = document.getElementById('themeToggle');
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

        if (botao) {
            botao.setAttribute('aria-label', tema === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro');
            botao.setAttribute('aria-pressed', String(tema === 'dark'));
        }
    }

    aplicarTema(raiz.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

    if (botao) {
        botao.addEventListener('click', () => {
            const novoTema = raiz.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';

            // Transição suave de cores só durante a troca (a classe sai depois de ~0,5s)
            raiz.classList.add('tema-animando');
            setTimeout(() => raiz.classList.remove('tema-animando'), 500);

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
})();
