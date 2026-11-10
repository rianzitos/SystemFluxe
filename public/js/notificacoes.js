// =========================================================
// SINO DE NOTIFICAÇÕES — cabeçalho de todas as telas internas
// Ao clicar, abre o painel e busca os alertas em GET /notificacoes (mesma regra do painel,
// respeitando as preferências de Configurações → Notificações). O número do sino é
// atualizado junto e também de minuto em minuto, sem recarregar a página.
// =========================================================

(function () {
    const botao = document.getElementById('btnNotificacoes');
    const painel = document.getElementById('painelNotificacoes');
    const lista = document.getElementById('listaNotificacoes');
    if (!botao || !painel || !lista) return;

    const ICONES = {
        aviso: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill',
        sucesso: 'bi-graph-up-arrow',
    };
    const INTERVALO_MS = 60000;

    let pedido = 0;   // descarta respostas antigas se duas buscas se cruzarem

    function aberto() {
        return !painel.hidden;
    }

    /** No celular o painel ocupa a largura da tela (CSS); aqui ele desce até ficar logo abaixo do sino. */
    function posicionar() {
        painel.style.top = window.matchMedia('(max-width: 560px)').matches
            ? `${Math.round(botao.getBoundingClientRect().bottom + 10)}px`
            : '';
    }

    function abrir() {
        painel.hidden = false;
        posicionar();
        botao.setAttribute('aria-expanded', 'true');
        carregar(true);
    }

    function fechar() {
        painel.hidden = true;
        botao.setAttribute('aria-expanded', 'false');
    }

    /** Número vermelho/amarelo sobre o sino: some quando não há alertas. */
    function atualizarSelo(total) {
        let selo = botao.querySelector('.badge-sino');
        if (total > 0) {
            if (!selo) {
                selo = document.createElement('span');
                selo.className = 'badge-sino';
                botao.appendChild(selo);
            }
            selo.textContent = total;
        } else if (selo) {
            selo.remove();
        }
        botao.setAttribute('aria-label', total > 0 ? `Notificações (${total})` : 'Notificações');
    }

    function mensagem(texto) {
        const p = document.createElement('p');
        p.className = 'painel-notificacoes-vazio';
        p.textContent = texto;
        lista.replaceChildren(p);
    }

    /** Só aceita destinos dentro do próprio site (começam com uma única "/"). */
    function destinoSeguro(link) {
        return typeof link === 'string' && link.startsWith('/') && !link.startsWith('//') ? link : '/assistente';
    }

    function desenhar(alertas) {
        if (!alertas.length) {
            mensagem('Nenhum alerta no momento. Tudo dentro do esperado.');
            return;
        }

        const itens = alertas.map((alerta) => {
            const tipo = ICONES[alerta.tipo] ? alerta.tipo : 'info';

            const item = document.createElement('a');
            item.className = `notificacao notificacao-${tipo}`;
            item.href = destinoSeguro(alerta.link);

            const icone = document.createElement('span');
            icone.className = 'notificacao-icone';
            const i = document.createElement('i');
            i.className = `bi ${ICONES[tipo]}`;
            i.setAttribute('aria-hidden', 'true');
            icone.appendChild(i);

            const texto = document.createElement('span');
            texto.className = 'notificacao-texto';
            const titulo = document.createElement('strong');
            titulo.textContent = alerta.titulo;
            const descricao = document.createElement('span');
            descricao.textContent = alerta.descricao;
            texto.append(titulo, descricao);

            item.append(icone, texto);
            return item;
        });

        lista.replaceChildren(...itens);
    }

    async function carregar(mostrarCarregando) {
        const meu = ++pedido;
        if (mostrarCarregando && !lista.querySelector('.notificacao')) mensagem('Carregando alertas…');

        try {
            const resposta = await fetch('/notificacoes', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (resposta.status === 401) {
                window.location.href = '/login';   // sessão encerrada
                return;
            }
            const dados = await resposta.json();
            if (meu !== pedido) return;
            if (!resposta.ok || !dados.sucesso) throw new Error(dados.mensagem || 'Falha ao carregar');

            atualizarSelo(dados.total);
            if (aberto()) desenhar(dados.alertas);
        } catch (erro) {
            if (meu === pedido && aberto()) mensagem('Não foi possível carregar os alertas agora. Tente de novo em instantes.');
        }
    }

    botao.addEventListener('click', (evento) => {
        evento.stopPropagation();
        aberto() ? fechar() : abrir();
    });

    document.addEventListener('click', (evento) => {
        if (aberto() && !painel.contains(evento.target) && !botao.contains(evento.target)) fechar();
    });

    document.addEventListener('keydown', (evento) => {
        if (evento.key === 'Escape' && aberto()) {
            fechar();
            botao.focus();
        }
    });

    setInterval(() => {
        if (!document.hidden) carregar(false);
    }, INTERVALO_MS);
})();
