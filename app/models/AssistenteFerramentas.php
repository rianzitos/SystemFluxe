<?php

/**
 * Ferramentas que a IA do assistente pode chamar para ler os dados da empresa.
 *
 * - Só leitura: nenhuma ferramenta grava nada.
 * - A empresa vem do login (sessão ou token), nunca da IA: o modelo escolhe só datas e períodos.
 * - Devolvem números agregados, sem nomes de pessoas (a lista nominal fica nas telas Pessoas e Relatórios).
 * - Reaproveitam os mesmos models das telas (Previsao, Acesso, Producao, Dispositivo), então a IA vê
 *   exatamente os números do painel.
 */
class AssistenteFerramentas
{
    private const MAX_DIAS_PERIODO = 92;

    private int $empresaId;
    private DateTimeImmutable $hoje;
    private ?Previsao $previsao = null;
    private ?Acesso $acesso = null;
    private ?Producao $producao = null;

    public function __construct(int $empresaId, ?DateTimeImmutable $hoje = null)
    {
        $this->empresaId = $empresaId;
        $this->hoje = $hoje ?? new DateTimeImmutable('today');
    }

    /**
     * Definições no formato da API (lista fixa e na mesma ordem a cada pedido, para o cache de prompt valer).
     * strict: a API garante que os argumentos seguem o esquema; mesmo assim tudo é validado em executar().
     */
    public static function definicoes(): array
    {
        $semParametros = ['type' => 'object', 'properties' => new stdClass(), 'required' => [], 'additionalProperties' => false];
        $data = fn(string $desc) => ['type' => 'string', 'format' => 'date', 'description' => $desc];

        return [
            [
                'name' => 'consultar_previsao_dia',
                'description' => 'Previsão de um dia da empresa: pessoas esperadas, refeições por tipo (café da manhã, almoço, jantar, ceia), '
                    . 'quilos de comida a produzir, feriado e confiança. Para hoje também traz quantas pessoas já entraram. '
                    . 'Para uma data passada devolve o que aconteceu de fato (pessoas que acessaram, produção e desperdício em kg).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['data' => $data('Dia consultado, AAAA-MM-DD.')],
                    'required' => ['data'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'consultar_previsao_proximos_dias',
                'description' => 'Previsão dia a dia dos próximos dias a partir de amanhã (pessoas, refeições e kg), com totais. '
                    . 'Use para "previsão semanal", "semana que vem", "próximos dias".',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['dias' => ['type' => 'integer', 'description' => 'Quantos dias a partir de amanhã, de 1 a 14. Semana = 7.']],
                    'required' => ['dias'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'consultar_desperdicio',
                'description' => 'Produção e desperdício de comida (kg) nos últimos N dias até ontem, percentual contra a meta (8%), '
                    . 'produção por tipo de refeição, a série diária (até 31 dias) e o desperdício já registrado hoje.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['dias' => ['type' => 'integer', 'description' => 'Tamanho do período em dias, de 1 a 90 (ex.: 7 = última semana, 30 = último mês).']],
                    'required' => ['dias'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'consultar_acessos',
                'description' => 'Acessos (entradas autorizadas nas catracas) em um período: pessoas distintas por dia, total de entradas, '
                    . 'média por dia com movimento, dia de maior movimento, duração média das permanências e horário de pico de entrada.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'data_inicio' => $data('Primeiro dia do período, AAAA-MM-DD.'),
                        'data_fim'    => $data('Último dia do período (inclusive), AAAA-MM-DD. Período máximo de 92 dias.'),
                    ],
                    'required' => ['data_inicio', 'data_fim'],
                    'additionalProperties' => false,
                ],
                'strict' => true,
            ],
            [
                'name' => 'consultar_presentes_agora',
                'description' => 'Quantas pessoas estão no local neste momento, por categoria (Operador, Supervisor, Prestador de Serviço), '
                    . 'e quantas pessoas ativas a empresa tem cadastradas. Não traz nomes.',
                'input_schema' => $semParametros,
                'strict' => true,
            ],
            [
                'name' => 'consultar_precisao',
                'description' => 'Precisão das previsões de pessoas nos últimos 30 dias (a mesma do card "Precisão da IA").',
                'input_schema' => $semParametros,
                'strict' => true,
            ],
            [
                'name' => 'consultar_alertas',
                'description' => 'Alertas e recomendações atuais: desperdício acima da meta, maior movimento previsto na semana, '
                    . 'feriados e dias de baixa previsão nos próximos 10 dias.',
                'input_schema' => $semParametros,
                'strict' => true,
            ],
            [
                'name' => 'consultar_dispositivos',
                'description' => 'Dispositivos IoT da empresa (catracas, leitores e balanças): nome, tipo, local, Online/Offline e último sinal.',
                'input_schema' => $semParametros,
                'strict' => true,
            ],
        ];
    }

    /**
     * Executa uma ferramenta. Devolve os dados (viram JSON no tool_result).
     *
     * @throws InvalidArgumentException argumento inválido (a IA recebe a mensagem e pode corrigir)
     */
    public function executar(string $nome, array $entrada): array
    {
        return match ($nome) {
            'consultar_previsao_dia'           => $this->previsaoDia($this->data($entrada, 'data')),
            'consultar_previsao_proximos_dias' => $this->proximosDias($this->inteiro($entrada, 'dias', 1, 14)),
            'consultar_desperdicio'            => $this->desperdicio($this->inteiro($entrada, 'dias', 1, 90)),
            'consultar_acessos'                => $this->acessos($this->data($entrada, 'data_inicio'), $this->data($entrada, 'data_fim')),
            'consultar_presentes_agora'        => $this->presentes(),
            'consultar_precisao'               => $this->precisao(),
            'consultar_alertas'                => $this->alertas(),
            'consultar_dispositivos'           => $this->dispositivos(),
            default => throw new InvalidArgumentException("Ferramenta desconhecida: {$nome}."),
        };
    }

    // ─── Ferramentas ─────────────────────────────────────────────────────────

    private function previsaoDia(DateTimeImmutable $dia): array
    {
        $k = $dia->format('Y-m-d');
        $base = ['data' => $k, 'dia_semana' => Datas::nomeDiaSemana($dia), 'feriado' => Datas::feriado($dia)];

        if ($dia < $this->hoje) {
            $pessoas = $this->acesso()->pessoasPorDia($this->empresaId, $k, $k)[$k] ?? 0;
            $prod = $this->producao()->serieDiaria($this->empresaId, $k, $k)[$k] ?? null;
            return $base + [
                'tipo' => 'realizado (data passada)',
                'pessoas_que_acessaram' => $pessoas,
                'producao_kg' => $prod === null ? null : [
                    'previsto' => round($prod['previsto'], 1),
                    'produzido' => round($prod['produzido'], 1),
                    'desperdicado' => round($prod['desperdicado'], 1),
                ],
                'observacao' => $pessoas === 0 && $prod === null ? 'Sem registros de acesso nem de produção neste dia.' : null,
            ];
        }

        $p = $this->previsao()->previsaoDoDia($dia);
        $saida = $base + ['tipo' => 'previsão'] + $this->resumoPrevisao($p);
        if ($k === $this->hoje->format('Y-m-d')) {
            $saida['pessoas_que_ja_entraram_hoje'] = $this->acesso()->pessoasPorDia($this->empresaId, $k, $k)[$k] ?? 0;
        }
        return $saida;
    }

    private function proximosDias(int $dias): array
    {
        $lista = [];
        $totPessoas = 0;
        $totRefeicoes = 0;
        $totKg = 0.0;
        $temKg = false;
        foreach ($this->previsao()->proximosDias($dias) as $p) {
            $lista[] = ['data' => $p['data']->format('Y-m-d'), 'dia_semana' => Datas::nomeDiaSemana($p['data']), 'feriado' => $p['feriado']]
                + $this->resumoPrevisao($p);
            $totPessoas += $p['pessoas'];
            $totRefeicoes += $p['total_refeicoes'];
            if ($p['kg'] !== null) {
                $totKg += $p['kg'];
                $temKg = true;
            }
        }
        return [
            'dias' => $lista,
            'totais' => [
                'pessoas' => $totPessoas,
                'refeicoes' => $totRefeicoes,
                'kg_recomendado' => $temKg ? round($totKg, 1) : null,
            ],
        ];
    }

    private function desperdicio(int $dias): array
    {
        $ate = $this->hoje->modify('-1 day');
        $de = $this->hoje->modify("-{$dias} days");
        [$deK, $ateK] = [$de->format('Y-m-d'), $ate->format('Y-m-d')];

        $t = $this->producao()->totais($this->empresaId, $deK, $ateK);
        $meta = Previsao::META_DESPERDICIO;
        $pct = $t['produzido'] > 0 ? $t['desperdicado'] / $t['produzido'] * 100 : null;

        $saida = [
            'periodo' => ['de' => $deK, 'ate' => $ateK],
            'dias_com_registro' => $t['dias'],
            'produzido_kg' => round($t['produzido'], 1),
            'desperdicado_kg' => round($t['desperdicado'], 1),
            'previsto_kg' => round($t['previsto'], 1),
            'desperdicio_percentual' => $pct === null ? null : round($pct, 1),
            'meta_percentual' => $meta,
            'acima_da_meta' => $pct === null ? null : $pct > $meta,
            'excesso_sobre_a_meta_kg' => $pct !== null && $pct > $meta
                ? round($t['desperdicado'] - $t['produzido'] * $meta / 100, 1) : 0,
        ];

        if ($t['produzido'] <= 0) {
            $saida['observacao'] = 'Sem produção registrada no período.';
        }

        $porTipo = [];
        foreach ($this->producao()->produzidoPorTipo($this->empresaId, $deK, $ateK) as $tipo => $kg) {
            $porTipo[Producao::TIPOS[$tipo] ?? $tipo] = round($kg, 1);
        }
        $saida['produzido_por_refeicao_kg'] = $porTipo;

        if ($dias <= 31) {
            $serie = [];
            foreach ($this->producao()->serieDiaria($this->empresaId, $deK, $ateK) as $data => $d) {
                $serie[] = [
                    'data' => $data,
                    'produzido' => round($d['produzido'], 1),
                    'desperdicado' => round($d['desperdicado'], 1),
                ];
            }
            usort($serie, fn($a, $b) => strcmp($a['data'], $b['data']));
            $saida['serie_diaria_kg'] = $serie;
        }

        $hojeK = $this->hoje->format('Y-m-d');
        $hoje = $this->producao()->serieDiaria($this->empresaId, $hojeK, $hojeK)[$hojeK] ?? null;
        $saida['hoje_ate_agora_kg'] = $hoje === null ? null : [
            'produzido' => round($hoje['produzido'], 1),
            'desperdicado' => round($hoje['desperdicado'], 1),
        ];

        return $saida;
    }

    private function acessos(DateTimeImmutable $de, DateTimeImmutable $ate): array
    {
        if ($ate < $de) {
            [$de, $ate] = [$ate, $de];
        }
        if ($de->diff($ate)->days + 1 > self::MAX_DIAS_PERIODO) {
            throw new InvalidArgumentException('Período longo demais: use no máximo ' . self::MAX_DIAS_PERIODO . ' dias.');
        }
        [$deK, $ateK] = [$de->format('Y-m-d'), $ate->format('Y-m-d')];

        $porDia = $this->acesso()->pessoasPorDia($this->empresaId, $deK, $ateK);
        ksort($porDia);
        $resumo = $this->acesso()->resumoRegistros($this->empresaId, $deK, $ateK, '', '');

        $maior = null;
        foreach ($porDia as $data => $qtd) {
            if ($maior === null || $qtd > $maior['pessoas']) {
                $maior = ['data' => $data, 'dia_semana' => Datas::nomeDiaSemana(new DateTimeImmutable($data)), 'pessoas' => $qtd];
            }
        }

        $saida = [
            'periodo' => ['de' => $deK, 'ate' => $ateK],
            'total_entradas' => $this->acesso()->contarEntradas($this->empresaId, $deK, $ateK),
            'dias_com_movimento' => count($porDia),
            'media_pessoas_por_dia_com_movimento' => $porDia ? round(array_sum($porDia) / count($porDia), 1) : null,
            'dia_de_maior_movimento' => $maior,
            'duracao_media_permanencia_min' => $resumo['duracao_media_min'],
            'horario_pico_entrada' => $resumo['pico_hora'] === null ? null
                : sprintf('%02d:00 às %02d:00', $resumo['pico_hora'], ($resumo['pico_hora'] + 1) % 24),
        ];
        if (count($porDia) <= 31) {
            $saida['pessoas_distintas_por_dia'] = $porDia;
        }
        if ($ate >= $this->hoje) {
            $saida['observacao'] = 'O dia de hoje ainda está em andamento.';
        }
        return $saida;
    }

    private function presentes(): array
    {
        $presentes = $this->acesso()->presentesAgora($this->empresaId, $this->hoje->format('Y-m-d'));
        $porCategoria = [];
        foreach (Acesso::CATEGORIAS as $chave => $rotulo) {
            $porCategoria[$rotulo] = 0;
        }
        foreach ($presentes as $p) {
            $rotulo = Acesso::CATEGORIAS[$p['categoria']] ?? (string) $p['categoria'];
            $porCategoria[$rotulo] = ($porCategoria[$rotulo] ?? 0) + 1;
        }
        return [
            'agora' => date('H:i'),
            'presentes_no_local' => count($presentes),
            'por_categoria' => $porCategoria,
            'pessoas_ativas_cadastradas' => $this->acesso()->contarFuncionariosAtivos($this->empresaId),
        ];
    }

    private function precisao(): array
    {
        $r = $this->previsao()->precisao();
        if ($r === null) {
            return ['precisao_percentual' => null, 'observacao' => 'Dados insuficientes: são necessários ao menos 5 dias com movimento nos últimos 30 dias.'];
        }
        return ['precisao_percentual' => $r['precisao'], 'dias_avaliados' => $r['dias'], 'janela' => 'últimos 30 dias'];
    }

    private function alertas(): array
    {
        $lista = array_map(fn($a) => [
            'tipo' => $a['tipo'],
            'titulo' => $a['titulo'],
            'descricao' => $a['descricao'],
            'data' => $a['data'] ?? null,
        ], $this->previsao()->alertas());

        return ['alertas' => $lista, 'observacao' => $lista ? null : 'Nenhum alerta no momento.'];
    }

    private function dispositivos(): array
    {
        $lista = array_map(fn($d) => [
            'nome' => $d['nome'],
            'tipo' => $d['tipo_rotulo'],
            'localizacao' => $d['localizacao'],
            'status' => $d['status'],
            'ultimo_sinal' => $d['ultimo_sinal'],
        ], (new Dispositivo())->listar($this->empresaId));

        return [
            'dispositivos' => $lista,
            'online' => count(array_filter($lista, fn($d) => $d['status'] === 'online')),
            'offline' => count(array_filter($lista, fn($d) => $d['status'] !== 'online')),
            'observacao' => $lista ? null : 'Nenhum dispositivo cadastrado (Configurações › Integrações).',
        ];
    }

    // ─── Apoio ───────────────────────────────────────────────────────────────

    /** Campos de uma previsão do dia (Previsao::previsaoDoDia) no formato da resposta. */
    private function resumoPrevisao(array $p): array
    {
        $refeicoes = [];
        foreach ($p['refeicoes'] as $tipo => $qtd) {
            $refeicoes[Producao::TIPOS[$tipo] ?? $tipo] = $qtd;
        }
        $base = [
            'historico'   => 'mesmos dias da semana das últimas semanas',
            'media_geral' => 'sem histórico deste dia da semana: média geral dos dias úteis',
            'cadastro'    => 'sem histórico de acessos: estimativa pelo número de colaboradores do cadastro',
        ][$p['base']] ?? $p['base'];

        return [
            'pessoas_previstas' => $p['pessoas'],
            'fim_de_semana' => $p['fim_de_semana'],
            'refeicoes_previstas' => $refeicoes ?: null,
            'total_refeicoes' => $p['total_refeicoes'],
            'kg_recomendado' => $p['kg'],
            'confianca' => $p['confianca'],
            'base_do_calculo' => $base,
            'dias_semelhantes_no_historico' => $p['amostras'],
            'observacao' => $refeicoes ? null : 'A empresa não cadastrou metas de refeições, então não há divisão por tipo.',
        ];
    }

    private function data(array $entrada, string $campo): DateTimeImmutable
    {
        $valor = $entrada[$campo] ?? null;
        $d = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($d === false || $d->format('Y-m-d') !== $valor) {
            throw new InvalidArgumentException("Campo {$campo}: use uma data válida no formato AAAA-MM-DD.");
        }
        if ($d < $this->hoje->modify('-2 years') || $d > $this->hoje->modify('+1 year')) {
            throw new InvalidArgumentException("Campo {$campo}: data fora do intervalo aceito (até 2 anos atrás e 1 ano à frente).");
        }
        return $d;
    }

    private function inteiro(array $entrada, string $campo, int $min, int $max): int
    {
        $valor = $entrada[$campo] ?? null;
        if (!is_int($valor) && !(is_string($valor) && ctype_digit($valor))) {
            throw new InvalidArgumentException("Campo {$campo}: informe um número inteiro de {$min} a {$max}.");
        }
        return max($min, min($max, (int) $valor));
    }

    private function previsao(): Previsao
    {
        return $this->previsao ??= new Previsao($this->empresaId, $this->hoje);
    }

    private function acesso(): Acesso
    {
        return $this->acesso ??= new Acesso();
    }

    private function producao(): Producao
    {
        return $this->producao ??= new Producao();
    }
}
