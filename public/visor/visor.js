/* =========================================================
   VISOR WEB — o que a catraca e a balança têm em comum
   - relógio do topo
   - conexão com a ESP32 pelo cabo USB (Web Serial, Chrome ou Edge no computador):
     o firmware escreve no Serial linhas "VISOR {json}" e a página desenha o que o LCD mostraria
   - tela cheia e tela sempre acesa (para deixar o computador ao lado do aparelho)
   Script comum (sem módulos) para funcionar também abrindo o arquivo direto.
   ========================================================= */

(function () {
    'use strict';

    const NS = 'http://www.w3.org/2000/svg';
    const Visor = {};

    // ===================== ÍCONES (desenhos do Lucide, licença ISC) =====================
    // Ficam aqui uma vez só e entram na página como <symbol>; o HTML usa <svg><use href="#i-nome">.

    const ICONES = {
        cartao: '<path d="M6 8.32a7.43 7.43 0 0 1 0 7.36"/><path d="M9.46 6.21a11.76 11.76 0 0 1 0 11.58"/><path d="M12.91 4.1a15.91 15.91 0 0 1 .01 15.8"/><path d="M16.37 2a20.16 20.16 0 0 1 0 20"/>',
        entrada: '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5"/><path d="M15 12H3"/>',
        saida: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        negado: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        ok: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        alerta: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        carregando: '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
        wifi: '<path d="M12 20h.01"/><path d="M2 8.82a15 15 0 0 1 20 0"/><path d="M5 12.86a10 10 0 0 1 14 0"/><path d="M8.5 16.43a5 5 0 0 1 7 0"/>',
        'wifi-off': '<path d="M12 20h.01"/><path d="M8.5 16.43a5 5 0 0 1 7 0"/><path d="M5 12.86a10 10 0 0 1 5.17-2.69"/><path d="M19 12.86a10 10 0 0 0-2.007-1.523"/><path d="M2 8.82a15 15 0 0 1 4.177-2.643"/><path d="M22 8.82a15 15 0 0 0-11.288-3.764"/><path d="m2 2 20 20"/>',
        usb: '<circle cx="10" cy="7" r="1"/><circle cx="4" cy="20" r="1"/><path d="M4.7 19.3 19 5"/><path d="m21 3-3 1 2 2Z"/><path d="M9.26 7.68 5 12l2 5"/><path d="m10 14 5 2 3.5-3.5"/><path d="m18 12 1-1 1 1-1 1Z"/>',
        play: '<path d="M6 3l14 9-14 9V3z"/>',
        'tela-cheia': '<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>',
        nuvem: '<path d="M12 13v8"/><path d="M4 14.899A7 7 0 1 1 15.71 8h1.79a4.5 4.5 0 0 1 2.5 8.242"/><path d="m8 17 4-4 4 4"/>',
        relogio: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        zerar: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        refeicao: '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
        enviar: '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    };

    (function incluirIcones() {
        const sprite = document.createElementNS(NS, 'svg');
        sprite.setAttribute('aria-hidden', 'true');
        sprite.style.display = 'none';
        sprite.innerHTML = Object.keys(ICONES)
            .map((nome) => '<symbol id="i-' + nome + '" viewBox="0 0 24 24">' + ICONES[nome] + '</symbol>')
            .join('');
        document.body.prepend(sprite);
    })();

    // ===================== DOM =====================

    Visor.icone = function (nome, classe) {
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('class', 'icone' + (classe ? ' ' + classe : ''));
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS(NS, 'use');
        use.setAttribute('href', '#i-' + nome);
        svg.appendChild(use);
        return svg;
    };

    Visor.el = function (tag, classe, texto) {
        const el = document.createElement(tag);
        if (classe) el.className = classe;
        if (texto !== undefined && texto !== null) el.textContent = texto;
        return el;
    };

    // ===================== HORA =====================

    Visor.hora = function (data) {
        return (data || new Date()).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    };

    Visor.iniciarRelogio = function (el) {
        function atualizar() {
            const agora = new Date();
            el.textContent = agora.toLocaleTimeString('pt-BR');
            el.dateTime = agora.toISOString();
            setTimeout(atualizar, 1000 - agora.getMilliseconds() + 5);
        }
        atualizar();
    };

    // ===================== TELA CHEIA E TELA ACESA =====================

    Visor.alternarTelaCheia = function () {
        if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
        else document.documentElement.requestFullscreen?.().catch(() => {});
    };

    // O computador ao lado da catraca não deve apagar a tela no meio da apresentação.
    Visor.manterTelaAcesa = function () {
        if (!('wakeLock' in navigator)) return;
        let trava = null;
        async function pedir() {
            if (document.visibilityState !== 'visible' || trava) return;
            try {
                trava = await navigator.wakeLock.request('screen');
                trava.addEventListener('release', () => { trava = null; });
            } catch (e) {
                trava = null;   // bateria fraca, aba em segundo plano... a página segue normal
            }
        }
        document.addEventListener('visibilitychange', pedir);
        pedir();
    };

    // Atalhos de teclado, ignorados só enquanto a pessoa digita num campo de texto
    // (o controle deslizante de peso não conta: depois de mexer nele, E/R/T continuam valendo).
    Visor.atalhos = function (mapa) {
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey || e.altKey || e.repeat) return;
            const alvo = e.target;
            const digitando = alvo && ((alvo.tagName === 'INPUT' && alvo.type !== 'range')
                || alvo.tagName === 'TEXTAREA' || alvo.isContentEditable);
            if (digitando) return;
            const acao = mapa[e.key.toLowerCase()] || mapa[e.code];
            if (!acao) return;
            if (alvo && alvo.tagName === 'BUTTON' && (e.key === ' ' || e.key === 'Enter')) return;   // o botão focado já trata
            e.preventDefault();
            acao();
        });
    };

    // ===================== CONEXÃO USB (Web Serial) =====================
    //
    // estados: 'demo' (sem ESP32), 'conectando', 'aguardando' (porta aberta, sem linhas VISOR ainda),
    //          'ao-vivo', 'perdida' (cabo saiu ou a porta deu erro)

    const SEM_DADOS_MS = 6000;      // porta aberta e nenhuma linha VISOR: firmware antigo ou VISOR_WEB = false
    const SILENCIO_MS = 20000;      // estava ao vivo e parou de chegar (a ESP32 pode travar ~10 s num envio lento)

    function ConexaoUsb(opcoes) {
        this.aoDado = opcoes.aoDado;
        this.aoEstado = opcoes.aoEstado;
        this.porta = null;
        this.leitor = null;
        this.estado = 'demo';
        this.ultimoDado = 0;
        this.bytes = 0;                    // bytes recebidos desde que abriu (0 = a placa está muda)
        this.modoGravacao = false;         // a ROM da ESP32 avisou "waiting for download"
        this.notaSemDados = '';
        this.saidaPedida = false;          // a pessoa escolheu "voltar à demonstração": não reconecta sozinho
        this.vigia = null;
    }

    ConexaoUsb.prototype.suportada = function () {
        return 'serial' in navigator;
    };

    ConexaoUsb.prototype.mudar = function (estado, mensagem) {
        this.estado = estado;
        this.aoEstado(estado, mensagem || '');
    };

    // Botão "Conectar ESP32": o navegador mostra a lista de portas USB.
    ConexaoUsb.prototype.escolher = async function () {
        if (!this.suportada()) return;
        let porta;
        try {
            porta = await navigator.serial.requestPort();
        } catch (e) {
            return;   // a pessoa fechou a lista sem escolher
        }
        this.saidaPedida = false;
        await this.abrir(porta, false);
    };

    // Ao abrir a página (e quando o cabo volta): usa a porta que já foi autorizada antes, sem perguntar.
    ConexaoUsb.prototype.retomar = async function () {
        if (!this.suportada() || this.porta || this.saidaPedida) return;
        let portas = [];
        try {
            portas = await navigator.serial.getPorts();
        } catch (e) {
            return;
        }
        if (portas.length) await this.abrir(portas[0], true);
    };

    ConexaoUsb.prototype.abrir = async function (porta, silencioso) {
        if (this.porta) return;
        this.mudar('conectando');
        try {
            await porta.open({ baudRate: 115200 });
        } catch (e) {
            if (silencioso) {
                this.mudar('demo');
                return;
            }
            const ocupada = e && (e.name === 'NetworkError' || e.name === 'InvalidStateError');
            this.mudar('demo', ocupada
                ? 'A porta está ocupada. Feche o Monitor Serial (Arduino IDE, PlatformIO) e tente de novo.'
                : 'Não consegui abrir a porta USB da ESP32. Tente de novo.');
            return;
        }
        // Reinicia a ESP32 rodando o programa, igual ao "hard reset" do esptool. Na DevKit, DTR e RTS
        // comandam o EN (reset) e o IO0 (modo de gravação):
        //   RTS ligado + DTR desligado → EN baixo: a placa fica em reset, com o IO0 alto
        //   RTS desligado + DTR desligado → solta o EN: ela liga e roda o firmware
        // NUNCA deixar DTR ligado + RTS desligado enquanto ela liga: o IO0 fica baixo e a ESP32 entra no
        // modo de gravação ("waiting for download"), sem ler cartão nem mandar nada ao visor. Abrir a porta
        // já pode reiniciar a placa no meio do caminho; este reset no fim garante que ela ligue certo.
        // A balança perde a hora ao reiniciar, mas acerta de novo com o site em poucos segundos.
        try {
            await porta.setSignals({ dataTerminalReady: false, requestToSend: true });
            await new Promise((r) => setTimeout(r, 120));
            await porta.setSignals({ requestToSend: false });
        } catch (e) { /* nem toda placa aceita; segue */ }

        this.porta = porta;
        this.ultimoDado = 0;
        this.bytes = 0;
        this.modoGravacao = false;
        const aberturaEm = Date.now();
        this.mudar('aguardando');   // a própria tela já diz "ESP32 conectada"
        clearInterval(this.vigia);
        this.vigia = setInterval(() => {
            const agora = Date.now();
            if (!this.ultimoDado && agora - aberturaEm > SEM_DADOS_MS) {
                const nota = this.semDados();
                if (this.estado !== 'aguardando-firmware' || nota !== this.notaSemDados) {
                    this.notaSemDados = nota;
                    this.mudar('aguardando-firmware', nota);
                }
            } else if (this.ultimoDado && agora - this.ultimoDado > SILENCIO_MS && this.estado === 'ao-vivo') {
                this.mudar('aguardando', 'Sem dados da ESP32 há alguns segundos...');
            }
        }, 1000);
        this.ler();
    };

    // Porta aberta e nenhuma linha VISOR: diz o porquê, pelo que chegou (ou não) pelo cabo.
    ConexaoUsb.prototype.semDados = function () {
        if (this.modoGravacao) {
            return 'A ESP32 ligou no modo de gravação e não está rodando o programa. Aperte o botão EN (RST) da placa.';
        }
        if (!this.bytes) {
            return 'A ESP32 não mandou nada pelo USB. Aperte o botão EN (RST) da placa; se continuar, '
                + 'confira se escolheu a porta certa e se o Monitor Serial está fechado.';
        }
        return 'A ESP32 está mandando o log, mas sem as linhas do visor. Grave o firmware novo (VISOR_WEB = true).';
    };

    ConexaoUsb.prototype.ler = async function () {
        const decodificador = new TextDecoder();
        let resto = '';
        while (this.porta && this.porta.readable) {
            const leitor = this.porta.readable.getReader();
            this.leitor = leitor;
            try {
                for (;;) {
                    const { value, done } = await leitor.read();
                    if (done) break;
                    this.bytes += value.length;
                    resto += decodificador.decode(value, { stream: true });
                    if (!this.modoGravacao && /waiting for download|DOWNLOAD_BOOT/.test(resto)) this.modoGravacao = true;
                    let fim;
                    while ((fim = resto.indexOf('\n')) >= 0) {
                        this.linha(resto.slice(0, fim).replace(/\r$/, ''));
                        resto = resto.slice(fim + 1);
                    }
                    if (resto.length > 4096) resto = '';   // lixo sem quebra de linha (ex.: boot da ESP32)
                }
            } catch (e) {
                // Erros de leitura passageiros (ruído no boot) seguem no while; cabo solto zera o readable.
            } finally {
                leitor.releaseLock();
                this.leitor = null;
            }
            if (this.saidaPedida) break;
        }
        await this.fechar();
    };

    ConexaoUsb.prototype.linha = function (texto) {
        const inicio = texto.indexOf('VISOR {');
        if (inicio < 0) return;   // as outras linhas do Serial são o log de sempre
        let dado;
        try {
            dado = JSON.parse(texto.slice(inicio + 6));
        } catch (e) {
            return;               // linha cortada (a página abriu no meio dela)
        }
        this.ultimoDado = Date.now();
        if (this.estado !== 'ao-vivo') this.mudar('ao-vivo');
        this.aoDado(dado);
    };

    ConexaoUsb.prototype.fechar = async function () {
        clearInterval(this.vigia);
        const porta = this.porta;
        this.porta = null;
        if (porta) {
            try {
                await porta.close();
            } catch (e) { /* já estava fechada (cabo solto) */ }
        }
        if (this.saidaPedida) this.mudar('demo');
        else this.mudar('perdida');   // a própria tela explica o que fazer
    };

    // Botão "Voltar à demonstração".
    ConexaoUsb.prototype.desconectar = async function () {
        this.saidaPedida = true;
        if (this.leitor) {
            try {
                await this.leitor.cancel();   // o ler() termina e chama fechar()
            } catch (e) {
                await this.fechar();
            }
        } else if (this.porta) {
            await this.fechar();
        } else {
            this.mudar('demo');
        }
    };

    ConexaoUsb.prototype.vigiarCabo = function () {
        if (!this.suportada()) return;
        navigator.serial.addEventListener('connect', () => this.retomar());
    };

    Visor.conexaoUsb = function (opcoes) {
        const conexao = new ConexaoUsb(opcoes);
        conexao.vigiarCabo();
        return conexao;
    };

    // Chip do topo + botão + nota do rodapé, iguais nas duas telas.
    Visor.ligarControlesUsb = function (conexao, el) {
        const TEXTOS = {
            'demo': 'Demonstração',
            'conectando': 'Conectando...',
            'aguardando': 'ESP32 no USB',
            'aguardando-firmware': 'ESP32 sem dados',
            'ao-vivo': 'ESP32 ao vivo',
            'perdida': 'ESP32 desconectada',
        };

        const suportada = conexao.suportada();

        function desenhar(estado, mensagem) {
            el.chip.dataset.estado = estado === 'aguardando-firmware' ? 'aguardando' : estado;
            el.chip.textContent = TEXTOS[estado] || estado;
            const usandoUsb = estado !== 'demo';
            el.botao.replaceChildren(Visor.icone(usandoUsb ? 'play' : 'usb'),
                document.createTextNode(usandoUsb ? 'Voltar à demonstração' : 'Conectar ESP32 (USB)'));
            el.botao.disabled = !suportada || estado === 'conectando';
            if (!suportada) {
                mensagem = 'A conexão com a ESP32 pelo USB funciona no Chrome ou no Edge do computador. '
                    + 'A demonstração funciona em qualquer navegador.';
            }
            el.nota.textContent = mensagem;
            el.nota.hidden = !mensagem;
        }

        if (!suportada) el.botao.title = 'A conexão USB funciona no Chrome ou no Edge do computador.';

        el.botao.addEventListener('click', () => {
            if (conexao.estado === 'demo') conexao.escolher();
            else conexao.desconectar();
        });

        return desenhar;
    };

    window.Visor = Visor;
})();
