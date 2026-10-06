// =========================================================
// RELATÓRIOS — filtros e atalhos de período
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    montarMenuMobile();
    montarFiltros();
});

/** Abre/fecha a sidebar no mobile. */
function montarMenuMobile() {
    const botao = document.getElementById('btnMenuMobile');
    const sidebar = document.getElementById('sidebar');
    if (!botao || !sidebar) return;

    botao.addEventListener('click', () => sidebar.classList.toggle('aberta'));

    document.addEventListener('click', (evento) => {
        const fora = !sidebar.contains(evento.target) && !botao.contains(evento.target);
        if (fora) sidebar.classList.remove('aberta');
    });
}

/** yyyy-mm-dd no horário local (toISOString usaria UTC e poderia errar o dia). */
function ymd(data) {
    const m = String(data.getMonth() + 1).padStart(2, '0');
    const d = String(data.getDate()).padStart(2, '0');
    return `${data.getFullYear()}-${m}-${d}`;
}

function intervaloDoAtalho(atalho) {
    const hoje = new Date();
    switch (atalho) {
        case 'hoje':
            return [hoje, hoje];
        case '7d':
            return [new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - 6), hoje];
        case '30d':
            return [new Date(hoje.getFullYear(), hoje.getMonth(), hoje.getDate() - 29), hoje];
        case 'mes':
            return [new Date(hoje.getFullYear(), hoje.getMonth(), 1), hoje];
        case 'mesant':
            return [
                new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1),
                new Date(hoje.getFullYear(), hoje.getMonth(), 0),
            ];
        default:
            return null;
    }
}

function montarFiltros() {
    const form = document.getElementById('formFiltros');
    const preset = document.getElementById('presetPeriodo');
    const de = document.getElementById('campoDe');
    const ate = document.getElementById('campoAte');
    if (!form) return;

    // Atalhos de período (seletor do cabeçalho)
    if (preset && de && ate) {
        preset.addEventListener('change', () => {
            const intervalo = intervaloDoAtalho(preset.value);
            if (!intervalo) return;
            de.value = ymd(intervalo[0]);
            ate.value = ymd(intervalo[1]);
            form.requestSubmit();
        });
    }

    // Selects e datas aplicam o filtro na hora; o texto só ao enviar/Enter.
    form.querySelectorAll('select[name="categoria"], input[type="date"]').forEach((campo) => {
        campo.addEventListener('change', () => form.requestSubmit());
    });
}
