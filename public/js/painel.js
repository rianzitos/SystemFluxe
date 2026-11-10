// =========================================================
// PAINEL — interações e gráficos
// Lê os dados do <script id="dadosPainel"> (JSON, definido no painel.php). O aoVivo.js troca
// esses dados em tempo real: os gráficos são atualizados no lugar, sem recriar nem recarregar.
// e da biblioteca Chart.js carregada antes deste arquivo.
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    definirPeriodoAtual();
    montarMenuMobile();
    montarGraficoDemanda();
    montarGraficoDistribuicao();
});

/**
 * Preenche o texto "01/05/2024 - 31/05/2024" com o mês atual.
 * Depois, se vocês adicionarem um seletor de datas de verdade,
 * é só trocar essa função por quem lê o valor escolhido pelo usuário.
 */
function definirPeriodoAtual() {
    const label = document.getElementById('labelPeriodo');
    if (!label) return;

    const hoje = new Date();
    const primeiroDia = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
    const ultimoDia = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);

    const formatar = (data) => data.toLocaleDateString('pt-BR');
    label.textContent = `${formatar(primeiroDia)} - ${formatar(ultimoDia)}`;
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

/** Gráfico de linha: Previsto x Realizado. */
let graficoDemanda = null;
let graficoDistribuicao = null;

/** Dados dos gráficos que o servidor deixou no HTML. */
function lerDados(origem = document) {
    try {
        return JSON.parse(origem.querySelector('[data-ao-vivo-dados="painel"]').textContent);
    } catch (erro) {
        return null;
    }
}

function montarGraficoDemanda() {
    const canvas = document.getElementById('graficoDemanda');
    if (!canvas || typeof Chart === 'undefined') return;

    const dados = lerDados()?.demanda;
    if (!dados) return;

    graficoDemanda = new Chart(canvas, {
        type: 'line',
        data: {
            labels: dados.labels,
            datasets: [
                {
                    label: 'Previsto',
                    data: dados.previsto,
                    borderColor: '#FFC107',
                    backgroundColor: 'rgba(255, 193, 7, 0.12)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#FFC107',
                },
                {
                    label: 'Realizado',
                    data: dados.realizado,
                    borderColor: '#0D0D0D',
                    backgroundColor: 'rgba(13, 13, 13, 0.06)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#0D0D0D',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { color: '#EFEFF3' } },
                x: { grid: { display: false } },
            },
        },
    });
}

/** Gráfico de rosca: Distribuição de Refeições + legenda manual ao lado. */
function montarGraficoDistribuicao() {
    const canvas = document.getElementById('graficoDistribuicao');
    if (!canvas || typeof Chart === 'undefined') return;

    const dados = lerDados()?.distribuicao;
    if (!dados) return;

    graficoDistribuicao = new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels: dados.labels,
            datasets: [{
                data: dados.valores,
                backgroundColor: dados.cores,
                borderWidth: 0,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: { legend: { display: false } },
        },
    });

    desenharLegenda(dados);
}

/** Legenda manual da rosca (ao lado do gráfico). */
function desenharLegenda(dados) {
    const legenda = document.getElementById('legendaDistribuicao');
    if (!legenda) return;

    legenda.innerHTML = dados.labels.map((label, i) => `
        <li>
            <span class="ponto" style="background:${dados.cores[i]}"></span>
            ${label}
            <span class="valor">${dados.valores[i]}%</span>
        </li>
    `).join('');
}

// Tempo real: chegaram dados novos do servidor → os gráficos se ajustam no lugar.
document.addEventListener('aovivo:atualizado', (evento) => {
    const dados = evento.detail.dados.painel;
    if (!dados) return;

    if (graficoDemanda) {
        graficoDemanda.data.labels = dados.demanda.labels;
        graficoDemanda.data.datasets[0].data = dados.demanda.previsto;
        graficoDemanda.data.datasets[1].data = dados.demanda.realizado;
        graficoDemanda.update();
    }

    if (graficoDistribuicao) {
        graficoDistribuicao.data.labels = dados.distribuicao.labels;
        graficoDistribuicao.data.datasets[0].data = dados.distribuicao.valores;
        graficoDistribuicao.data.datasets[0].backgroundColor = dados.distribuicao.cores;
        graficoDistribuicao.update();
        desenharLegenda(dados.distribuicao);
    }
});
