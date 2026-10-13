// =========================================================
// CONFIGURAÇÕES — SICAPDA by FLUXE
// Tudo fala com o backend real (ConfiguracaoController, rotas /configuracoes/*):
// perfil, senha, foto, notificações, sistema, dispositivos IoT e sessões.
// A única exceção é o tema (claro/escuro), que é por navegador e fica no localStorage.
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    inicializarAbas();
    inicializarMenuMobile();
    inicializarTema();
    inicializarFormularios();
    inicializarNotificacoes();
    inicializarDispositivos();
    inicializarSessoes();
});

// ===================== ABAS =====================

function inicializarAbas() {
    const botoes = document.querySelectorAll('.config-aba');
    const paineis = document.querySelectorAll('.config-painel');

    botoes.forEach((botao) => {
        botao.addEventListener('click', () => {
            const alvo = botao.dataset.aba;

            botoes.forEach((b) => b.classList.remove('ativa'));
            botao.classList.add('ativa');

            paineis.forEach((painel) => {
                painel.classList.toggle('ativa', painel.dataset.abaPainel === alvo);
            });

            history.replaceState(null, '', `#${alvo}`);
        });
    });

    // Abre a aba certa se a URL já vier com #hash (ex: /configuracoes#seguranca)
    const hash = window.location.hash.replace('#', '');
    const botaoInicial = [...botoes].find((b) => b.dataset.aba === hash);
    if (botaoInicial) botaoInicial.click();
}

// ===================== MENU MOBILE (sidebar) =====================

function inicializarMenuMobile() {
    const botao = document.getElementById('btnMenuMobile');
    const sidebar = document.getElementById('sidebar');
    if (!botao || !sidebar) return;

    botao.addEventListener('click', () => sidebar.classList.toggle('aberta'));
}

// ===================== TEMA (claro / escuro / sistema) =====================

function inicializarTema() {
    const opcoes = document.querySelectorAll('.tema-opcao');
    const salvo = localStorage.getItem('sicapda_tema') || 'claro';

    aplicarTema(salvo);
    marcarOpcaoAtiva(opcoes, 'tema', salvo);

    opcoes.forEach((opcao) => {
        opcao.addEventListener('click', () => {
            const tema = opcao.dataset.tema;
            aplicarTema(tema);
            marcarOpcaoAtiva(opcoes, 'tema', tema);
            localStorage.setItem('sicapda_tema', tema);
        });
    });
}

function aplicarTema(tema) {
    let temaFinal = tema;

    if (tema === 'sistema') {
        const prefereEscuro = window.matchMedia('(prefers-color-scheme: dark)').matches;
        temaFinal = prefereEscuro ? 'escuro' : 'claro';
    }

    // Aplicado no <html> (não no <body>) porque o script inline no <head> de
    // cada página também mexe no <html>, antes do <body> existir — assim o
    // tema fica consistente em todas as telas (painel.php, acessos.php etc).
    if (temaFinal === 'escuro') {
        document.documentElement.setAttribute('data-tema', 'escuro');
    } else {
        document.documentElement.removeAttribute('data-tema');
    }
}

function marcarOpcaoAtiva(lista, dataset, valor) {
    lista.forEach((el) => el.classList.toggle('ativa', el.dataset[dataset] === valor));
}

// ===================== FORMULÁRIOS =====================

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
}

/** POST (FormData) para o backend; sempre devolve {sucesso, mensagem, ...}. */
async function enviar(url, formData) {
    try {
        const resposta = await fetch(url, {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-Token': csrfToken(), Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (resposta.status === 401) {
            window.location.href = '/login';
            return { sucesso: false, mensagem: 'Sessão expirada.' };
        }

        return await resposta.json();
    } catch (erro) {
        return { sucesso: false, mensagem: 'Não foi possível conectar ao servidor.' };
    }
}

function mostrarMensagem(el, mensagem, sucesso) {
    if (!el) return;
    el.textContent = mensagem;
    el.hidden = !mensagem;
    el.classList.toggle('campo-sucesso', !!sucesso);
}

function inicializarFormularios() {
    const formPerfil = document.getElementById('formPerfil');
    const formSistema = document.getElementById('formSistema');
    const formSenha = document.getElementById('formSenha');

    if (formPerfil) {
        const emailInput = document.getElementById('perfilEmail');
        const campoSenha = document.getElementById('campoSenhaEmail');
        const senhaInput = document.getElementById('perfilSenhaAtual');
        const msg = document.getElementById('msgPerfil');
        let emailOriginal = emailInput.value;

        // Alterar o e-mail exige confirmar a senha atual
        emailInput.addEventListener('input', () => {
            const mudou = emailInput.value.trim().toLowerCase() !== emailOriginal.toLowerCase();
            campoSenha.hidden = !mudou;
            senhaInput.required = mudou;
            if (!mudou) senhaInput.value = '';
        });

        formPerfil.addEventListener('submit', async (e) => {
            e.preventDefault();
            const botao = formPerfil.querySelector('button[type="submit"]');
            mostrarMensagem(msg, '');
            botao.disabled = true;

            const resultado = await enviar('/configuracoes/perfil', new FormData(formPerfil));
            botao.disabled = false;

            if (!resultado.sucesso) {
                mostrarMensagem(msg, resultado.mensagem, false);
                return;
            }

            // Reflete os dados salvos na tela (sem recarregar)
            emailOriginal = resultado.email;
            emailInput.value = resultado.email;
            campoSenha.hidden = true;
            senhaInput.required = false;
            senhaInput.value = '';
            document.getElementById('perfilNome').value = resultado.nome;
            document.getElementById('perfilCargo').value = resultado.cargo;
            document.getElementById('perfilNomeTopo').textContent = resultado.nome;
            document.getElementById('perfilEmailTopo').textContent = resultado.email;
            atualizarNomeNaSidebar(resultado.nome);
            feedbackBotao(botao, 'Salvo!');
        });
    }

    if (formSistema) {
        const msg = document.getElementById('msgSistema');

        formSistema.addEventListener('submit', async (e) => {
            e.preventDefault();
            const botao = formSistema.querySelector('button[type="submit"]');
            mostrarMensagem(msg, '');
            botao.disabled = true;

            const resultado = await enviar('/configuracoes/sistema', new FormData(formSistema));
            botao.disabled = false;

            if (!resultado.sucesso) {
                mostrarMensagem(msg, resultado.mensagem, false);
                return;
            }
            feedbackBotao(botao, 'Salvo!');
        });
    }

    if (formSenha) {
        formSenha.addEventListener('submit', async (e) => {
            e.preventDefault();

            const nova = document.getElementById('senhaNova').value;
            const confirmar = document.getElementById('senhaConfirmar').value;
            const erro = document.getElementById('erroSenha');
            const botao = formSenha.querySelector('button[type="submit"]');

            if (nova.length < 8) {
                mostrarMensagem(erro, 'A nova senha deve ter pelo menos 8 caracteres.', false);
                return;
            }
            if (nova !== confirmar) {
                mostrarMensagem(erro, 'As senhas não coincidem.', false);
                return;
            }

            mostrarMensagem(erro, '');
            botao.disabled = true;
            const resultado = await enviar('/configuracoes/senha', new FormData(formSenha));
            botao.disabled = false;

            if (!resultado.sucesso) {
                mostrarMensagem(erro, resultado.mensagem, false);
                return;
            }

            formSenha.reset();
            feedbackBotao(botao, 'Senha atualizada!');
        });
    }

    const inputFoto = document.getElementById('inputFotoPerfil');
    if (inputFoto) {
        inputFoto.addEventListener('change', async () => {
            const arquivo = inputFoto.files[0];
            if (!arquivo) return;

            const msg = document.getElementById('msgPerfil');
            mostrarMensagem(msg, '');

            if (!['image/jpeg', 'image/png'].includes(arquivo.type)) {
                mostrarMensagem(msg, 'Formato inválido. Envie uma imagem JPG ou PNG.', false);
                inputFoto.value = '';
                return;
            }
            if (arquivo.size > 5 * 1024 * 1024) {
                mostrarMensagem(msg, 'A imagem deve ter no máximo 5 MB.', false);
                inputFoto.value = '';
                return;
            }

            const dados = new FormData();
            dados.append('foto_perfil', arquivo);
            const resultado = await enviar('/configuracoes/foto', dados);
            inputFoto.value = '';

            if (!resultado.sucesso) {
                mostrarMensagem(msg, resultado.mensagem, false);
                return;
            }

            mostrarMensagem(msg, resultado.mensagem, true);
            atualizarAvatares(resultado.foto);
        });
    }
}

/** Troca o avatar (letra ou foto antiga) pela nova foto, na sidebar e no topo do perfil. */
function atualizarAvatares(urlFoto) {
    const alvos = document.querySelectorAll('.sidebar-usuario .avatar, .config-perfil-topo .avatar');
    alvos.forEach((avatar) => {
        avatar.classList.add('avatar-foto');
        avatar.textContent = '';
        const img = document.createElement('img');
        img.src = `${urlFoto}?v=${Date.now()}`;
        img.alt = 'Foto de perfil';
        avatar.appendChild(img);
    });
}

function atualizarNomeNaSidebar(nome) {
    const el = document.querySelector('.sidebar-usuario-info strong');
    if (el) el.textContent = nome;

    // Sem foto, o avatar mostra a inicial do nome
    document.querySelectorAll('.avatar:not(.avatar-foto)').forEach((avatar) => {
        avatar.textContent = nome.charAt(0).toUpperCase();
    });
}

function feedbackBotao(botao, textoSucesso) {
    if (!botao) return;
    const original = botao.innerHTML;
    botao.disabled = true;
    botao.innerHTML = `<i class="bi bi-check-lg"></i> ${textoSucesso}`;

    setTimeout(() => {
        botao.innerHTML = original;
        botao.disabled = false;
    }, 1800);
}

// ===================== NOTIFICAÇÕES =====================

/** Cada chave salva na hora; se o servidor recusar, o interruptor volta ao estado anterior. */
function inicializarNotificacoes() {
    const msg = document.getElementById('msgNotificacoes');

    document.querySelectorAll('input[data-notificacao]:not(:disabled)').forEach((input) => {
        input.addEventListener('change', async () => {
            mostrarMensagem(msg, '');
            input.disabled = true;

            const dados = new FormData();
            dados.append('chave', input.dataset.notificacao);
            dados.append('ativo', input.checked ? '1' : '0');
            const resultado = await enviar('/configuracoes/notificacoes', dados);
            input.disabled = false;

            if (!resultado.sucesso) {
                input.checked = !input.checked;
                mostrarMensagem(msg, resultado.mensagem, false);
                return;
            }
            atualizarSino(resultado.sino);
        });
    });
}

/** Atualiza o número do sino do cabeçalho sem recarregar a página. */
function atualizarSino(total) {
    const sino = document.getElementById('btnNotificacoes');
    if (!sino) return;

    let badge = sino.querySelector('.badge-sino');
    if (total > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'badge-sino';
            sino.appendChild(badge);
        }
        badge.textContent = total;
    } else if (badge) {
        badge.remove();
    }
}

// ===================== DISPOSITIVOS IOT =====================

function inicializarDispositivos() {
    const lista = document.getElementById('listaDispositivos');
    if (!lista) return;

    lista.querySelectorAll('.btn-regenerar-token').forEach(ligarNovoToken);

    document.getElementById('btnCopiarToken')?.addEventListener('click', copiarToken);

    inicializarSimulador();

    const botaoAdicionar = document.getElementById('btnAdicionarDispositivo');
    const form = document.getElementById('formDispositivo');
    if (!botaoAdicionar || !form) return; // só o administrador vê o formulário

    const msg = document.getElementById('msgDispositivo');
    const fecharForm = () => {
        form.reset();
        form.hidden = true;
        botaoAdicionar.hidden = false;
        mostrarMensagem(msg, '');
    };

    botaoAdicionar.addEventListener('click', () => {
        form.hidden = false;
        botaoAdicionar.hidden = true;
        document.getElementById('dispNome').focus();
    });
    document.getElementById('btnCancelarDispositivo').addEventListener('click', fecharForm);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const botao = form.querySelector('button[type="submit"]');
        mostrarMensagem(msg, '');
        botao.disabled = true;

        const resultado = await enviar('/configuracoes/dispositivos', new FormData(form));
        botao.disabled = false;

        if (!resultado.sucesso) {
            mostrarMensagem(msg, resultado.mensagem, false);
            return;
        }

        lista.appendChild(montarItemDispositivo(resultado.dispositivo));
        document.getElementById('dispositivosVazio').hidden = true;
        adicionarAoSimulador(resultado.dispositivo);
        fecharForm();
        revelarToken(`Dispositivo criado: ${resultado.dispositivo.nome}`, resultado.token);
    });
}

// ----- Simular leitura (só administrador) -----

/** Catraca criada agora entra na lista do simulador sem recarregar a página. */
function adicionarAoSimulador(d) {
    const select = document.getElementById('simDispositivo');
    const bloco = document.getElementById('blocoSimulador');
    if (!select || !bloco) return;

    select.appendChild(new Option(d.nome, d.id));
    bloco.hidden = false;
}

function inicializarSimulador() {
    const form = document.getElementById('formSimulacao');
    if (!form) return;

    const msg = document.getElementById('msgSimulacao');
    const caixa = document.getElementById('resultadoSimulacao');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const botao = form.querySelector('button[type="submit"]');
        mostrarMensagem(msg, '');
        caixa.hidden = true;
        botao.disabled = true;

        const resposta = await enviar('/configuracoes/dispositivos/simular', new FormData(form));
        botao.disabled = false;

        if (!resposta.sucesso) {
            mostrarMensagem(msg, resposta.mensagem, false);
            return;
        }

        const r = resposta.resultado;
        caixa.className = `resultado-simulacao ${r.autorizado ? 'resultado-ok' : 'resultado-negado'}`;
        caixa.replaceChildren();

        const titulo = document.createElement('strong');
        titulo.textContent = r.autorizado
            ? `${r.nome}: ${r.direcao === 'entrada' ? 'entrada' : 'saída'} liberada${r.repetido ? ' (leitura repetida, não gerou novo registro)' : ''}`
            : r.mensagem;
        caixa.appendChild(titulo);

        if (r.motivo === 'cartao_desconhecido') {
            const dica = document.createElement('p');
            dica.textContent = `O cartão ${resposta.uid} aparece agora em Pessoas → Cadastro → Vincular cartão.`;
            caixa.appendChild(dica);
        }
        caixa.hidden = false;
    });
}

function ligarNovoToken(botao) {
    botao.addEventListener('click', async () => {
        const item = botao.closest('.item-dispositivo');
        const nome = item.querySelector('strong').textContent;

        const certeza = window.confirm(
            `Gerar um novo token para "${nome}"?\n\nO aparelho que usa o token atual deixa de ser aceito ` +
            'até você colocar o novo token no firmware dele.'
        );
        if (!certeza) return;

        botao.disabled = true;
        const dados = new FormData();
        dados.append('id', item.dataset.id);
        const resultado = await enviar('/configuracoes/dispositivos/token', dados);
        botao.disabled = false;

        if (!resultado.sucesso) {
            window.alert(resultado.mensagem);
            return;
        }

        atualizarItemDispositivo(item, resultado.dispositivo);
        revelarToken(`Novo token de ${resultado.dispositivo.nome}`, resultado.token);
    });
}

/** Monta o item da lista com textContent (nada do servidor entra como HTML). */
function montarItemDispositivo(d) {
    const item = document.createElement('div');
    item.className = 'item-dispositivo';
    item.dataset.id = d.id;

    const ponto = document.createElement('div');
    ponto.className = 'item-dispositivo-status';

    const info = document.createElement('div');
    info.className = 'item-dispositivo-info';
    info.append(
        elemento('strong', d.nome),
        elemento('span', '', 'item-dispositivo-detalhe'),
        elemento('span', '', 'token-mascarado')
    );

    const meta = document.createElement('div');
    meta.className = 'item-dispositivo-meta';
    meta.append(elemento('span', '', 'status-texto'), elemento('span', '', 'item-dispositivo-tempo'));

    const botao = document.createElement('button');
    botao.type = 'button';
    botao.className = 'btn-secundario btn-regenerar-token';
    botao.innerHTML = '<i class="bi bi-arrow-repeat"></i> Novo token';
    ligarNovoToken(botao);

    item.append(ponto, info, meta, botao);
    atualizarItemDispositivo(item, d);
    return item;
}

function atualizarItemDispositivo(item, d) {
    const online = d.status === 'online';

    item.querySelector('.item-dispositivo-status').className = `item-dispositivo-status status-${d.status}`;
    item.querySelector('.item-dispositivo-detalhe').textContent =
        d.localizacao ? `${d.tipo_rotulo} · ${d.localizacao}` : d.tipo_rotulo;
    item.querySelector('.token-mascarado').textContent = d.token;

    const status = item.querySelector('.status-texto');
    status.className = `status-texto status-texto-${d.status}`;
    status.textContent = online ? 'Online' : 'Offline';
    item.querySelector('.item-dispositivo-tempo').textContent = d.ultimo_sinal;
}

function elemento(tag, texto, classe) {
    const el = document.createElement(tag);
    if (classe) el.className = classe;
    el.textContent = texto;
    return el;
}

/** Mostra o token inteiro (a única vez em que ele aparece) numa caixa em destaque. */
function revelarToken(titulo, token) {
    const caixa = document.getElementById('tokenRevelado');
    document.getElementById('tokenReveladoTitulo').textContent = titulo;
    document.getElementById('tokenValor').textContent = token;
    caixa.hidden = false;
    caixa.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

async function copiarToken() {
    const botao = document.getElementById('btnCopiarToken');
    const valor = document.getElementById('tokenValor');

    try {
        await navigator.clipboard.writeText(valor.textContent);
    } catch (erro) {
        // Sem permissão da área de transferência (ex.: página sem HTTPS): seleciona o texto para Ctrl+C.
        const faixa = document.createRange();
        faixa.selectNodeContents(valor);
        const selecao = window.getSelection();
        selecao.removeAllRanges();
        selecao.addRange(faixa);
        return;
    }
    feedbackBotao(botao, 'Copiado!');
}

// ===================== SESSÕES ATIVAS =====================

function inicializarSessoes() {
    const msg = document.getElementById('msgSessoes');
    const botaoOutras = document.getElementById('btnEncerrarOutras');

    const atualizarBotaoOutras = () => {
        if (!botaoOutras) return;
        botaoOutras.hidden = document.querySelectorAll('#listaSessoes .btn-encerrar-sessao').length === 0;
    };

    document.querySelectorAll('.btn-encerrar-sessao').forEach((botao) => {
        botao.addEventListener('click', async () => {
            const item = botao.closest('.item-sessao');
            mostrarMensagem(msg, '');
            botao.disabled = true;

            const dados = new FormData();
            dados.append('id', item.dataset.id);
            const resultado = await enviar('/configuracoes/sessoes/encerrar', dados);

            if (!resultado.sucesso) {
                botao.disabled = false;
                mostrarMensagem(msg, resultado.mensagem, false);
                return;
            }
            item.remove();
            atualizarBotaoOutras();
        });
    });

    if (botaoOutras) {
        botaoOutras.addEventListener('click', async () => {
            if (!window.confirm('Encerrar todas as outras sessões? Quem estiver conectado nelas precisará entrar de novo.')) return;

            mostrarMensagem(msg, '');
            botaoOutras.disabled = true;
            const dados = new FormData();
            dados.append('todas', '1');
            const resultado = await enviar('/configuracoes/sessoes/encerrar', dados);
            botaoOutras.disabled = false;

            if (!resultado.sucesso) {
                mostrarMensagem(msg, resultado.mensagem, false);
                return;
            }
            document.querySelectorAll('#listaSessoes .item-sessao').forEach((item) => {
                if (!item.querySelector('.tag-atual')) item.remove();
            });
            atualizarBotaoOutras();
            mostrarMensagem(msg, resultado.mensagem, true);
        });
    }
}
