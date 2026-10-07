// =========================================================
// CONFIGURAÇÕES — SICAPDA by FLUXE
// Perfil (nome/e-mail/cargo), senha e foto falam com o backend real
// (ConfiguracaoController, rotas /configuracoes/perfil|senha|foto).
// O restante (sistema, notificações, IoT, sessões) ainda é visual
// e está marcado com "TODO backend".
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    inicializarAbas();
    inicializarMenuMobile();
    inicializarTema();
    inicializarFormularios();
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
        formSistema.addEventListener('submit', (e) => {
            e.preventDefault();
            // TODO backend: salvar preferências do sistema
            feedbackBotao(formSistema.querySelector('button[type="submit"]'), 'Salvo!');
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

// ===================== DISPOSITIVOS IOT =====================

function inicializarDispositivos() {
    document.querySelectorAll('.btn-regenerar-token').forEach((botao) => {
        botao.addEventListener('click', () => {
            // TODO backend: chamar a rota que regenera o token do dispositivo_iot
            const tokenEl = botao.closest('.item-dispositivo').querySelector('.token-mascarado');
            tokenEl.textContent = gerarTokenFake();
            feedbackBotao(botao, 'Gerado!');
        });
    });
}

function gerarTokenFake() {
    const aleatorio = () => Math.random().toString(16).slice(2, 6);
    return `sk_iot_${aleatorio()}${aleatorio()}...${aleatorio()}`;
}

// ===================== SESSÕES ATIVAS =====================

function inicializarSessoes() {
    document.querySelectorAll('.btn-encerrar-sessao').forEach((botao) => {
        botao.addEventListener('click', () => {
            // TODO backend: invalidar a sessão correspondente no servidor
            botao.closest('.item-sessao').remove();
        });
    });
}