// =========================================================
// CONFIGURAÇÕES — SICAPDA by FLUXE
// Tudo aqui é front-end puro. Os formulários (perfil, sistema,
// senha) só dão preventDefault + feedback visual por enquanto —
// quando o Controller/rota de configurações existir, troque cada
// bloco marcado "TODO backend" por um fetch/submit real.
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

function inicializarFormularios() {
    const formPerfil = document.getElementById('formPerfil');
    const formSistema = document.getElementById('formSistema');
    const formSenha = document.getElementById('formSenha');

    if (formPerfil) {
        formPerfil.addEventListener('submit', (e) => {
            e.preventDefault();
            // TODO backend: enviar os dados do perfil pro Controller
            feedbackBotao(formPerfil.querySelector('button[type="submit"]'), 'Salvo!');
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
        formSenha.addEventListener('submit', (e) => {
            e.preventDefault();

            const nova = document.getElementById('senhaNova').value;
            const confirmar = document.getElementById('senhaConfirmar').value;
            const erro = document.getElementById('erroSenha');

            if (nova.length > 0 && nova !== confirmar) {
                erro.hidden = false;
                return;
            }

            erro.hidden = true;
            // TODO backend: enviar troca de senha pro Controller (com hash, validações etc.)
            feedbackBotao(formSenha.querySelector('button[type="submit"]'), 'Senha atualizada!');
            formSenha.reset();
        });
    }

    const inputFoto = document.getElementById('inputFotoPerfil');
    if (inputFoto) {
        inputFoto.addEventListener('change', () => {
            // TODO backend: subir o arquivo pro uploads/perfil e atualizar a sessão
            if (inputFoto.files[0]) {
                console.log('Foto selecionada:', inputFoto.files[0].name);
            }
        });
    }
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