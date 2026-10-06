<?php

/**
 * Monta os dados das telas Painel, Análise mensal e Pessoas a partir do banco.
 * Os arrays devolvidos têm o mesmo formato que as views já consumiam.
 */
class Painel
{
    private const CORES_REFEICAO = ['#FFC107', '#0D0D0D', '#6B7280', '#D1D5DB'];

    private int $empresaId;
    private DateTimeImmutable $hoje;
    private Acesso $acesso;
    private Producao $producao;
    private Previsao $previsao;

    public function __construct(int $empresaId, ?DateTimeImmutable $hoje = null)
    {
        $this->empresaId = $empresaId;
        $this->hoje      = $hoje ?? new DateTimeImmutable('today');
        $this->acesso    = new Acesso();
        $this->producao  = new Producao();
        $this->previsao  = new Previsao($empresaId, $this->hoje);
    }

    /** Variação percentual; null quando não há base de comparação. */
    private static function variacao(float $atual, float $anterior): ?float
    {
        return $anterior > 0 ? round(($atual - $anterior) / $anterior * 100, 1) : null;
    }

    // ─── PAINEL ──────────────────────────────────────────────────────────────

    public function painel(): array
    {
        $hoje = $this->hoje;
        $inicioMes = $hoje->modify('first day of this month');
        $diasDecorridos = (int) $hoje->format('j');
        $inicioMesAnt = $inicioMes->modify('-1 month');
        $fimMesAntMesmoDia = $inicioMesAnt->modify('+' . ($diasDecorridos - 1) . ' days');
        $d = fn(DateTimeImmutable $x) => $x->format('Y-m-d');

        // Funcionários ativos × ativos no início do mês
        $ativos = $this->acesso->contarFuncionariosAtivos($this->empresaId);
        $ativosAntes = $this->acesso->contarFuncionariosAtivos($this->empresaId, $d($inicioMes));

        // Acessos (entradas) do mês até hoje × mesmo período do mês anterior
        $acessos = $this->acesso->contarEntradas($this->empresaId, $d($inicioMes), $d($hoje));
        $acessosAnt = $this->acesso->contarEntradas($this->empresaId, $d($inicioMesAnt), $d($fimMesAntMesmoDia));

        // Refeições previstas para os próximos 7 dias × refeições dos 7 dias anteriores (pessoas reais × proporção)
        $prox = $this->previsao->proximosDias(7);
        $refPrev = array_sum(array_column($prox, 'total_refeicoes'));
        $pessoas7 = array_sum($this->acesso->pessoasPorDia($this->empresaId, $d($hoje->modify('-7 days')), $d($hoje->modify('-1 day'))));
        $refAnt = 0;
        if ($prox) {
            $totPessoasProx = array_sum(array_column($prox, 'pessoas'));
            $razao = $totPessoasProx > 0 ? $refPrev / $totPessoasProx : 0;
            $refAnt = $pessoas7 * $razao;
        }

        // Acurácia atual × janela anterior
        $prec = $this->previsao->precisao();
        $precAnt = $this->previsao->precisao(30);

        $cards = [
            ['chave' => 'usuarios', 'label' => 'Usuários Ativos', 'valor' => $ativos,
                'variacao' => self::variacao($ativos, $ativosAntes), 'periodo' => 'este mês', 'icone' => 'icone bi-person-fill'],
            ['chave' => 'acessos', 'label' => 'Acessos Realizados', 'valor' => $acessos,
                'variacao' => self::variacao($acessos, $acessosAnt), 'periodo' => 'este mês', 'icone' => 'icone bi-door-open-fill'],
            ['chave' => 'refeicoes', 'label' => 'Refeições Previstas', 'valor' => $refPrev,
                'variacao' => self::variacao($refPrev, $refAnt), 'periodo' => 'próx. 7 dias', 'icone' => 'icone bi-cup-hot-fill'],
            ['chave' => 'acuracia', 'label' => 'Acurácia da Previsão', 'valor' => $prec['precisao'] ?? null, 'sufixo' => '%',
                'variacao' => ($prec && $precAnt) ? round($prec['precisao'] - $precAnt['precisao'], 1) : null,
                'periodo' => 'vs. 30 dias antes', 'icone' => 'icone bi-graph-up-arrow'],
        ];

        // Previsto × produzido (kg) — últimos 15 dias com produção registrada
        $serie = $this->producao->serieDiaria($this->empresaId, $d($hoje->modify('-14 days')), $d($hoje));
        ksort($serie);
        $demanda = ['labels' => [], 'previsto' => [], 'realizado' => []];
        foreach ($serie as $dia => $s) {
            $demanda['labels'][]    = date('d/m', strtotime($dia));
            $demanda['previsto'][]  = round($s['previsto'], 1);
            $demanda['realizado'][] = round($s['produzido'], 1);
        }

        return [
            'cardsResumo'          => $cards,
            'graficoDemanda'       => $demanda,
            'graficoDistribuicao'  => $this->distribuicaoRefeicoes(),
            'previsaoProximasHoras' => $this->proximasHoras(),
            'alertas'              => $this->previsao->alertas(),
        ];
    }

    /** % da produção (kg, últimos 30 dias) por tipo; sem produção usa as metas cadastradas. */
    private function distribuicaoRefeicoes(): array
    {
        $kg = $this->producao->produzidoPorTipo(
            $this->empresaId, $this->hoje->modify('-30 days')->format('Y-m-d'), $this->hoje->format('Y-m-d')
        );
        if (!array_sum($kg)) {
            $kg = array_map('floatval', $this->producao->metasDiarias($this->empresaId));
        }

        $total = array_sum($kg);
        $labels = [];
        $valores = [];
        if ($total > 0) {
            $nomes = ['cafe' => 'Café', 'almoco' => 'Almoço', 'jantar' => 'Jantar', 'ceia' => 'Ceia'];
            foreach ($nomes as $tipo => $rotulo) {
                if (!empty($kg[$tipo])) {
                    $labels[]  = $rotulo;
                    $valores[] = (int) round($kg[$tipo] / $total * 100);
                }
            }
        }

        return ['labels' => $labels, 'valores' => $valores, 'cores' => array_slice(self::CORES_REFEICAO, 0, count($labels))];
    }

    /**
     * Próximas 4 horas: média de entradas por hora nas últimas semanas (mesmo dia
     * da semana). Status compara a hora com a média das horas movimentadas.
     */
    private function proximasHoras(): array
    {
        $media = $this->acesso->mediaEntradasPorHora($this->empresaId, $this->hoje);
        if (!$media) {
            return [];
        }
        $ref = array_sum($media) / count($media);
        $hora = (int) date('G');

        $saida = [];
        for ($i = 1; $i <= 4; $i++) {
            $h = ($hora + $i) % 24;
            $v = $media[$h] ?? 0;
            $status = $v >= $ref * 1.25 ? 'alto' : ($v <= $ref * 0.6 ? 'baixo' : 'normal');
            $saida[] = ['hora' => sprintf('%02d:00', $h), 'status' => $status, 'pessoas' => (int) round($v)];
        }
        // Fora do expediente (tudo zero) não há o que prever.
        return array_sum(array_column($saida, 'pessoas')) > 0 ? $saida : [];
    }

    // ─── ANÁLISE MENSAL ──────────────────────────────────────────────────────

    /** Meses disponíveis no seletor (mês atual + 11 anteriores): ['2026-10' => 'Outubro 2026']. */
    public function mesesDisponiveis(): array
    {
        $out = [];
        $m = $this->hoje->modify('first day of this month');
        for ($i = 0; $i < 12; $i++) {
            $out[$m->format('Y-m')] = Datas::mesAno($m);
            $m = $m->modify('-1 month');
        }
        return $out;
    }

    public function analiseMensal(string $mes): array
    {
        $inicio = DateTimeImmutable::createFromFormat('!Y-m-d', $mes . '-01') ?: $this->hoje->modify('first day of this month');
        $fimMes = $inicio->modify('last day of this month');
        $fim = $fimMes > $this->hoje ? $this->hoje : $fimMes; // não passa de hoje
        $d = fn(DateTimeImmutable $x) => $x->format('Y-m-d');

        $pessoas = $this->acesso->pessoasPorDia($this->empresaId, $d($inicio), $d($fim));
        $prod = $this->producao->serieDiaria($this->empresaId, $d($inicio), $d($fim));
        $tot = $this->producao->totais($this->empresaId, $d($inicio), $d($fim));

        // Mês anterior, mesmo número de dias, para as variações
        $ini0 = $inicio->modify('-1 month');
        $fim0 = $ini0->modify('+' . ((int) $fim->format('j') - 1) . ' days');
        $fim0 = min($fim0, $ini0->modify('last day of this month'));
        $tot0 = $this->producao->totais($this->empresaId, $d($ini0), $d($fim0));
        $pessoas0 = $this->acesso->pessoasPorDia($this->empresaId, $d($ini0), $d($fim0));

        $mediaPessoas = $pessoas ? array_sum($pessoas) / count($pessoas) : 0;
        $mediaPessoas0 = $pessoas0 ? array_sum($pessoas0) / count($pessoas0) : 0;
        $pctDesp = $tot['produzido'] > 0 ? $tot['desperdicado'] / $tot['produzido'] * 100 : 0;

        $varProd = self::variacao($tot['produzido'], $tot0['produzido']);
        $varDesp = self::variacao($tot['desperdicado'], $tot0['desperdicado']);
        $varPess = self::variacao($mediaPessoas, $mediaPessoas0);

        $cards = [
            ['chave' => 'produzido', 'label' => 'Total Produzido', 'valor' => round($tot['produzido']), 'sufixo' => ' kg',
                'variacao' => $varProd, 'positiva' => ($varProd ?? 0) >= 0, 'periodo' => 'vs. mês anterior',
                'extra' => $tot['dias'] ? 'Média: ' . Previsao::n($tot['produzido'] / $tot['dias'], 1) . ' kg/dia' : 'Sem produção registrada',
                'icone' => 'bi-graph-up-arrow'],
            ['chave' => 'desperdicado', 'label' => 'Total Desperdiçado', 'valor' => round($tot['desperdicado']), 'sufixo' => ' kg',
                'variacao' => $varDesp, 'positiva' => ($varDesp ?? 0) <= 0, 'periodo' => 'vs. mês anterior',
                'extra' => Previsao::n($pctDesp, 1) . '% do total', 'icone' => 'bi-graph-down-arrow'],
            ['chave' => 'pessoas', 'label' => 'Média de Pessoas', 'valor' => round($mediaPessoas), 'sufixo' => '',
                'variacao' => $varPess, 'positiva' => ($varPess ?? 0) >= 0, 'periodo' => 'vs. mês anterior',
                'extra' => 'Por dia com movimento', 'icone' => 'bi-people-fill'],
        ];

        // Séries para os gráficos: um ponto por dia do mês (até hoje)
        $labels = [];
        $gPessoas = [];
        $gProd = [];
        $gDesp = [];
        for ($dia = $inicio; $dia <= $fim; $dia = $dia->modify('+1 day')) {
            $k = $d($dia);
            $labels[]   = (int) $dia->format('j');
            $gPessoas[] = $pessoas[$k] ?? 0;
            $gProd[]    = round($prod[$k]['produzido'] ?? 0, 1);
            $gDesp[]    = round($prod[$k]['desperdicado'] ?? 0, 1);
        }

        return [
            'inicio' => $inicio,
            'cardsResumo' => $cards,
            'graficoPessoasDia' => ['labels' => $labels, 'valores' => $gPessoas],
            'graficoProducao'   => ['labels' => $labels, 'valores' => $gProd],
            'graficoDesperdicio' => ['labels' => $labels, 'valores' => $gDesp],
            'insights' => $this->insights($pessoas, $prod, $tot),
        ];
    }

    private function insights(array $pessoas, array $prod, array $tot): array
    {
        $ins = [];

        if ($pessoas) {
            arsort($pessoas);
            $dia = array_key_first($pessoas);
            $ins[] = ['label' => 'Dia com maior fluxo', 'valor' => 'Dia ' . (int) substr($dia, 8) . ' - ' . $pessoas[$dia] . ' pessoas', 'icone' => 'trend-up'];
        } else {
            $ins[] = ['label' => 'Dia com maior fluxo', 'valor' => 'Sem dados', 'icone' => 'trend-up'];
        }

        $comDesp = array_filter($prod, fn($s) => $s['produzido'] > 0);
        if ($comDesp) {
            uasort($comDesp, fn($a, $b) => $a['desperdicado'] <=> $b['desperdicado']);
            $dia = array_key_first($comDesp);
            $ins[] = ['label' => 'Dia com menor desperdício', 'valor' => 'Dia ' . (int) substr($dia, 8) . ' - ' . Previsao::n($comDesp[$dia]['desperdicado'], 1) . ' kg', 'icone' => 'trend-up'];
        } else {
            $ins[] = ['label' => 'Dia com menor desperdício', 'valor' => 'Sem dados', 'icone' => 'trend-up'];
        }

        // Economia possível: quanto de desperdício estaria acima da meta
        if ($tot['produzido'] > 0) {
            $excesso = $tot['desperdicado'] - $tot['produzido'] * Previsao::META_DESPERDICIO / 100;
            $ins[] = ['label' => 'Economia possível', 'valor' => $excesso > 0 ? '-' . Previsao::n($excesso, 1) . ' kg' : 'Meta atingida', 'icone' => 'pulse'];
        } else {
            $ins[] = ['label' => 'Economia possível', 'valor' => 'Sem dados', 'icone' => 'pulse'];
        }

        // Tendência: 2ª metade do mês × 1ª metade (pessoas por dia)
        $tend = 'Sem dados';
        if (count($pessoas) >= 6) {
            ksort($pessoas);
            $v = array_values($pessoas);
            $meio = intdiv(count($v), 2);
            $a = array_sum(array_slice($v, 0, $meio)) / $meio;
            $b = array_sum(array_slice($v, $meio)) / (count($v) - $meio);
            $var = $a > 0 ? ($b - $a) / $a * 100 : 0;
            $tend = abs($var) < 3 ? 'Estável' : ($var > 0 ? 'Em alta (+' . Previsao::n($var, 0) . '%)' : 'Em queda (' . Previsao::n($var, 0) . '%)');
        }
        $ins[] = ['label' => 'Tendência', 'valor' => $tend, 'icone' => 'trend-up'];

        return $ins;
    }

    // ─── PESSOAS ─────────────────────────────────────────────────────────────

    public function pessoas(): array
    {
        $presentes = $this->acesso->presentesAgora($this->empresaId, $this->hoje->format('Y-m-d'));

        $por = ['operador' => [], 'supervisor' => [], 'prestador' => []];
        foreach ($presentes as $p) {
            $por[$p['categoria']][] = $p;
        }

        $cards = [
            ['chave' => 'total', 'label' => 'Total Presente', 'valor' => count($presentes), 'extra' => 'Colaboradores no local', 'icone' => 'bi-people-fill'],
            ['chave' => 'operadores', 'label' => 'Operadores de Produção', 'valor' => count($por['operador']), 'extra' => 'presentes agora', 'icone' => 'bi-gear-fill'],
            ['chave' => 'supervisores', 'label' => 'Supervisores', 'valor' => count($por['supervisor']), 'extra' => 'presentes agora', 'icone' => 'bi-person-check-fill'],
            ['chave' => 'prestadores', 'label' => 'Prestadores de Serviço', 'valor' => count($por['prestador']), 'extra' => 'presentes agora', 'icone' => 'bi-briefcase-fill'],
        ];

        $defs = [
            'operador'   => ['operadores', 'Operadores de Produção', 'gear'],
            'supervisor' => ['supervisores', 'Supervisores', 'badge'],
            'prestador'  => ['prestadores', 'Prestadores de Serviço', 'briefcase'],
        ];

        $grupos = [];
        foreach ($defs as $cat => [$chave, $titulo, $icone]) {
            $lista = $por[$cat];
            $grupos[] = [
                'chave'     => $chave,
                'titulo'    => $titulo,
                'icone'     => $icone,
                'presentes' => count($lista),
                'pessoas'   => array_map(fn($p) => [
                    'nome'    => $p['nome'],
                    'funcao'  => $p['cargo'],
                    'entrada' => date('H:i', strtotime($p['entrada'])),
                ], $lista),
                'restantes' => 0, // a lista já traz todos os presentes
            ];
        }

        return ['cardsResumo' => $cards, 'grupos' => $grupos];
    }

    public function totalAlertas(): int
    {
        return count($this->previsao->alertas());
    }
}
