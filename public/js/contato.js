// Formulário "Contato" das páginas / (Fluxe) e /sicapda (form[data-contato]).
// Envia para /contato sem recarregar a página e mostra o resultado no próprio cartão. O servidor
// (ContatoController) guarda a mensagem, envia por e-mail para a equipe e manda a confirmação para quem escreveu.
(function () {
    'use strict';

    const MENSAGENS = {
        nome: 'Informe o seu nome.',
        email: 'Informe um e-mail válido para podermos responder.',
        telefone: 'Informe um telefone válido (ou deixe em branco).',
        mensagem: 'Escreva uma mensagem com pelo menos 10 caracteres.',
    };

    function mostrar(form, tipo, texto) {
        const status = form.querySelector('.formStatus');
        if (!status) return;
        status.textContent = texto;
        status.className = 'formStatus formStatus--' + tipo;
        status.hidden = false;
    }

    function validar(form) {
        for (const nome of ['nome', 'email', 'telefone', 'mensagem']) {
            const campo = form.elements[nome];
            if (!campo) continue;
            campo.value = campo.value.trim() === '' ? '' : campo.value;
            let ok = campo.checkValidity();
            if (nome === 'telefone' && campo.value.trim() !== '') {
                ok = /^[0-9()+\-.\s]{8,30}$/.test(campo.value.trim());
            }
            if (nome === 'mensagem' && campo.value.trim().length < 10) ok = false;
            campo.toggleAttribute('aria-invalid', !ok);
            if (!ok) {
                mostrar(form, 'erro', MENSAGENS[nome]);
                campo.focus();
                return false;
            }
        }
        return true;
    }

    function iniciar(form) {
        const botao = form.querySelector('.formSubmit');
        const rotulo = botao ? botao.textContent : '';

        form.addEventListener('input', (ev) => ev.target.removeAttribute('aria-invalid'));

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            if (form.dataset.enviando === '1' || !validar(form)) return;

            form.dataset.enviando = '1';
            if (botao) { botao.disabled = true; botao.textContent = 'Enviando...'; }
            mostrar(form, 'info', 'Enviando a sua mensagem...');

            try {
                const resposta = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const dados = await resposta.json().catch(() => ({}));
                if (resposta.ok && dados.sucesso) {
                    mostrar(form, 'sucesso', dados.mensagem || 'Mensagem enviada! Em breve entraremos em contato.');
                    form.reset();
                } else {
                    mostrar(form, 'erro', dados.mensagem || 'Não foi possível enviar agora. Tente novamente em instantes.');
                }
            } catch (e) {
                mostrar(form, 'erro', 'Sem conexão com o servidor. Confira a internet e tente novamente.');
            } finally {
                form.dataset.enviando = '';
                if (botao) { botao.disabled = false; botao.textContent = rotulo; }
            }
        });

        // Resultado de um envio sem JavaScript (o servidor volta para ?contato=ok|erro#contato).
        const resultado = new URLSearchParams(location.search).get('contato');
        if (resultado === 'ok') mostrar(form, 'sucesso', 'Mensagem enviada! Em breve entraremos em contato.');
        if (resultado === 'erro') mostrar(form, 'erro', 'Não foi possível enviar a mensagem. Confira os campos e tente novamente.');
    }

    document.querySelectorAll('form[data-contato]').forEach(iniciar);
})();
