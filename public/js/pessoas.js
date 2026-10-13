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

// =========================================================
// CADASTRO (aba) — pessoas, cartões e importação de planilha
// Fala com PessoaController (rotas /pessoas/*). Só o administrador vê os botões
// de alterar; o servidor confere a permissão de novo em toda ação.
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    montarAbas();
    montarAviso();
    montarBuscaCadastro();
    montarCadastro();
});

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

function dadosDe(campos) {
    const dados = new FormData();
    Object.entries(campos).forEach(([chave, valor]) => dados.append(chave, valor));
    return dados;
}

function elemento(tag, texto, classe) {
    const el = document.createElement(tag);
    if (classe) el.className = classe;
    el.textContent = texto;
    return el;
}

function mostrarErro(el, mensagem) {
    if (!el) return;
    el.textContent = mensagem || '';
    el.hidden = !mensagem;
}

// ===================== ABAS =====================

function montarAbas() {
    const botoes = document.querySelectorAll('.pessoas-aba');
    const paineis = document.querySelectorAll('[data-aba-painel]');
    if (!botoes.length) return;

    const ativar = (aba) => {
        botoes.forEach((b) => b.classList.toggle('ativa', b.dataset.aba === aba));
        paineis.forEach((p) => { p.hidden = p.dataset.abaPainel !== aba; });
        history.replaceState(null, '', aba === 'presentes' ? location.pathname + location.search : `#${aba}`);
    };

    botoes.forEach((b) => b.addEventListener('click', () => ativar(b.dataset.aba)));
    if (location.hash === '#cadastro') ativar('cadastro');
}

// ===================== AVISO (depois de recarregar) =====================

let temporizadorAviso = null;

function mostrarAviso(texto) {
    const el = document.getElementById('avisoPessoas');
    if (!el) return;
    el.textContent = texto;
    el.hidden = false;
    clearTimeout(temporizadorAviso);
    temporizadorAviso = setTimeout(() => { el.hidden = true; }, 5000);
}

function montarAviso() {
    try {
        const aviso = sessionStorage.getItem('pessoasAviso');
        if (!aviso) return;
        sessionStorage.removeItem('pessoasAviso');
        mostrarAviso(aviso);
    } catch (erro) { /* sem sessionStorage: segue sem o aviso */ }
}

/** Depois de gravar, recarrega a página (a lista vem pronta do servidor) já na aba Cadastro. */
function recarregarComAviso(texto) {
    try { sessionStorage.setItem('pessoasAviso', texto); } catch (erro) { /* sem aviso */ }
    location.hash = 'cadastro';
    location.reload();
}

// ===================== BUSCA NO CADASTRO =====================

function montarBuscaCadastro() {
    const input = document.getElementById('buscaCadastro');
    const itens = document.querySelectorAll('.cad-item');
    const vazio = document.getElementById('cadVazio');
    if (!input || !itens.length) return;

    input.addEventListener('input', () => {
        const termo = input.value.trim().toLowerCase();
        let visiveis = 0;

        itens.forEach((item) => {
            const bate = (item.dataset.busca || '').includes(termo);
            item.hidden = !bate;
            if (bate) visiveis++;
        });

        if (vazio) {
            vazio.textContent = 'Nenhuma pessoa encontrada.';
            vazio.hidden = visiveis > 0;
        }
    });
}

// ===================== DIÁLOGOS =====================

function abrirDialogo(dlg) {
    dlg.showModal();
    iniciarLeituras(dlg);
}

/**
 * Lista "cartões lidos nas catracas e ainda sem dono": consulta o servidor a cada 3 s enquanto o
 * diálogo está aberto. Passou o cartão na catraca → aparece aqui (destacado) → clique para usar o UID.
 */
function iniciarLeituras(dlg) {
    const caixa = dlg.querySelector('[data-leituras]');
    if (!caixa || caixa.closest('[hidden]')) return;

    const lista = caixa.querySelector('.leituras-lista');
    const status = caixa.querySelector('.leituras-status');
    const input = dlg.querySelector('[data-uid-input]');

    let base = null;      // o que já existia quando o diálogo abriu; o que chegar depois é "novo"
    let ultimos = [];

    const desenhar = () => {
        lista.replaceChildren();

        if (!ultimos.length) {
            lista.appendChild(elemento('p', 'Nenhum cartão novo lido ainda. Passe o cartão na catraca.', 'leituras-vazio'));
            return;
        }

        const escolhido = (input.value || '').trim().toUpperCase().replace(/[\s:]+/g, '');
        ultimos.forEach((l) => {
            const novo = base !== null && (base.get(l.uid) ?? 0) < l.leituras;
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'leitura-chip' + (novo ? ' leitura-nova' : '') + (escolhido === l.uid ? ' selecionada' : '');
            chip.append(
                elemento('code', l.uid),
                elemento('span', `${l.dispositivo} · ${l.quando}${l.leituras > 1 ? ` · ${l.leituras}×` : ''}`)
            );
            chip.addEventListener('click', () => {
                input.value = l.uid;
                desenhar();
            });
            lista.appendChild(chip);
        });
    };

    const buscar = async () => {
        if (document.hidden) return;
        try {
            const resposta = await fetch('/pessoas/leituras', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (resposta.status === 401) { window.location.href = '/login'; return; }
            const dados = await resposta.json();
            if (!dados.sucesso) { status.textContent = '· indisponível'; return; }

            if (base === null) base = new Map(dados.leituras.map((l) => [l.uid, l.leituras]));
            ultimos = dados.leituras;
            status.textContent = '· atualiza sozinha';
            desenhar();
        } catch (erro) {
            status.textContent = '· sem conexão';
        }
    };

    buscar();
    const temporizador = setInterval(buscar, 3000);
    dlg.addEventListener('close', () => clearInterval(temporizador), { once: true });
}

function montarCadastro() {
    const lista = document.getElementById('listaCadastro');
    const dlgPessoa = document.getElementById('dlgPessoa');
    if (!lista || !dlgPessoa) return; // sem os diálogos = não é administrador

    document.querySelectorAll('dialog.dlg').forEach((dlg) => {
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); }); // clique no fundo escuro
        dlg.querySelectorAll('[data-fechar]').forEach((b) => b.addEventListener('click', () => dlg.close()));
    });

    montarDialogoPessoa(dlgPessoa);
    montarDialogoCartao(document.getElementById('dlgCartao'));
    montarDialogoImportar(document.getElementById('dlgImportar'));

    lista.addEventListener('click', (e) => {
        const botao = e.target.closest('button');
        const item = botao && botao.closest('.cad-item');
        if (!item) return;

        if (botao.classList.contains('btn-vincular')) abrirVincular(item);
        else if (botao.classList.contains('btn-editar')) abrirPessoa(item);
        else if (botao.classList.contains('btn-ativo')) alternarPessoa(item);
        else if (botao.classList.contains('chip-ativo')) alternarCartao(botao.closest('.cartao-chip'));
        else if (botao.classList.contains('chip-remover')) removerCartao(botao.closest('.cartao-chip'), item);
    });

    document.getElementById('btnNovaPessoa').addEventListener('click', () => abrirPessoa(null));
    document.getElementById('btnImportar').addEventListener('click', () => abrirImportar());
}

// ----- Adicionar / editar pessoa -----

function abrirPessoa(item) {
    const dlg = document.getElementById('dlgPessoa');
    const form = document.getElementById('formPessoa');
    form.reset();
    mostrarErro(document.getElementById('msgPessoa'), '');

    const edicao = item !== null;
    document.getElementById('dlgPessoaTitulo').textContent = edicao ? 'Editar pessoa' : 'Adicionar pessoa';
    document.getElementById('blocoCartaoNovo').hidden = edicao; // cartões de quem já existe se mexem na própria linha
    document.getElementById('pessoaId').value = edicao ? item.dataset.id : '';

    if (edicao) {
        document.getElementById('pessoaNome').value = item.dataset.nome;
        document.getElementById('pessoaCargo').value = item.dataset.cargo;
        document.getElementById('pessoaCategoria').value = item.dataset.categoria;
        document.getElementById('pessoaMatricula').value = item.dataset.matricula;
    }

    abrirDialogo(dlg);
    document.getElementById('pessoaNome').focus();
}

function montarDialogoPessoa(dlg) {
    const form = document.getElementById('formPessoa');
    const msg = document.getElementById('msgPessoa');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const botao = form.querySelector('button[type="submit"]');
        const edicao = document.getElementById('pessoaId').value !== '';
        mostrarErro(msg, '');
        botao.disabled = true;

        const resultado = await enviar(edicao ? '/pessoas/editar' : '/pessoas/criar', new FormData(form));
        botao.disabled = false;

        if (!resultado.sucesso) {
            mostrarErro(msg, resultado.mensagem);
            return;
        }
        recarregarComAviso(resultado.mensagem);
    });
}

// ----- Ativar / desativar -----

async function alternarPessoa(item) {
    const ativa = item.dataset.ativo === '1';
    const nome = item.dataset.nome;

    const certeza = !ativa || window.confirm(
        `Desativar ${nome}?\n\nEla deixa de passar na catraca e sai das contagens, mas o histórico de acessos fica guardado. ` +
        'Dá para reativar quando quiser.'
    );
    if (!certeza) return;

    const resultado = await enviar('/pessoas/ativo', dadosDe({ id: item.dataset.id, ativo: ativa ? '0' : '1' }));
    if (!resultado.sucesso) {
        window.alert(resultado.mensagem);
        return;
    }
    recarregarComAviso(resultado.mensagem);
}

async function alternarCartao(chip) {
    const ativo = chip.dataset.ativo === '1';
    const resultado = await enviar('/pessoas/cartao/ativo', dadosDe({ id: chip.dataset.cartao, ativo: ativo ? '0' : '1' }));
    if (!resultado.sucesso) {
        window.alert(resultado.mensagem);
        return;
    }
    recarregarComAviso(resultado.mensagem);
}

async function removerCartao(chip, item) {
    const uid = chip.querySelector('code').textContent;
    const certeza = window.confirm(
        `Remover o cartão ${uid} de ${item.dataset.nome}?\n\nEle deixa de funcionar e o UID fica livre para outra pessoa.`
    );
    if (!certeza) return;

    const resultado = await enviar('/pessoas/cartao/remover', dadosDe({ id: chip.dataset.cartao }));
    if (!resultado.sucesso) {
        window.alert(resultado.mensagem);
        return;
    }
    recarregarComAviso(resultado.mensagem);
}

// ----- Vincular cartão -----

function abrirVincular(item) {
    document.getElementById('cartaoPessoaId').value = item.dataset.id;
    document.getElementById('dlgCartaoPessoa').textContent = item.dataset.nome;
    document.getElementById('cartaoUid').value = '';
    mostrarErro(document.getElementById('msgCartao'), '');
    abrirDialogo(document.getElementById('dlgCartao'));
}

function montarDialogoCartao(dlg) {
    const form = document.getElementById('formCartao');
    const msg = document.getElementById('msgCartao');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const botao = form.querySelector('button[type="submit"]');
        mostrarErro(msg, '');
        botao.disabled = true;

        const resultado = await enviar('/pessoas/cartao', new FormData(form));
        botao.disabled = false;

        if (!resultado.sucesso) {
            mostrarErro(msg, resultado.mensagem);
            return;
        }
        recarregarComAviso(resultado.mensagem);
    });
}

// ----- Importar planilha -----

function abrirImportar() {
    const dlg = document.getElementById('dlgImportar');
    document.getElementById('formImportar').reset();
    mostrarErro(document.getElementById('msgImportar'), '');
    document.getElementById('resultadoImportacao').hidden = true;
    document.getElementById('btnEnviarImportar').hidden = false;
    document.getElementById('btnFecharImportar').textContent = 'Cancelar';
    abrirDialogo(dlg);
}

function montarDialogoImportar(dlg) {
    const form = document.getElementById('formImportar');
    const msg = document.getElementById('msgImportar');
    const caixa = document.getElementById('resultadoImportacao');
    const enviarBotao = document.getElementById('btnEnviarImportar');
    let importou = false;

    // Se importou algo, fechar o diálogo recarrega a lista.
    dlg.addEventListener('close', () => {
        if (importou) recarregarComAviso('Importação concluída.');
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        mostrarErro(msg, '');
        caixa.hidden = true;
        enviarBotao.disabled = true;
        enviarBotao.textContent = 'Importando…';

        const resultado = await enviar('/pessoas/importar', new FormData(form));
        enviarBotao.disabled = false;
        enviarBotao.textContent = 'Importar';

        if (!resultado.sucesso) {
            mostrarErro(msg, resultado.mensagem);
            return;
        }

        importou = resultado.pessoas > 0;
        caixa.replaceChildren();
        caixa.appendChild(elemento(
            'strong',
            `${resultado.pessoas} ${resultado.pessoas === 1 ? 'pessoa cadastrada' : 'pessoas cadastradas'}` +
            ` (${resultado.cartoes} com cartão)` +
            (resultado.ignoradas ? ` · ${resultado.ignoradas} ${resultado.ignoradas === 1 ? 'linha ignorada' : 'linhas ignoradas'}` : '')
        ));

        if (resultado.erros.length) {
            const ul = document.createElement('ul');
            resultado.erros.forEach((er) => ul.appendChild(elemento('li', `Linha ${er.linha}: ${er.motivo}`)));
            caixa.appendChild(ul);
            if (resultado.ignoradas > resultado.erros.length) {
                caixa.appendChild(elemento('p', `…e mais ${resultado.ignoradas - resultado.erros.length} linhas ignoradas.`));
            }
        }

        caixa.hidden = false;
        enviarBotao.hidden = true; // o arquivo já foi processado
        document.getElementById('btnFecharImportar').textContent = 'Concluir';
    });
}
