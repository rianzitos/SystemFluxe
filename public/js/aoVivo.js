// =========================================================
// TEMPO REAL — telas internas (Painel, Análise mensal, Pessoas, Relatórios, Configurações...)
//
// A cada poucos segundos pergunta ao servidor (GET /aovivo) se algo mudou na empresa: alguém
// entrou/saiu, uma pesagem chegou, uma pessoa ou cartão foi alterado, um dispositivo caiu...
// Quando muda, busca de novo a própria página e troca só os pedaços marcados com
//   data-ao-vivo="nome"            → o conteúdo do elemento é substituído
//   data-ao-vivo-dados="nome"      → <script type="application/json"> com os dados dos gráficos
// sem recarregar nada: filtros, abas, busca e rolagem continuam como estão. Se a pessoa está
// digitando numa região ou com um diálogo aberto, a troca espera ela terminar.
//
// Eventos para o JS de cada tela:
//   aovivo:estado      → a cada consulta, com o JSON do servidor (status dos dispositivos, pesagens)
//   aovivo:mudou       → a assinatura mudou (o sino de notificações se atualiza)
//   aovivo:atualizado  → regiões já trocadas; detail.dados = {nome: dadosDosGraficos}
// =========================================================

(function () {
    const cabecalho = document.querySelector('.cabecalho-acoes');
    if (!cabecalho) return;

    const INTERVALO_MS = 5000;
    const INTERVALO_MAX_MS = 30000;
    const AVISO_MS = 7000;
    const MAX_AVISOS = 4;

    const temRegioes = () => document.querySelector('[data-ao-vivo], [data-ao-vivo-dados]') !== null;

    let assinaturaAplicada = null;   // assinatura do que está na tela agora
    let ultimoLog = null;            // último acesso que a tela já conhece
    let pesagens = null;             // {tipo: kg} da consulta anterior
    let dispositivos = null;         // {id: status} da consulta anterior
    let falhas = 0;
    let consultando = false;
    let temporizador = null;
    const htmlAplicado = {};         // última versão (vinda do servidor) de cada região

    // ===================== INDICADOR "AO VIVO" =====================

    const indicador = document.createElement('span');
    indicador.className = 'ao-vivo';
    indicador.setAttribute('role', 'status');
    indicador.innerHTML = '<i class="ao-vivo-ponto" aria-hidden="true"></i><span class="ao-vivo-texto">Ao vivo</span>';
    cabecalho.prepend(indicador);

    function marcarIndicador(ok) {
        indicador.classList.toggle('ao-vivo-erro', !ok);
        indicador.querySelector('.ao-vivo-texto').textContent = ok ? 'Ao vivo' : 'Reconectando…';
        indicador.title = ok
            ? `Atualizando sozinho · última verificação ${new Date().toLocaleTimeString('pt-BR')}`
            : 'Sem conexão com o servidor. Tentando de novo…';
    }

    // ===================== AVISOS (toasts) =====================

    let caixaAvisos = null;

    function aviso(tipo, icone, titulo, detalhe) {
        if (!caixaAvisos) {
            caixaAvisos = document.createElement('div');
            caixaAvisos.className = 'avisos-vivo';
            caixaAvisos.setAttribute('role', 'status');
            caixaAvisos.setAttribute('aria-live', 'polite');
            document.body.appendChild(caixaAvisos);
        }

        const item = document.createElement('div');
        item.className = `aviso-vivo aviso-vivo-${tipo}`;

        const i = document.createElement('i');
        i.className = `bi ${icone}`;
        i.setAttribute('aria-hidden', 'true');

        const texto = document.createElement('span');
        const forte = document.createElement('strong');
        forte.textContent = titulo;
        texto.appendChild(forte);
        if (detalhe) {
            const pequeno = document.createElement('small');
            pequeno.textContent = detalhe;
            texto.appendChild(pequeno);
        }

        item.append(i, texto);
        caixaAvisos.appendChild(item);
        while (caixaAvisos.children.length > MAX_AVISOS) caixaAvisos.firstChild.remove();

        setTimeout(() => {
            item.classList.add('aviso-vivo-saindo');
            setTimeout(() => item.remove(), 300);
        }, AVISO_MS);
    }

    function avisarEventos(estado) {
        (estado.eventos || []).forEach((e) => {
            if (e.resultado === 'negado') {
                aviso('aviso', 'bi-shield-exclamation', 'Acesso negado',
                    `${e.nome || 'Cartão não cadastrado'} · ${e.hora}`);
            } else if (e.direcao === 'saida') {
                aviso('saida', 'bi-box-arrow-right', `${e.nome || 'Alguém'} saiu`, `Saída às ${e.hora}`);
            } else {
                aviso('entrada', 'bi-box-arrow-in-right', `${e.nome || 'Alguém'} entrou`, `Entrada às ${e.hora}`);
            }
        });

        const restantes = (estado.novosAcessos || 0) - (estado.eventos || []).length;
        if (restantes > 0) aviso('info', 'bi-people', `+ ${restantes} acessos`, 'Registrados agora há pouco');
    }

    function avisarPesagens(estado) {
        const atuais = {};
        (estado.pesagens || []).forEach((p) => {
            atuais[p.tipo] = p.kg;
            if (pesagens !== null && pesagens[p.tipo] !== p.kg) {
                const kg = p.kg.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                aviso('info', 'bi-speedometer2', 'Pesagem recebida', `${p.rotulo}: ${kg} kg de desperdício`);
            }
        });
        pesagens = atuais;
    }

    function avisarDispositivos(estado) {
        const atuais = {};
        (estado.dispositivos || []).forEach((d) => {
            atuais[d.id] = d.status;
            if (dispositivos !== null && dispositivos[d.id] && dispositivos[d.id] !== d.status) {
                if (d.status === 'online') aviso('entrada', 'bi-wifi', `${d.nome} voltou`, 'Dispositivo online');
                else aviso('aviso', 'bi-wifi-off', `${d.nome} sem sinal`, 'Dispositivo offline');
            }
        });
        dispositivos = atuais;
    }

    // ===================== TROCA DAS REGIÕES =====================

    /** Alguém está no meio de uma ação (diálogo aberto ou digitando dentro de uma região)? Então espera. */
    function ocupado(regiao) {
        if (document.querySelector('dialog[open]')) return true;
        const foco = document.activeElement;
        return !!(regiao && foco && regiao.contains(foco) && foco.matches('input, select, textarea'));
    }

    /** Busca a página de novo e troca as regiões que mudaram. true = tudo aplicado. */
    async function atualizarTela() {
        if (!temRegioes()) return true;

        const resposta = await fetch(window.location.href, {
            headers: { Accept: 'text/html', 'X-Requested-With': 'aovivo' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (resposta.redirected && new URL(resposta.url).pathname === '/login') {
            window.location.href = '/login';   // a sessão foi encerrada
            return false;
        }
        if (!resposta.ok) throw new Error(`HTTP ${resposta.status}`);

        const novo = new DOMParser().parseFromString(await resposta.text(), 'text/html');
        let completo = true;
        let mudou = false;

        document.querySelectorAll('[data-ao-vivo]').forEach((regiao) => {
            const nome = regiao.dataset.aoVivo;
            const origem = novo.querySelector(`[data-ao-vivo="${nome}"]`);
            if (!origem) return;

            const html = origem.innerHTML;
            if (htmlAplicado[nome] === undefined) {
                // Primeira vez: o que está na tela veio do mesmo servidor, então só compara depois.
                htmlAplicado[nome] = regiao.innerHTML === html ? html : null;
            }
            if (htmlAplicado[nome] === html) return;

            if (ocupado(regiao)) {
                completo = false;
                return;
            }
            regiao.innerHTML = html;
            htmlAplicado[nome] = html;
            mudou = true;
        });

        const dados = {};
        novo.querySelectorAll('script[data-ao-vivo-dados]').forEach((s) => {
            try { dados[s.dataset.aoVivoDados] = JSON.parse(s.textContent); } catch (erro) { /* dado inválido: ignora */ }
        });

        if (mudou || Object.keys(dados).length) {
            document.dispatchEvent(new CustomEvent('aovivo:atualizado', { detail: { dados, documento: novo } }));
        }
        return completo;
    }

    // ===================== CONSULTA PERIÓDICA =====================

    function agendar() {
        clearTimeout(temporizador);
        const espera = Math.min(INTERVALO_MAX_MS, INTERVALO_MS * 2 ** Math.min(falhas, 3));
        temporizador = setTimeout(consultar, espera);
    }

    async function consultar() {
        clearTimeout(temporizador);
        if (document.hidden || consultando) {
            agendar();
            return;
        }

        consultando = true;
        try {
            const url = '/aovivo' + (ultimoLog !== null ? `?ultimo=${ultimoLog}` : '');
            const resposta = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
            if (resposta.status === 401) {
                window.location.href = '/login';
                return;
            }
            const estado = await resposta.json();
            if (!resposta.ok || !estado.sucesso) throw new Error(estado.mensagem || 'Falha');

            falhas = 0;
            marcarIndicador(true);

            avisarEventos(estado);
            avisarPesagens(estado);
            avisarDispositivos(estado);
            ultimoLog = estado.ultimoLog;
            document.dispatchEvent(new CustomEvent('aovivo:estado', { detail: estado }));

            if (assinaturaAplicada === null) {
                assinaturaAplicada = estado.assinatura;   // primeira consulta: a tela já está atualizada
            } else if (estado.assinatura !== assinaturaAplicada) {
                document.dispatchEvent(new CustomEvent('aovivo:mudou', { detail: estado }));
                if (await atualizarTela()) assinaturaAplicada = estado.assinatura;
            }
        } catch (erro) {
            falhas++;
            marcarIndicador(false);
        } finally {
            consultando = false;
            agendar();
        }
    }

    // Ao voltar para a aba, confere na hora em vez de esperar o próximo ciclo.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) consultar();
    });

    consultar();
})();
