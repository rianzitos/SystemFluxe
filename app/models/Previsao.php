<?php

/**
 * Motor de previsão do assistente virtual (PHP puro, sem IA externa).
 *
 * Método — médias ponderadas por dia da semana:
 *   1. Conta, no histórico de acessos (logs_acesso), quantas pessoas
 *      diferentes entraram em cada dia.
 *   2. Para prever um dia, usa as últimas N ocorrências do MESMO dia da
 *      semana (ex.: as últimas 6 sextas), dando mais peso às mais recentes.
 *   3. Aplica um fator de tendência (últimos 7 dias contra os 28 anteriores).
 *   4. Converte pessoas em refeições (proporção da meta cadastrada) e em kg
 *      (kg consumidos por pessoa, vindos da produção real menos o desperdício).
 *   5. Sem histórico suficiente, cai para os dados do cadastro da empresa
 *      (qtd_colaboradores / meta de refeições) e avisa a baixa confiança.
 *
 * A "precisão" é medida de verdade: refaz a previsão dos últimos 30 dias
 * usando só o que se sabia antes de cada dia e compara com o realizado.
 */
class Previsao
{
    private const SEMANAS_HISTORICO = 8;
    private const DIAS_PRECISAO = 30;
    /** Meta de desperdício (% da produção) usada nos alertas. */
    public const META_DESPERDICIO = 8.0;

    private PDO $db;
    private Acesso $acesso;
    private Producao $producao;
    private int $empresaId;
    private DateTimeImmutable $hoje;

    private ?array $historico = null;       // ['Y-m-d' => pessoas]
    private ?array $empresa = null;
    private ?array $cacheKgPessoa = null;

    public function __construct(int $empresaId, ?DateTimeImmutable $hoje = null)
    {
        $this->db        = Database::connect();
        $this->acesso    = new Acesso();
        $this->producao  = new Producao();
        $this->empresaId = $empresaId;
        $this->hoje      = $hoje ?? new DateTimeImmutable('today');
    }

    // ─── Dados base ──────────────────────────────────────────────────────────

    private function empresa(): array
    {
        if ($this->empresa === null) {
            $stmt = $this->db->prepare('SELECT qtd_colaboradores, possui_refeitorio, horario_funcionamento FROM empresas WHERE id = ?');
            $stmt->execute([$this->empresaId]);
            $this->empresa = $stmt->fetch() ?: ['qtd_colaboradores' => 0, 'possui_refeitorio' => 0, 'horario_funcionamento' => ''];
        }
        return $this->empresa;
    }

    /** Pessoas por dia dos últimos ~12 semanas (até ontem), indexado por 'Y-m-d'. */
    private function historico(): array
    {
        if ($this->historico === null) {
            $de  = $this->hoje->modify('-84 days')->format('Y-m-d');
            $ate = $this->hoje->format('Y-m-d'); // inclui hoje (parcial) só para exibição; previsões ignoram >= hoje
            $this->historico = $this->acesso->pessoasPorDia($this->empresaId, $de, $ate);
        }
        return $this->historico;
    }

    public function temHistorico(): bool
    {
        return count($this->historico()) >= 3;
    }

    // ─── Previsão de pessoas ─────────────────────────────────────────────────

    /**
     * Previsão de pessoas para um dia.
     * Retorna ['pessoas'=>int, 'confianca'=>'alta|media|baixa', 'base'=>string,
     *          'amostras'=>int, 'feriado'=>?string, 'fim_de_semana'=>bool].
     */
    public function pessoasNoDia(DateTimeImmutable $dia): array
    {
        return $this->preverPessoas($dia, $this->historico(), $this->hoje);
    }

    /**
     * Núcleo do cálculo, separado para poder ser reutilizado no teste de precisão
     * (com um "hoje" e um histórico recortados no passado).
     */
    private function preverPessoas(DateTimeImmutable $dia, array $historico, DateTimeImmutable $hoje): array
    {
        $feriado = Datas::feriado($dia);
        $dow = (int) $dia->format('w');
        $fimDeSemana = $dow === 0 || $dow === 6;

        // Amostras: mesmo dia da semana, só dias já passados e com movimento.
        $amostras = [];
        for ($i = 1; $i <= self::SEMANAS_HISTORICO; $i++) {
            $d = $dia->modify("-{$i} weeks");
            if ($d >= $hoje) {
                continue;
            }
            $k = $d->format('Y-m-d');
            if (isset($historico[$k]) && $historico[$k] > 0) {
                $amostras[] = ['valor' => $historico[$k], 'peso' => self::SEMANAS_HISTORICO - $i + 1];
            }
        }

        $n = count($amostras);
        $base = 'historico';

        if ($n > 0) {
            $soma = 0.0;
            $pesos = 0.0;
            foreach ($amostras as $a) {
                $soma  += $a['valor'] * $a['peso'];
                $pesos += $a['peso'];
            }
            $previsto = $soma / $pesos;
            $previsto *= $this->fatorTendencia($historico, $hoje);
        } else {
            // Sem nenhuma amostra do mesmo dia da semana.
            $media = $this->mediaDiasUteis($historico, $hoje);
            if ($media !== null && !$fimDeSemana) {
                $previsto = $media;
                $base = 'media_geral';
            } elseif ($media !== null) {
                // Houve movimento nos dias úteis, mas nunca nesse dia da semana → fim de semana quieto.
                $previsto = 0;
                $base = 'media_geral';
            } else {
                $previsto = $fimDeSemana ? 0 : (float) $this->empresa()['qtd_colaboradores'] * 0.85;
                $base = 'cadastro';
            }
        }

        if ($feriado !== null) {
            $previsto *= 0.15; // feriado: operação mínima
        }

        $confianca = $n >= 5 ? 'alta' : ($n >= 2 ? 'media' : 'baixa');

        return [
            'pessoas'       => (int) round($previsto),
            'confianca'     => $confianca,
            'base'          => $base,
            'amostras'      => $n,
            'feriado'       => $feriado,
            'fim_de_semana' => $fimDeSemana,
        ];
    }

    /** Média de pessoas em dias úteis com movimento; null se não há dados. */
    private function mediaDiasUteis(array $historico, DateTimeImmutable $hoje): ?float
    {
        $vals = [];
        foreach ($historico as $k => $v) {
            $dt = new DateTimeImmutable($k);
            $dow = (int) $dt->format('w');
            if ($dt < $hoje && $v > 0 && $dow >= 1 && $dow <= 5) {
                $vals[] = $v;
            }
        }
        return $vals ? array_sum($vals) / count($vals) : null;
    }

    /**
     * Fator de tendência: média diária dos últimos 7 dias úteis com movimento
     * ÷ média dos 28 anteriores. Limitado a ±15% para não exagerar.
     */
    private function fatorTendencia(array $historico, DateTimeImmutable $hoje): float
    {
        $rec = [];
        $ant = [];
        foreach ($historico as $k => $v) {
            $dt = new DateTimeImmutable($k);
            if ($dt >= $hoje || $v <= 0) {
                continue;
            }
            $dias = (int) $dt->diff($hoje)->days;
            if ($dias <= 7) {
                $rec[] = $v;
            } elseif ($dias <= 35) {
                $ant[] = $v;
            }
        }
        if (count($rec) < 3 || count($ant) < 5) {
            return 1.0;
        }
        $fator = (array_sum($rec) / count($rec)) / (array_sum($ant) / count($ant));
        return max(0.85, min(1.15, $fator));
    }

    // ─── Refeições e quilos ──────────────────────────────────────────────────

    /**
     * Proporção das pessoas que fazem cada refeição, a partir da meta cadastrada
     * (media_diaria de cada tipo ÷ colaboradores). Sem meta → vazio.
     */
    private function proporcoesRefeicao(): array
    {
        $metas = $this->producao->metasDiarias($this->empresaId);
        $base = max(1, (int) $this->empresa()['qtd_colaboradores']);
        $p = [];
        foreach ($metas as $tipo => $media) {
            if ($media > 0) {
                $p[$tipo] = min(1.0, $media / $base);
            }
        }
        return $p;
    }

    /**
     * kg consumidos por pessoa (produzido − desperdiçado ÷ pessoas), média dos
     * últimos 60 dias que têm produção E movimento registrados. null sem dados.
     */
    private function kgPorPessoa(): ?array
    {
        if ($this->cacheKgPessoa !== null) {
            return $this->cacheKgPessoa ?: null;
        }

        $de  = $this->hoje->modify('-60 days')->format('Y-m-d');
        $ate = $this->hoje->modify('-1 day')->format('Y-m-d');
        $serie = $this->producao->serieDiaria($this->empresaId, $de, $ate);
        $hist = $this->historico();

        $consumo = 0.0; $prod = 0.0; $desp = 0.0; $pessoas = 0;
        foreach ($serie as $dia => $s) {
            if (($hist[$dia] ?? 0) > 0 && $s['produzido'] > 0) {
                $consumo += max(0, $s['produzido'] - $s['desperdicado']);
                $prod    += $s['produzido'];
                $desp    += $s['desperdicado'];
                $pessoas += $hist[$dia];
            }
        }

        if ($pessoas === 0) {
            $this->cacheKgPessoa = [];
            return null;
        }

        return $this->cacheKgPessoa = [
            'kg_consumo'   => $consumo / $pessoas,
            'kg_producao'  => $prod / $pessoas,
            'desperdicio'  => $prod > 0 ? $desp / $prod * 100 : 0.0,
        ];
    }

    /**
     * Previsão completa de um dia: pessoas, refeições por tipo e kg a produzir.
     */
    public function previsaoDoDia(DateTimeImmutable $dia): array
    {
        $p = $this->pessoasNoDia($dia);
        $pessoas = $p['pessoas'];

        $refeicoes = [];
        foreach ($this->proporcoesRefeicao() as $tipo => $prop) {
            $refeicoes[$tipo] = (int) round($pessoas * $prop);
        }

        $kg = null;
        $kgp = $this->kgPorPessoa();
        if ($kgp !== null && $pessoas > 0) {
            // Quilos necessários = o que as pessoas realmente consomem (sem o desperdício histórico).
            $kg = round($pessoas * $kgp['kg_consumo'], 1);
        }

        return $p + [
            'data'      => $dia,
            'refeicoes' => $refeicoes,
            'total_refeicoes' => array_sum($refeicoes),
            'kg'        => $kg,
        ];
    }

    /** Previsão dos próximos $dias dias a partir de amanhã. */
    public function proximosDias(int $dias = 7): array
    {
        $out = [];
        for ($i = 1; $i <= $dias; $i++) {
            $out[] = $this->previsaoDoDia($this->hoje->modify("+{$i} days"));
        }
        return $out;
    }

    // ─── Precisão (backtest) ─────────────────────────────────────────────────

    /**
     * Percentual de acerto nos últimos 30 dias (100 − erro médio absoluto %).
     * Só conta dias com movimento. null se houver menos de 5 dias comparáveis.
     * $deslocamento permite medir a janela anterior (para a variação do painel).
     */
    public function precisao(int $deslocamentoDias = 0): ?array
    {
        $hist = $this->historico();
        $erros = [];

        for ($i = 1 + $deslocamentoDias; $i <= self::DIAS_PRECISAO + $deslocamentoDias; $i++) {
            $dia = $this->hoje->modify("-{$i} days");
            $real = $hist[$dia->format('Y-m-d')] ?? 0;
            if ($real <= 0) {
                continue; // fim de semana/feriado sem operação não entra na conta
            }
            // Só enxerga o que existia ANTES daquele dia.
            $passado = array_filter($hist, fn($v, $k) => $k < $dia->format('Y-m-d'), ARRAY_FILTER_USE_BOTH);
            $prev = $this->preverPessoas($dia, $passado, $dia);
            if ($prev['amostras'] === 0) {
                continue;
            }
            $erros[] = abs($prev['pessoas'] - $real) / $real;
        }

        if (count($erros) < 5) {
            return null;
        }
        $mape = array_sum($erros) / count($erros) * 100;
        return ['precisao' => round(max(0, 100 - $mape), 1), 'dias' => count($erros)];
    }

    // ─── Alertas e recomendações ─────────────────────────────────────────────

    /**
     * Alertas gerados a partir dos dados reais:
     * [['tipo'=>'aviso|info|sucesso', 'titulo'=>, 'descricao'=>, 'data'=>?'d/m', 'rotulo'=>?], ...]
     */
    public function alertas(): array
    {
        $alertas = [];
        $ontem = $this->hoje->modify('-1 day')->format('Y-m-d');

        // Desperdício dos últimos 7 dias contra a meta e contra a semana anterior.
        $t7  = $this->producao->totais($this->empresaId, $this->hoje->modify('-7 days')->format('Y-m-d'), $ontem);
        $t14 = $this->producao->totais($this->empresaId, $this->hoje->modify('-14 days')->format('Y-m-d'), $this->hoje->modify('-8 days')->format('Y-m-d'));

        if ($t7['produzido'] > 0) {
            $pct = $t7['desperdicado'] / $t7['produzido'] * 100;
            if ($pct > self::META_DESPERDICIO) {
                $excesso = $t7['desperdicado'] - $t7['produzido'] * self::META_DESPERDICIO / 100;
                $alertas[] = [
                    'tipo' => 'aviso',
                    'titulo' => 'Desperdício acima da meta',
                    'descricao' => sprintf('%s%% na última semana (meta %s%%). Reduza cerca de %s kg por semana.',
                        self::n($pct, 1), self::n(self::META_DESPERDICIO, 0), self::n($excesso, 1)),
                ];
            } elseif ($t14['desperdicado'] - $t7['desperdicado'] >= 1 && $t7['desperdicado'] <= $t14['desperdicado'] * 0.95) {
                $alertas[] = [
                    'tipo' => 'sucesso',
                    'titulo' => 'Produção otimizada',
                    'descricao' => sprintf('Economia de %s kg de desperdício em relação à semana anterior.',
                        self::n($t14['desperdicado'] - $t7['desperdicado'], 1)),
                ];
            }
        }

        // Pico de fluxo previsto para a próxima semana.
        $prox = array_filter($this->proximosDias(7), fn($p) => $p['pessoas'] > 0);
        if (count($prox) >= 2) {
            usort($prox, fn($a, $b) => $b['pessoas'] <=> $a['pessoas']);
            $pico = $prox[0];
            $alertas[] = [
                'tipo' => 'info',
                'titulo' => 'Maior movimento previsto',
                'descricao' => sprintf('%s (%s): cerca de %d pessoas.',
                    ucfirst(Datas::nomeDiaSemana($pico['data'])), $pico['data']->format('d/m'), $pico['pessoas']),
            ];
        }

        // Dias de baixa previsão / feriados nos próximos 10 dias.
        $media = $this->mediaDiasUteis($this->historico(), $this->hoje);
        for ($i = 1; $i <= 10; $i++) {
            $dia = $this->hoje->modify("+{$i} days");
            $dow = (int) $dia->format('w');
            if ($dow === 0 || $dow === 6) {
                continue;
            }
            $feriado = Datas::feriado($dia);
            if ($feriado !== null) {
                $alertas[] = ['tipo' => 'info', 'titulo' => 'Feriado: ' . $feriado,
                    'descricao' => 'Previsão de operação mínima em ' . $dia->format('d/m') . '.',
                    'data' => $dia->format('d/m'), 'rotulo' => 'Feriado'];
            } elseif ($media !== null) {
                $p = $this->pessoasNoDia($dia);
                if ($p['amostras'] >= 2 && $p['pessoas'] < $media * 0.8) {
                    $alertas[] = ['tipo' => 'aviso', 'titulo' => 'Baixa previsão',
                        'descricao' => sprintf('%s (%s): cerca de %d pessoas, abaixo da média.',
                            ucfirst(Datas::nomeDiaSemana($dia)), $dia->format('d/m'), $p['pessoas']),
                        'data' => $dia->format('d/m'), 'rotulo' => 'Baixa previsão'];
                }
            }
        }

        return $alertas;
    }

    /** Próximos eventos (feriados e dias de baixa previsão) para a lateral do assistente. */
    public function eventos(int $max = 3): array
    {
        $ev = array_values(array_filter($this->alertas(), fn($a) => isset($a['data'])));
        return array_slice($ev, 0, $max);
    }

    // ─── Formatação ──────────────────────────────────────────────────────────

    public static function n(float $v, int $casas = 0): string
    {
        return number_format($v, $casas, ',', '.');
    }
}
