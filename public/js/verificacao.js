// Segunda etapa do login (app/views/verificacao.php). Tudo aqui é conforto: sem JavaScript a tela funciona igual.

(function () {
    const formCodigo = document.getElementById('formCodigo');
    const campo = document.getElementById('codigo');

    // Só dígitos; com os 6 digitados (ou colados, ou preenchidos pelo celular a partir do SMS/e-mail), envia sozinho.
    if (formCodigo && campo) {
        let enviando = false;
        campo.addEventListener('input', () => {
            const digitos = campo.value.replace(/\D/g, '').slice(0, 6);
            if (campo.value !== digitos) campo.value = digitos;
            if (digitos.length === 6 && !enviando) {
                enviando = true;
                formCodigo.requestSubmit ? formCodigo.requestSubmit() : formCodigo.submit();
            }
        });
        formCodigo.addEventListener('submit', () => {
            enviando = true;
            const botao = formCodigo.querySelector('button[type="submit"]');
            if (botao) {
                botao.disabled = true;
                botao.textContent = 'VERIFICANDO...';
            }
        });
    }

    // Código de recuperação: maiúsculas e o hífen no meio (ABCDE-FGHIJ), como foi mostrado ao ativar.
    const recuperacao = document.getElementById('codigoRecuperacao');
    recuperacao?.addEventListener('input', () => {
        const limpo = recuperacao.value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 10);
        recuperacao.value = limpo.length > 5 ? `${limpo.slice(0, 5)}-${limpo.slice(5)}` : limpo;
    });
    // Ao abrir "Use um código de recuperação", o cursor já vai para o campo.
    document.querySelector('.verificacao-recuperacao')?.addEventListener('toggle', (e) => {
        if (e.target.open) recuperacao?.focus();
    });

    // "Reenviar código": contagem regressiva de 1 minuto entre um envio e outro.
    const reenviar = document.getElementById('btnReenviar');
    let espera = Number(reenviar?.dataset.espera || 0);
    if (reenviar && espera > 0) {
        const relogio = setInterval(() => {
            espera -= 1;
            if (espera <= 0) {
                clearInterval(relogio);
                reenviar.disabled = false;
                reenviar.textContent = 'Reenviar código';
            } else {
                reenviar.textContent = `Reenviar código em ${espera} s`;
            }
        }, 1000);
    }
})();
