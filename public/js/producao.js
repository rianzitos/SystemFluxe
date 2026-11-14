// Tela Produção (/producao): quilos produzidos e desperdiçados por refeição em um dia.
// Lê e grava pelo ProducaoController (GET /producao/dados?data=, POST /producao/salvar). O que é salvo vai para a
// mesma tabela do painel, da análise mensal e da previsão, então elas se atualizam sozinhas.
(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const num = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
    const kg = (v) => (v === null || v === undefined ? '—' : num.format(v) + ' kg');
    const pct = (v) => (v === null || v === undefined ? '—' : num.format(v) + '%');

    let dia = JSON.parse($('dadosProducao').textContent);
    let alterado = false;
    let carregando = false;

    // ─── Números digitados ("12,5" ou "12.5") ───────────────────────────────

    function lerKg(texto) {
        const t = String(texto).trim().replace(/\s/g, '').replace(',', '.');
        if (t === '') return null;
        if (!/^\d+(\.\d+)?$/.test(t)) return NaN;
        return Number(t);
    }

    function paraCampo(v) {
        return v === null || v === undefined ? '' : String(v).replace('.', ',');
    }

    function formatarData(ymd, comDiaSemana) {
        const [a, m, d] = ymd.split('-').map(Number);
        const data = new Date(a, m - 1, d);
        const texto = data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
        if (!comDiaSemana) return texto;
        const semana = data.toLocaleDateString('pt-BR', { weekday: 'short' }).replace('.', '');
        return semana.charAt(0).toUpperCase() + semana.slice(1) + ', ' + texto;
    }

    function somarDias(ymd, n) {
        const [a, m, d] = ymd.split('-').map(Number);
        const data = new Date(a, m - 1, d + n);
        return [data.getFullYear(), String(data.getMonth() + 1).padStart(2, '0'), String(data.getDate()).padStart(2, '0')].join('-');
    }

    // ─── Desenho ────────────────────────────────────────────────────────────

    function desenhar() {
        $('dataProducao').value = dia.data;
        $('dataProducao').max = dia.hoje;
        $('diaSeguinte').disabled = dia.data >= dia.hoje;
        $('irHoje').hidden = dia.data === dia.hoje;
        $('dicaBalanca').hidden = !dia.temBalanca;
        $('tituloRegistro').textContent = dia.data === dia.hoje ? 'Registrar produção de hoje' : 'Produção de ' + formatarData(dia.data, true);

        const corpo = $('linhasProducao');
        corpo.innerHTML = '';
        for (const r of dia.refeicoes) {
            const tr = document.createElement('tr');
            tr.dataset.tipo = r.tipo;
            tr.innerHTML = `
                <th scope="row" data-rotulo="Refeição"></th>
                <td data-rotulo="Previsto" class="col-previsto"></td>
                <td data-rotulo="Produzido (kg)">
                    <input type="text" inputmode="decimal" autocomplete="off" class="campo-kg" name="produzido" placeholder="0,0">
                </td>
                <td data-rotulo="Desperdiçado (kg)">
                    <input type="text" inputmode="decimal" autocomplete="off" class="campo-kg" name="desperdicado" placeholder="0,0">
                </td>
                <td data-rotulo="Desperdício" class="col-percentual">—</td>`;
            tr.querySelector('th').textContent = r.rotulo;
            tr.querySelector('.col-previsto').textContent = kg(r.previsto);
            const [produzido, desperdicado] = tr.querySelectorAll('input');
            produzido.value = paraCampo(r.produzido);
            desperdicado.value = paraCampo(r.desperdicado);
            produzido.setAttribute('aria-label', r.rotulo + ': quilos produzidos');
            desperdicado.setAttribute('aria-label', r.rotulo + ': quilos desperdiçados');
            corpo.appendChild(tr);
        }
        recalcular();
        desenharResumo();
        desenharHistorico();
        alterado = false;
    }

    /** % de cada linha e os totais, enquanto a pessoa digita. */
    function recalcular() {
        let totalProd = 0, totalDesp = 0, totalPrev = 0, temPrev = false;
        document.querySelectorAll('#linhasProducao tr').forEach((tr) => {
            const p = lerKg(tr.querySelector('[name=produzido]').value);
            const d = lerKg(tr.querySelector('[name=desperdicado]').value);
            const r = dia.refeicoes.find((x) => x.tipo === tr.dataset.tipo);
            if (r && r.previsto !== null) { totalPrev += r.previsto; temPrev = true; }
            const celula = tr.querySelector('.col-percentual');
            celula.className = 'col-percentual';
            if (p > 0 && d >= 0 && !Number.isNaN(d)) {
                const v = (d || 0) / p * 100;
                celula.textContent = pct(v);
                celula.classList.add(v > dia.metaDesperdicio ? 'acima-meta' : 'dentro-meta');
            } else {
                celula.textContent = '—';
            }
            if (p > 0) totalProd += p;
            if (d > 0) totalDesp += d;
        });
        $('totalPrevisto').textContent = temPrev ? kg(totalPrev) : '—';
        $('totalProduzido').textContent = totalProd > 0 ? kg(totalProd) : '—';
        $('totalDesperdicado').textContent = totalDesp > 0 ? kg(totalDesp) : '—';
        const tp = $('totalPercentual');
        tp.className = '';
        if (totalProd > 0) {
            const v = totalDesp / totalProd * 100;
            tp.textContent = pct(v);
            tp.classList.add(v > dia.metaDesperdicio ? 'acima-meta' : 'dentro-meta');
        } else {
            tp.textContent = '—';
        }
    }

    function desenharResumo() {
        const t = dia.totais;
        $('resumoProduzido').textContent = t.produzido > 0 ? kg(t.produzido) : '—';
        const previsto = dia.refeicoes.reduce((s, r) => s + (r.previsto || 0), 0);
        $('resumoPrevisto').textContent = previsto > 0 ? 'Previsto: ' + kg(previsto) : 'Sem previsão para o dia';
        $('resumoDesperdicado').textContent = t.desperdicado > 0 ? kg(t.desperdicado) : '—';
        $('resumoFonteDesperdicio').textContent = dia.temBalanca ? 'Pela balança ou digitado aqui' : 'Digitado aqui';
        $('resumoPercentual').textContent = pct(t.percentual);
        const meta = $('resumoMeta');
        meta.className = 'card-variacao';
        if (t.percentual === null) {
            meta.innerHTML = '<span>Meta: até ' + num.format(dia.metaDesperdicio) + '%</span>';
        } else {
            const acima = t.percentual > dia.metaDesperdicio;
            meta.classList.add(acima ? 'negativa' : 'positiva');
            meta.textContent = (acima ? 'Acima' : 'Dentro') + ' da meta (' + num.format(dia.metaDesperdicio) + '%)';
        }
        const s = dia.sugestao;
        $('resumoPessoas').textContent = s.pessoas > 0 ? num.format(s.pessoas) + ' pessoas' : '—';
        $('resumoSugestao').textContent = s.feriado ? 'Feriado: ' + s.feriado
            : (s.kg !== null ? 'Produzir cerca de ' + kg(s.kg) : 'Sem histórico suficiente');
    }

    function desenharHistorico() {
        const lista = $('listaHistorico');
        if (!dia.historico.length) {
            lista.innerHTML = '<p class="sem-dados">Nenhuma produção registrada nos últimos dias. Comece pelo formulário ao lado.</p>';
            return;
        }
        const maximo = Math.max(...dia.historico.map((h) => h.produzido), 1);
        lista.innerHTML = '';
        const ul = document.createElement('ul');
        ul.className = 'lista-historico';
        for (const h of dia.historico) {
            const li = document.createElement('li');
            const botao = document.createElement('button');
            botao.type = 'button';
            botao.className = 'item-historico' + (h.data === dia.data ? ' ativo' : '');
            botao.dataset.data = h.data;
            const acima = h.percentual !== null && h.percentual > dia.metaDesperdicio;
            botao.innerHTML = `
                <span class="historico-data"></span>
                <span class="historico-barra"><span style="width:${Math.round(h.produzido / maximo * 100)}%"></span></span>
                <span class="historico-kg"></span>
                <span class="historico-pct ${h.percentual === null ? '' : (acima ? 'acima-meta' : 'dentro-meta')}"></span>`;
            botao.querySelector('.historico-data').textContent = formatarData(h.data, true);
            botao.querySelector('.historico-kg').textContent = kg(h.produzido);
            botao.querySelector('.historico-pct').textContent = h.percentual === null ? '—' : pct(h.percentual);
            botao.title = 'Desperdiçado: ' + kg(h.desperdicado) + (h.previsto > 0 ? ' · Previsto: ' + kg(h.previsto) : '');
            botao.addEventListener('click', () => irPara(h.data));
            li.appendChild(botao);
            ul.appendChild(li);
        }
        lista.appendChild(ul);
    }

    function mostrarStatus(tipo, texto) {
        const s = $('statusProducao');
        s.className = 'producao-status producao-status--' + tipo;
        s.textContent = texto;
        s.hidden = false;
    }

    // ─── Trocar de dia ──────────────────────────────────────────────────────

    async function carregar(data, silencioso = false) {
        if (carregando) return;
        carregando = true;
        try {
            const r = await fetch('/producao/dados?data=' + encodeURIComponent(data), { headers: { Accept: 'application/json' } });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || !j.sucesso) throw new Error(j.mensagem || 'Não foi possível carregar o dia.');
            dia = j.dia;
            desenhar();
            if (!silencioso) $('statusProducao').hidden = true;
            history.replaceState(null, '', data === dia.hoje ? '/producao' : '/producao?data=' + data);
        } catch (e) {
            if (!silencioso) mostrarStatus('erro', e.message);
            $('dataProducao').value = dia.data;
        } finally {
            carregando = false;
        }
    }

    function irPara(data) {
        if (data === dia.data) return;
        if (alterado && !confirm('Você tem alterações não salvas neste dia. Descartar e trocar de dia?')) {
            $('dataProducao').value = dia.data;
            return;
        }
        carregar(data);
    }

    $('dataProducao').addEventListener('change', (ev) => {
        const v = ev.target.value;
        if (!v) return;
        irPara(v > dia.hoje ? dia.hoje : v);
    });
    $('diaAnterior').addEventListener('click', () => irPara(somarDias(dia.data, -1)));
    $('diaSeguinte').addEventListener('click', () => irPara(somarDias(dia.data, 1)));
    $('irHoje').addEventListener('click', () => irPara(dia.hoje));

    // ─── Salvar ─────────────────────────────────────────────────────────────

    $('formProducao').addEventListener('input', (ev) => {
        alterado = true;
        ev.target.removeAttribute('aria-invalid');
        recalcular();
    });

    $('formProducao').addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const itens = [];
        for (const tr of document.querySelectorAll('#linhasProducao tr')) {
            const r = dia.refeicoes.find((x) => x.tipo === tr.dataset.tipo);
            const campoP = tr.querySelector('[name=produzido]');
            const campoD = tr.querySelector('[name=desperdicado]');
            const p = lerKg(campoP.value);
            const d = lerKg(campoD.value);
            for (const [campo, valor] of [[campoP, p], [campoD, d]]) {
                if (Number.isNaN(valor)) {
                    campo.setAttribute('aria-invalid', 'true');
                    campo.focus();
                    mostrarStatus('erro', r.rotulo + ': digite só números, por exemplo 12,5.');
                    return;
                }
            }
            if (p !== null && d !== null && p > 0 && d > p) {
                campoD.setAttribute('aria-invalid', 'true');
                campoD.focus();
                mostrarStatus('erro', r.rotulo + ': o desperdício não pode ser maior que o produzido.');
                return;
            }
            // Campo apagado numa refeição já registrada = zera; em branco numa refeição nova = não mexe.
            if (p === null && d === null && !r.registrado) continue;
            itens.push({ tipo: r.tipo, produzido: p ?? (r.registrado ? 0 : null), desperdicado: d ?? (r.registrado ? 0 : null) });
        }
        if (!itens.length) {
            mostrarStatus('erro', 'Preencha os quilos produzidos de pelo menos uma refeição.');
            $('linhasProducao').querySelector('input')?.focus();
            return;
        }

        const botao = $('btnSalvarProducao');
        botao.disabled = true;
        mostrarStatus('info', 'Salvando…');
        try {
            const r = await fetch('/producao/salvar', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ data: dia.data, itens }),
            });
            const j = await r.json().catch(() => ({}));
            if (!r.ok || !j.sucesso) throw new Error(j.mensagem || 'Não foi possível salvar. Tente novamente.');
            dia = j.dia;
            desenhar();
            mostrarStatus('sucesso', 'Produção de ' + formatarData(dia.data, false) + ' salva. O painel e a análise mensal já usam os números novos.');
        } catch (e) {
            mostrarStatus('erro', e.message);
        } finally {
            botao.disabled = false;
        }
    });

    window.addEventListener('beforeunload', (ev) => {
        if (alterado) { ev.preventDefault(); ev.returnValue = ''; }
    });

    // Tempo real: a balança (ou outra pessoa) mudou algo → recarrega o dia, se ninguém estiver digitando aqui.
    document.addEventListener('aovivo:mudou', () => {
        if (!alterado) carregar(dia.data, true);
    });

    desenhar();
})();
