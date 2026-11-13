// =========================================================
// ACESSOS — interações e gráficos
// Lê os dados do <script id="dadosAcessos"> (JSON, definido no acessos.php). O aoVivo.js troca
// esses dados em tempo real: os gráficos são atualizados no lugar, sem recriar nem recarregar.
// e da biblioteca Chart.js carregada antes deste arquivo.
// =========================================================

document.addEventListener('DOMContentLoaded', () => {
    definirDataHoje();
    definirPeriodoAtual();
    montarGraficoPessoasDia();
    montarGraficoProducao();
    montarGraficoDesperdicio();
});

/** Preenche "terça-feira, 25 de agosto de 2026" no subtítulo do cabeçalho. */
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

/**
 * Preenche o seletor de período com o mês/ano atual (ex: "Agosto 2026"),
 * no mesmo estilo do dropdown da referência visual.
 */
function definirPeriodoAtual() {
    const label = document.getElementById('labelPeriodo');
    if (!label) return;

    const hoje = new Date();
    const texto = hoje.toLocaleDateString('pt-BR', { month: 'long', year: 'numeric' });
    label.textContent = texto.charAt(0).toUpperCase() + texto.slice(1);
}

const graficos = {};

/** Dados dos gráficos que o servidor deixou no HTML. */
function lerDados(origem = document) {
    try {
        return JSON.parse(origem.querySelector('[data-ao-vivo-dados="acessos"]').textContent);
    } catch (erro) {
        return null;
    }
}

/** Gráfico de área: Número de Pessoas por Dia. */
function montarGraficoPessoasDia() {
    const canvas = document.getElementById('graficoPessoasDia');
    if (!canvas || typeof Chart === 'undefined') return;

    const dados = lerDados()?.pessoasDia;
    if (!dados) return;

    graficos.pessoasDia = new Chart(canvas, {
        type: 'line',
        data: {
            labels: dados.labels,
            datasets: [{
                label: 'Pessoas',
                data: dados.valores,
                borderColor: '#FFC107',
                backgroundColor: 'rgba(255, 193, 7, 0.18)',
                fill: true,
                tension: 0.35,
                pointRadius: 2,
                pointBackgroundColor: '#FFC107',
                borderWidth: 2,
            }],
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

/** Gráfico de barras: Produção Diária (kg) — roxo, igual à referência. */
function montarGraficoProducao() {
    const canvas = document.getElementById('graficoProducao');
    if (!canvas || typeof Chart === 'undefined') return;

    const dados = lerDados()?.producao;
    if (!dados) return;

    graficos.producao = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: dados.labels,
            datasets: [{
                label: 'Produção (kg)',
                data: dados.valores,
                backgroundColor: '#7C3AED',
                borderRadius: 3,
                maxBarThickness: 14,
            }],
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

/** Gráfico de barras: Desperdício Diário (kg) — vermelho, igual à referência. */
function montarGraficoDesperdicio() {
    const canvas = document.getElementById('graficoDesperdicio');
    if (!canvas || typeof Chart === 'undefined') return;

    const dados = lerDados()?.desperdicio;
    if (!dados) return;

    graficos.desperdicio = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: dados.labels,
            datasets: [{
                label: 'Desperdício (kg)',
                data: dados.valores,
                backgroundColor: '#EF4444',
                borderRadius: 3,
                maxBarThickness: 14,
            }],
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

// Tempo real: chegaram dados novos do servidor → os gráficos se ajustam no lugar.
document.addEventListener('aovivo:atualizado', (evento) => {
    const dados = evento.detail.dados.acessos;
    if (!dados) return;

    Object.entries(graficos).forEach(([chave, grafico]) => {
        grafico.data.labels = dados[chave].labels;
        grafico.data.datasets[0].data = dados[chave].valores;
        grafico.update();
    });
});
