/* =========================================================
   VISOR DA CATRACA
   Mostra o que o LCD 16x2 da catraca mostraria, com mais espaço: o primeiro nome de quem passou
   o cartão, se foi ENTRADA ou SAÍDA, o horário e as últimas passagens.

   Duas fontes, o mesmo desenho:
   - Demonstração: os botões do rodapé simulam um cartão (mesmos tempos do firmware).
   - ESP32 no USB: o firmware (iot/esp32_catraca) escreve "VISOR {json}" no Serial a cada tela:
       {"tela":"espera"} · {"tela":"sem_wifi"} · {"tela":"conectando"} · {"tela":"lendo"}
       {"tela":"liberado","nome":"Maria","cargo":"Operadora de Produção","direcao":"entrada"|"saida"}
       {"tela":"negado","motivo":"cartao_desconhecido"|"inativo"|"sem_wifi"|"sem_resposta","mensagem":"...","codigo":401}
     (codigo só em sem_resposta: o HTTP que o site devolveu; <= 0 = nem chegou ao site). O UID nunca vem.
   ========================================================= */

(function () {
    'use strict';

    const V = window.Visor;
    const visor = document.getElementById('visor');
    const conteudo = document.getElementById('conteudo');
    const listaHistorico = document.getElementById('historico');
    const grupoDemo = document.getElementById('grupoDemo');
    const btnCartao = document.getElementById('btnCartao');
    const btnDesconhecido = document.getElementById('btnDesconhecido');

    // Mesmos tempos do firmware: OPEN_TIME_MS e MENSAGEM_NEGADO_MS
    const ABERTA_MS = 4000;
    const NEGADO_MS = 3000;
    const CONSULTA_DEMO_MS = 700;   // o "vai e volta" até o servidor, na demonstração
    const MAX_HISTORICO = 6;

    const NEGADOS = {
        cartao_desconhecido: ['Cartão não cadastrado', 'Procure a administração para cadastrar o cartão.'],
        inativo: ['Acesso desativado', 'Este cartão está desativado. Procure a administração.'],
        sem_wifi: ['Catraca sem internet', 'Sem Wi-Fi a catraca não libera ninguém. Tente de novo em instantes.'],
        sem_resposta: ['O servidor não respondeu', 'Passe o cartão de novo.'],
    };

    // O site recusou o pedido da catraca: diz o motivo pelo código HTTP (o mesmo que o Serial explica).
    function semResposta(codigo, mensagem) {
        if (typeof codigo !== 'number') return NEGADOS.sem_resposta;   // firmware antigo
        if (codigo === 401) return ['Token da catraca recusado', 'O site não reconheceu o token. Copie o token da catraca em Configurações → Integrações e grave em TOKEN_DISPOSITIVO.'];
        if (codigo === 403) return ['Token de outro aparelho', (mensagem ? mensagem + ' ' : '') + 'Use o token de uma catraca (Configurações → Integrações), não o da balança.'];
        if (codigo === 404) return ['Endereço do site errado', 'Confira URL_SERVIDOR no firmware (sem barra no final).'];
        if (codigo <= 0) return ['A catraca não alcançou o site', 'Ela tem Wi-Fi, mas não chegou ao servidor. Confira URL_SERVIDOR e se essa rede tem internet.'];
        if (codigo === 200) return ['Resposta inesperada do site', 'O site respondeu algo que não é JSON. Confira se ele está atualizado.'];
        if (codigo >= 500) return ['Erro no servidor', 'O site deu erro (HTTP ' + codigo + '). Tente de novo; se continuar, veja o log do servidor.'];
        return ['O site recusou o pedido', (mensagem || 'HTTP ' + codigo) + ' (HTTP ' + codigo + ')'];
    }
    const NEGADOS_CURTO = {
        cartao_desconhecido: 'Não cadastrado',
        inativo: 'Desativado',
        sem_wifi: 'Sem Wi-Fi',
        sem_resposta: 'Sem resposta',
    };

    let telaAtual = 'espera';   // o HTML já abre na tela de espera
    let fonte = 'demo';   // 'demo' ou 'usb'

    // ===================== DESENHO =====================

    function trocar(classeEstado, chave, filhos) {
        if (chave === telaAtual) return;   // a ESP32 repete a tela de espera; não redesenha à toa
        telaAtual = chave;
        visor.className = 'visor ' + classeEstado;
        conteudo.replaceChildren(...filhos);
        conteudo.classList.remove('entra');
        void conteudo.offsetWidth;
        conteudo.classList.add('entra');
    }

    function iconeGrande(nome, girando) {
        const caixa = V.el('div', 'catraca-icone');
        caixa.appendChild(V.icone(nome, girando ? 'girando' : ''));
        return caixa;
    }

    function etiqueta(icone, texto) {
        const el = V.el('p', 'etiqueta');
        el.append(V.icone(icone), document.createTextNode(texto));
        return el;
    }

    function linhaHora(rotulo, hora) {
        const el = V.el('p', 'catraca-hora');
        const tempo = V.el('time', '', hora);
        el.append(V.icone('relogio'), document.createTextNode(rotulo + ' às '), tempo);
        return el;
    }

    function telaSimples(classe, chave, icone, titulo, texto, girando) {
        trocar(classe, chave, [
            iconeGrande(icone, girando),
            V.el('h1', 'catraca-titulo', titulo),
            V.el('p', 'catraca-texto', texto),
        ]);
    }

    function mostrar(d) {
        switch (d.tela) {
            case 'espera':
                telaSimples('estado-espera', 'espera', 'cartao', 'Aproxime o cartão', 'Encoste o cartão ou a tag no leitor');
                break;
            case 'sem_wifi':
                telaSimples('estado-aviso', 'sem_wifi', 'wifi-off', 'Sem Wi-Fi', 'A catraca está reconectando. Aguarde um instante.');
                break;
            case 'conectando':
                telaSimples('estado-info', 'conectando', 'wifi', 'Conectando ao Wi-Fi...', 'A catraca está ligando.');
                break;
            case 'lendo':
                telaSimples('estado-info', 'lendo', 'carregando', 'Lendo cartão...', 'Aguarde a confirmação do SICAPDA.', true);
                break;
            case 'liberado':
                mostrarLiberado(String(d.nome || '').trim(), String(d.cargo || '').trim(), d.direcao);
                break;
            case 'negado':
                mostrarNegado(d.motivo, d.mensagem, d.codigo);
                break;
        }
    }

    function mostrarLiberado(nome, cargo, direcao) {
        const hora = V.hora();
        const entrada = direcao === 'entrada';
        const saida = direcao === 'saida';
        const rotulo = entrada ? 'Entrada' : saida ? 'Saída' : 'Passagem';
        const nomeEl = V.el('h1', 'catraca-nome' + (nome.length > 9 ? ' nome-longo' : ''), nome || 'Acesso liberado');

        trocar(saida ? 'estado-saida' : 'estado-ok', 'liberado:' + Date.now(), [
            etiqueta(saida ? 'saida' : 'entrada', entrada ? 'Entrada liberada' : saida ? 'Saída registrada' : 'Acesso liberado'),
            nomeEl,
            ...(cargo ? [V.el('p', 'catraca-cargo', cargo)] : []),
            linhaHora(rotulo, hora),
        ]);
        registrar(saida ? 'saida' : 'entrada', nome || 'Liberado', rotulo, hora);
    }

    function mostrarNegado(motivo, mensagem, codigo) {
        const hora = V.hora();
        const textos = motivo === 'sem_resposta' ? semResposta(codigo, mensagem)
            : NEGADOS[motivo] || ['Acesso negado', mensagem || 'Procure a administração.'];

        trocar('estado-erro', 'negado:' + Date.now(), [
            etiqueta('negado', 'Acesso negado'),
            V.el('h1', 'catraca-titulo', textos[0]),
            V.el('p', 'catraca-texto', textos[1]),
            linhaHora('Tentativa', hora),
        ]);
        const curto = motivo === 'sem_resposta' && typeof codigo === 'number' ? 'Site: HTTP ' + codigo : NEGADOS_CURTO[motivo];
        registrar('negado', 'Negado', curto || 'Acesso negado', hora);
    }

    // ===================== ÚLTIMAS PASSAGENS =====================

    const historico = [];

    function registrar(tipo, titulo, detalhe, hora) {
        historico.unshift({ tipo, titulo, detalhe, hora });
        if (historico.length > MAX_HISTORICO) historico.length = MAX_HISTORICO;
        listaHistorico.replaceChildren(...historico.map((p) => {
            const li = V.el('li', 'passagem passagem-' + p.tipo);
            li.append(
                V.icone(p.tipo === 'negado' ? 'negado' : p.tipo),
                V.el('strong', '', p.titulo),
                V.el('span', '', p.detalhe),
                V.el('time', '', p.hora),
            );
            return li;
        }));
    }

    // ===================== DEMONSTRAÇÃO =====================
    // Cada pessoa entra e depois sai (como o servidor alterna): Maria entra, João entra, Maria sai...

    const CARGOS = {   // as mesmas pessoas do config/dados_exemplo.sql
        Maria: 'Operadora de Produção', João: 'Operador de Produção', Juliana: 'Operadora de Produção',
        Rafael: 'Operador de Produção', Camila: 'Operadora de Máquinas',
    };
    const ROTEIRO = ['Maria', 'João', 'Maria', 'Juliana', 'João', 'Rafael', 'Juliana', 'Camila', 'Rafael', 'Camila'];
    const dentro = new Set();
    let passo = 0;
    let ocupada = false;      // consultando ou com a catraca aberta: a de verdade não lê outro cartão
    let temporizador = null;

    function marcarOcupada(valor) {
        ocupada = valor;
        [btnCartao, btnDesconhecido].forEach((b) => b.setAttribute('aria-disabled', String(valor)));
    }

    function pararDemo() {
        clearTimeout(temporizador);
        marcarOcupada(false);
    }

    function demoPassar(cadastrado) {
        if (fonte !== 'demo' || ocupada) return;
        clearTimeout(temporizador);   // um aviso de negado pode ser interrompido por outro cartão
        marcarOcupada(true);
        mostrar({ tela: 'lendo' });

        temporizador = setTimeout(() => {
            if (cadastrado) {
                const nome = ROTEIRO[passo++ % ROTEIRO.length];
                const direcao = dentro.has(nome) ? 'saida' : 'entrada';
                if (direcao === 'entrada') dentro.add(nome);
                else dentro.delete(nome);
                mostrar({ tela: 'liberado', nome, cargo: CARGOS[nome], direcao });
                temporizador = setTimeout(() => {
                    marcarOcupada(false);
                    mostrar({ tela: 'espera' });
                }, ABERTA_MS);
            } else {
                marcarOcupada(false);
                mostrar({ tela: 'negado', motivo: 'cartao_desconhecido' });
                temporizador = setTimeout(() => mostrar({ tela: 'espera' }), NEGADO_MS);
            }
        }, CONSULTA_DEMO_MS);
    }

    btnCartao.addEventListener('click', () => demoPassar(true));
    btnDesconhecido.addEventListener('click', () => demoPassar(false));

    // ===================== ESP32 NO USB =====================

    const conexao = V.conexaoUsb({
        aoDado: (d) => {
            if (fonte === 'usb' && d && typeof d.tela === 'string') mostrar(d);
        },
        aoEstado: aoMudarConexao,
    });

    const desenharConexao = V.ligarControlesUsb(conexao, {
        chip: document.getElementById('conexao'),
        botao: document.getElementById('btnUsb'),
        nota: document.getElementById('notaUsb'),
    });

    function aoMudarConexao(estado, mensagem) {
        desenharConexao(estado, mensagem);

        if (estado === 'demo') {
            if (fonte === 'usb') {
                fonte = 'demo';
                grupoDemo.hidden = false;
                mostrar({ tela: 'espera' });
            }
            return;
        }

        if (fonte === 'demo') {
            fonte = 'usb';
            pararDemo();
            grupoDemo.hidden = true;
            telaSimples('estado-neutro', 'usb', 'usb', 'ESP32 conectada', 'Esperando a catraca mandar a tela...');
        }
        if (estado === 'perdida') {
            telaSimples('estado-neutro', 'usb-perdida', 'usb', 'ESP32 desconectada', 'Reconecte o cabo USB. A tela volta sozinha.');
        }
    }

    // ===================== INÍCIO =====================

    V.iniciarRelogio(document.getElementById('relogio'));
    V.manterTelaAcesa();
    document.getElementById('btnTelaCheia').addEventListener('click', V.alternarTelaCheia);
    V.atalhos({
        ' ': () => demoPassar(true),
        c: () => demoPassar(true),
        n: () => demoPassar(false),
        f: V.alternarTelaCheia,
    });
    desenharConexao('demo', '');
})();
