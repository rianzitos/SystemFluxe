// =========================================================
// ASSISTENTE VIRTUAL — chat
// Envia a pergunta para POST /assistente/perguntar (o cálculo
// acontece no PHP) e mostra a resposta no chat.
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    const lista = document.getElementById('chatMensagens');
    const form = document.getElementById('chatForm');
    const entrada = document.getElementById('chatEntrada');
    const botao = document.getElementById('chatEnviar');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (!lista || !form || !entrada) return;

    montarMenuMobile();

    const agora = () => new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    document.getElementById('horaBoasVindas').textContent = agora();

    let aguardando = false;

    /** Cria uma mensagem. O texto entra via textContent (nunca innerHTML) — sem risco de XSS. */
    function adicionar(autor, texto, hora) {
        const msg = document.createElement('div');
        msg.className = `msg msg-${autor}`;

        if (autor === 'bot') {
            const avatar = document.createElement('div');
            avatar.className = 'chat-avatar';
            avatar.innerHTML = '<i class="bi bi-robot"></i>';
            msg.appendChild(avatar);
        }

        const balao = document.createElement('div');
        balao.className = 'msg-balao';
        const p = document.createElement('p');
        p.textContent = texto;
        const t = document.createElement('time');
        t.textContent = hora || agora();
        balao.append(p, t);
        msg.appendChild(balao);

        lista.appendChild(msg);
        lista.scrollTop = lista.scrollHeight;
        return msg;
    }

    function mostrarDigitando() {
        const msg = document.createElement('div');
        msg.className = 'msg msg-bot msg-digitando';
        msg.innerHTML = '<div class="chat-avatar"><i class="bi bi-robot"></i></div>'
            + '<div class="msg-balao"><span class="ponto"></span><span class="ponto"></span><span class="ponto"></span></div>';
        lista.appendChild(msg);
        lista.scrollTop = lista.scrollHeight;
        return msg;
    }

    async function perguntar(texto) {
        texto = texto.trim();
        if (!texto || aguardando) return;

        aguardando = true;
        botao.disabled = true;
        adicionar('usuario', texto);
        entrada.value = '';
        const digitando = mostrarDigitando();

        try {
            const resp = await fetch('/assistente/perguntar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                credentials: 'same-origin',
                body: JSON.stringify({ mensagem: texto }),
            });

            if (resp.status === 401) {
                window.location.href = '/login';
                return;
            }

            const json = await resp.json().catch(() => null);
            digitando.remove();

            if (json && json.sucesso) {
                adicionar('bot', json.resposta, json.hora);
            } else {
                adicionar('bot', (json && json.mensagem) || 'Não consegui responder agora. Tente novamente.');
            }
        } catch (e) {
            digitando.remove();
            adicionar('bot', 'Falha de conexão. Verifique sua internet e tente novamente.');
        } finally {
            aguardando = false;
            botao.disabled = false;
            entrada.focus();
        }
    }

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        perguntar(entrada.value);
    });

    document.querySelectorAll('.pergunta-rapida').forEach((b) => {
        b.addEventListener('click', () => perguntar(b.dataset.pergunta || ''));
    });
});

/** Abre/fecha a sidebar no mobile. */
function montarMenuMobile() {
    const botao = document.getElementById('btnMenuMobile');
    const sidebar = document.getElementById('sidebar');
    if (!botao || !sidebar) return;

    botao.addEventListener('click', () => sidebar.classList.toggle('aberta'));
    document.addEventListener('click', (evento) => {
        if (!sidebar.contains(evento.target) && !botao.contains(evento.target)) {
            sidebar.classList.remove('aberta');
        }
    });
}
