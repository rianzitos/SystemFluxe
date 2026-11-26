/* =========================================================
   VISOR DA BALANÇA
   Mostra o que o LCD 16x2 da balança mostraria, com mais espaço: o peso em tempo real, a refeição
   escolhida no botão AMARELO, o total de hoje e a confirmação de que a pesagem chegou ao painel.

   Duas fontes, o mesmo desenho:
   - Demonstração: o controle "Peso no prato" e os botões REFEIÇÃO / TARA / ENVIAR imitam a balança
     (mesmas mensagens e tempos do firmware).
   - ESP32 no USB: o firmware (iot/esp32_balanca) escreve no Serial, a cada mudança e a cada 2 s:
       VISOR {"l0":"Almoco Tot  0.45","l1":"Peso:   0.120 kg","ref":"almoco","peso":0.120,
              "parado":true,"total":0.45,"fila":0,"wifi":true,"modo":0}
     l0 e l1 são as duas linhas do LCD; peso é null quando não vale (sem tara, sensor sem sinal...);
     modo 1 e 2 são os passos da calibração.
   ========================================================= */

(function () {
    'use strict';

    const V = window.Visor;
    const $ = (id) => document.getElementById(id);

    const visor = $('visor');
    const listaRefeicoes = $('refeicoes');
    const peso = $('peso');
    const pesoValor = $('pesoValor');
    const estabilidade = $('estabilidade');
    const mensagem = $('mensagem');
    const totalRotulo = $('totalRotulo');
    const total = $('total');
    const ultimoEnvio = $('ultimoEnvio');
    const rede = $('rede');
    const grupoDemo = $('grupoDemo');

    let fonte = 'demo';   // 'demo' ou 'usb'

    // Mesma ordem do firmware (TIPOS_REFEICAO): o botão amarelo avança almoço → jantar → ceia → café
    const REFEICOES = ['cafe', 'almoco', 'jantar', 'ceia'];
    const NOMES = { cafe: 'Café', almoco: 'Almoço', jantar: 'Jantar', ceia: 'Ceia' };
    const NOMES_LCD = { cafe: 'Cafe', almoco: 'Almoco', jantar: 'Jantar', ceia: 'Ceia' };
    const DO = { cafe: 'do café', almoco: 'do almoço', jantar: 'do jantar', ceia: 'da ceia' };
    const NO = { cafe: 'no café', almoco: 'no almoço', jantar: 'no jantar', ceia: 'na ceia' };

    const kg3 = (n) => n.toLocaleString('pt-BR', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    const kg2 = (n) => n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    // ===================== O QUE CADA AVISO DO LCD QUER DIZER =====================
    // [tipo, ícone, título, explicação, girando]. O LCD não tem acento nem espaço; aqui tem.

    const MENSAGENS = {
        'Enviando...': ['info', 'carregando', 'Enviando ao SICAPDA...', 'Aguarde a confirmação do painel.', true],
        'Estabilizando...': ['info', 'carregando', 'Estabilizando o peso...', 'Não encoste no prato.', true],
        'Tarando...': ['info', 'carregando', 'Zerando a balança...', 'Não encoste no prato.', true],
        'Medindo...': ['info', 'carregando', 'Medindo o peso de calibração...', 'Não encoste no prato.', true],
        'Tara feita': ['ok', 'ok', 'Balança zerada', 'Pronta para pesar.'],
        'Calibrado!': ['ok', 'ok', 'Balança calibrada', 'Retire o peso e aperte TARA.'],
        'Cancelado': ['info', 'info', 'Cancelado', ''],
        'Prato vazio': ['aviso', 'alerta', 'Prato vazio', 'Coloque as sobras no prato antes de apertar ENVIAR.'],
        'Peso instavel': ['aviso', 'alerta', 'Peso instável', 'Não encoste no prato e aperte ENVIAR de novo.'],
        'Instavel: tente': ['aviso', 'alerta', 'Peso instável', 'Não encoste no prato e tente de novo.'],
        'Aperte TARA': ['aviso', 'zerar', 'Aperte TARA', 'Com o prato vazio, para zerar a balança.'],
        'Primeiro: TARA': ['aviso', 'zerar', 'Primeiro aperte TARA', 'Com o prato vazio.'],
        'Faca a TARA': ['aviso', 'zerar', 'Primeiro aperte TARA', 'Com o prato vazio.'],
        'Calibre: TARA 4s': ['aviso', 'alerta', 'Balança sem calibração', 'Segure TARA por 4 segundos para calibrar.'],
        'Sem hora: rede?': ['aviso', 'wifi-off', 'Sem data e hora', 'A balança precisa falar com o site uma vez. Confira o Wi-Fi.'],
        'Fila cheia: rede': ['erro', 'wifi-off', 'Fila de envios cheia', 'Conecte a balança à internet antes de pesar de novo.'],
        'Sensor sem sinal': ['erro', 'alerta', 'Sensor sem sinal', 'Confira a ligação do HX711.'],
        'Sensor: ligacao': ['erro', 'alerta', 'Sensor no limite', 'Confira os fios da célula de carga.'],
        'SOBRECARGA!': ['erro', 'alerta', 'Sobrecarga', 'Acima da capacidade da balança. Divida em mais pesagens.'],
        'Acima capacidade': ['erro', 'alerta', 'Acima da capacidade', 'Divida em mais pesagens.'],
        'Falha ao salvar': ['erro', 'alerta', 'Falha ao salvar na memória', 'Tente de novo.'],
        'Erro: sem peso?': ['erro', 'alerta', 'A calibração falhou', 'Coloque o peso de calibração no meio do prato.'],
    };

    function interpretar(l0, l1, d) {
        const ref = d.ref;
        if (d.modo === 1) {
            const m = MENSAGENS[l1];
            return m && l1 !== 'Esvazie + TARA'
                ? { tipo: m[0], icone: m[1], titulo: m[2], texto: m[3], girando: m[4] }
                : { tipo: 'info', icone: 'zerar', titulo: 'Calibração · passo 1 de 2', texto: 'Esvazie o prato e aperte TARA. O botão amarelo cancela.' };
        }
        if (d.modo === 2) {
            const m = MENSAGENS[l1];
            const peso = l0.replace(/^Ponha\s*/, '').replace('.', ',');
            return m && l1 !== 'e aperte ENVIAR'
                ? { tipo: m[0], icone: m[1], titulo: m[2], texto: m[3], girando: m[4] }
                : { tipo: 'info', icone: 'zerar', titulo: 'Calibração · passo 2 de 2', texto: 'Coloque ' + peso + ' no meio do prato e aperte ENVIAR.' };
        }
        if (l0 === 'Primeira tara') {
            return { tipo: 'info', icone: 'zerar', titulo: 'Primeira tara', texto: 'Deixe o prato vazio.' };
        }
        if (l0 === 'SICAPDA Balanca') {
            return { tipo: 'info', icone: 'carregando', girando: true, titulo: 'Ligando a balança...',
                texto: l1 === 'Conectando WiFi' ? 'Conectando ao Wi-Fi.' : 'Iniciando o sensor.' };
        }

        let m = /^Enviado \+([\d.]+)kg$/.exec(l1);
        if (m) {
            return { tipo: 'ok', icone: 'nuvem', titulo: 'Enviado ao painel do SICAPDA',
                texto: '+' + kg2(parseFloat(m[1])) + ' kg ' + (NO[ref] || ''), enviado: parseFloat(m[1]) };
        }
        m = /^Na fila \+([\d.]+)kg$/.exec(l1);
        if (m) {
            return { tipo: 'aviso', icone: 'relogio', titulo: 'Pesagem guardada na fila',
                texto: '+' + kg2(parseFloat(m[1])) + ' kg. Sem resposta do site: a balança reenvia sozinha.' };
        }
        if (MENSAGENS[l1]) {
            const x = MENSAGENS[l1];
            return { tipo: x[0], icone: x[1], titulo: x[2], texto: x[3], girando: x[4] };
        }
        if (l1 === '' || l1.startsWith('Peso:')) {
            return { tipo: 'neutro', icone: 'info', titulo: 'Coloque o prato e aperte ENVIAR',
                texto: 'O peso entra no total de hoje da refeição escolhida.' };
        }
        return { tipo: 'info', icone: 'info', titulo: l1, texto: '' };   // aviso novo do firmware: mostra como veio
    }

    // ===================== DESENHO =====================

    const CLASSE_VISOR = { neutro: 'estado-espera', ok: 'estado-ok', aviso: 'estado-aviso', erro: 'estado-erro', info: 'estado-info' };

    let refAtual = 'almoco';
    let chaveMensagem = '';
    let enviadoAnterior = false;

    function desenhar(d) {
        const l0 = String(d.l0 || '').trim();
        const l1 = String(d.l1 || '').trim();
        const ref = REFEICOES.includes(d.ref) ? d.ref : refAtual;

        // Refeição (botão amarelo)
        if (ref !== refAtual) {
            refAtual = ref;
            listaRefeicoes.querySelectorAll('.refeicao').forEach((el) => {
                const atual = el.dataset.ref === ref;
                el.setAttribute('aria-current', String(atual));
                el.classList.remove('trocou');
                if (atual) {
                    void el.offsetWidth;
                    el.classList.add('trocou');
                }
            });
        }
        totalRotulo.textContent = 'Total ' + DO[ref] + ' hoje';
        total.textContent = kg2(Number(d.total) || 0) + ' kg';

        // Peso
        const valido = typeof d.peso === 'number' && isFinite(d.peso);
        pesoValor.textContent = valido ? kg3(Math.max(0, d.peso)) : '-,---';
        peso.classList.toggle('sem-peso', !valido);
        estabilidade.hidden = !valido;
        estabilidade.dataset.parado = String(!!d.parado);
        estabilidade.textContent = d.parado ? 'Peso estável' : 'Estabilizando...';

        // Conexão da balança com o site
        const fila = Number(d.fila) || 0;
        const textoFila = fila === 1 ? '1 envio na fila' : fila + ' envios na fila';
        rede.textContent = d.wifi === false ? (fila ? 'Sem Wi-Fi · ' + textoFila : 'Sem Wi-Fi')
            : (fila ? 'Wi-Fi · ' + textoFila : 'Wi-Fi conectado');
        rede.dataset.tipo = d.wifi === false || fila ? 'aviso' : 'ok';

        // Mensagem
        const msg = interpretar(l0, l1, Object.assign({}, d, { ref }));
        const chave = msg.tipo + '|' + msg.titulo + '|' + msg.texto;
        if (chave !== chaveMensagem) {
            chaveMensagem = chave;
            visor.className = 'visor ' + CLASSE_VISOR[msg.tipo];
            mensagem.className = 'mensagem msg-' + msg.tipo + ' entra';
            const texto = V.el('p');
            texto.append(V.el('strong', '', msg.titulo), V.el('span', '', msg.texto));
            mensagem.replaceChildren(V.icone(msg.icone, msg.girando ? 'girando' : ''), texto);
        }

        // Último envio: guarda quando o aviso "Enviado" aparece (e não a cada repetição dele)
        const enviado = msg.enviado !== undefined;
        if (enviado && !enviadoAnterior) {
            ultimoEnvio.textContent = '+' + kg2(msg.enviado) + ' kg · ' + NOMES[ref] + ' · ' + V.hora();
        }
        enviadoAnterior = enviado;
    }

    // ===================== DEMONSTRAÇÃO =====================
    // Uma balança de mentira com as mesmas regras do firmware: degrau de 5 g, peso nunca negativo,
    // ENVIAR espera o peso parar, soma ao total da refeição de hoje e mostra "Enviado +x.xxkg".

    const DIVISAO_KG = 0.005;
    const ENVIO_MINIMO_KG = 0.010;
    const ESPERA_ESTAVEL_MS = 6000;

    const sim = {
        alvo: 0,          // o que está no prato (controle "Peso no prato")
        celula: 0,        // o que a célula lê: vai chegando no alvo, com ruído
        tara: 0,
        divisoes: 0,      // o que o visor mostra, em degraus de 5 g
        paradoDesde: 0,
        ref: 1,           // almoço, como a balança na primeira vez
        totais: [0, 0, 0, 0],
        aviso: '',
        avisoAte: 0,
        ocupada: false,   // tarando ou enviando: os botões esperam (a balança de verdade também)
    };
    let laco = null;

    const agora = () => Date.now();
    const estavel = () => sim.paradoDesde && agora() - sim.paradoDesde >= 900;
    const pesoLiquido = () => sim.celula - sim.tara;

    function avisoDemo(texto, ms) {
        sim.aviso = texto;
        sim.avisoAte = ms ? agora() + ms : Infinity;
    }

    function passoDemo() {
        const falta = sim.alvo - sim.celula;
        const mexendo = Math.abs(falta) > 0.002;
        sim.celula += falta * 0.35 + (Math.random() - 0.5) * (mexendo ? 0.006 : 0.0008);
        if (mexendo) sim.paradoDesde = 0;
        else if (!sim.paradoDesde) sim.paradoDesde = agora();

        // degrau de 5 g com folga (o número não pisca entre dois valores), como divisoesParaMostrar
        const liquido = Math.max(0, pesoLiquido());
        const alvoDivisoes = liquido / DIVISAO_KG;
        if (Math.abs(alvoDivisoes - sim.divisoes) > 0.7) sim.divisoes = Math.round(alvoDivisoes);

        if (sim.aviso && agora() >= sim.avisoAte) sim.aviso = '';
        const ref = REFEICOES[sim.ref];
        const totalKg = sim.totais[sim.ref];
        const pesoKg = sim.divisoes * DIVISAO_KG;
        desenhar({
            l0: (NOMES_LCD[ref] + '      ').slice(0, 6) + ' Tot' + totalKg.toFixed(2).padStart(6),
            l1: sim.aviso || 'Peso:' + pesoKg.toFixed(3).padStart(8) + ' kg',
            ref,
            peso: pesoKg,
            parado: !!estavel(),
            total: totalKg,
            fila: 0,
            wifi: true,
            modo: 0,
        });
    }

    function esperarEstavel() {
        const inicio = agora();
        return new Promise((resolver) => {
            (function conferir() {
                if (fonte !== 'demo') return resolver(false);
                if (estavel() && agora() - inicio >= 900) return resolver(true);   // só leituras depois do clique
                if (agora() - inicio >= ESPERA_ESTAVEL_MS) return resolver(false);
                setTimeout(conferir, 100);
            })();
        });
    }

    const pausa = (ms) => new Promise((r) => setTimeout(r, ms));

    function marcarOcupada(valor) {
        sim.ocupada = valor;
        ['btnRefeicao', 'btnTara', 'btnEnviar'].forEach((id) => $(id).setAttribute('aria-disabled', String(valor)));
    }

    function demoRefeicao() {
        if (fonte !== 'demo' || sim.ocupada) return;
        sim.ref = (sim.ref + 1) % REFEICOES.length;
    }

    async function demoTara() {
        if (fonte !== 'demo' || sim.ocupada) return;
        marcarOcupada(true);
        avisoDemo('Tarando...');
        if (await esperarEstavel()) {
            sim.tara = sim.celula;
            sim.divisoes = 0;
            avisoDemo('Tara feita', 1500);
        } else {
            avisoDemo('Instavel: tente', 2500);
        }
        marcarOcupada(false);
    }

    async function demoEnviar() {
        if (fonte !== 'demo' || sim.ocupada) return;
        marcarOcupada(true);
        avisoDemo('Estabilizando...');
        if (!(await esperarEstavel())) {
            avisoDemo('Peso instavel', 2500);
            marcarOcupada(false);
            return;
        }
        const kg = pesoLiquido();
        if (kg < ENVIO_MINIMO_KG) {
            avisoDemo('Prato vazio', 2000);
            marcarOcupada(false);
            return;
        }
        const gramas = Math.round(kg * 100) * 10;   // o site guarda centésimos de kg (10 g)
        avisoDemo('Enviando...');
        await pausa(700);
        sim.totais[sim.ref] = Math.round((sim.totais[sim.ref] * 1000 + gramas)) / 1000;
        avisoDemo('Enviado +' + (gramas / 1000).toFixed(2) + 'kg', 3000);
        marcarOcupada(false);
    }

    function iniciarDemo() {
        clearInterval(laco);
        laco = setInterval(passoDemo, 100);
        passoDemo();
    }

    function pararDemo() {
        clearInterval(laco);
        laco = null;
        marcarOcupada(false);
    }

    // Controle "Peso no prato"
    const pesoSim = $('pesoSim');
    const pesoSimValor = $('pesoSimValor');
    function lerControle() {
        sim.alvo = parseFloat(pesoSim.value) || 0;
        pesoSimValor.textContent = kg3(sim.alvo) + ' kg';
    }
    pesoSim.addEventListener('input', lerControle);
    lerControle();

    $('btnRefeicao').addEventListener('click', demoRefeicao);
    $('btnTara').addEventListener('click', demoTara);
    $('btnEnviar').addEventListener('click', demoEnviar);

    // ===================== ESP32 NO USB =====================

    const conexao = V.conexaoUsb({
        aoDado: (d) => {
            if (fonte === 'usb' && d && typeof d === 'object' && 'l1' in d) desenhar(d);
        },
        aoEstado: aoMudarConexao,
    });

    const desenharConexao = V.ligarControlesUsb(conexao, {
        chip: $('conexao'),
        botao: $('btnUsb'),
        nota: $('notaUsb'),
    });

    function telaSemDados(titulo, texto) {
        chaveMensagem = '';
        visor.className = 'visor estado-neutro';
        pesoValor.textContent = '-,---';
        peso.classList.add('sem-peso');
        estabilidade.hidden = true;
        mensagem.className = 'mensagem msg-neutro entra';
        const p = V.el('p');
        p.append(V.el('strong', '', titulo), V.el('span', '', texto));
        mensagem.replaceChildren(V.icone('usb'), p);
    }

    function aoMudarConexao(estado, nota) {
        desenharConexao(estado, nota);

        if (estado === 'demo') {
            if (fonte === 'usb') {
                fonte = 'demo';
                grupoDemo.hidden = false;
                chaveMensagem = '';
                iniciarDemo();
            }
            return;
        }

        if (fonte === 'demo') {
            fonte = 'usb';
            pararDemo();
            grupoDemo.hidden = true;
            ultimoEnvio.textContent = 'Nenhum ainda';
            telaSemDados('ESP32 conectada', 'Esperando a balança mandar o peso...');
        }
        if (estado === 'perdida') {
            telaSemDados('ESP32 desconectada', 'Reconecte o cabo USB. A tela volta sozinha.');
        }
    }

    // ===================== INÍCIO =====================

    V.iniciarRelogio($('relogio'));
    V.manterTelaAcesa();
    $('btnTelaCheia').addEventListener('click', V.alternarTelaCheia);
    V.atalhos({
        r: demoRefeicao,
        t: demoTara,
        e: demoEnviar,
        f: V.alternarTelaCheia,
    });
    desenharConexao('demo', '');
    iniciarDemo();
    conexao.retomar();   // a ESP32 já autorizada antes conecta sozinha
})();
