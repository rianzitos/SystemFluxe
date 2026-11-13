<?php

/**
 * Cadastro da produção de refeições (tela Produção do site e do aplicativo): quantos quilos foram produzidos
 * e desperdiçados em cada refeição de um dia. Grava em `producao_refeicoes`, a mesma tabela que o painel
 * (Previsto × Realizado, distribuição), a análise mensal, a previsão de demanda e os alertas já leem, então
 * tudo se atualiza sozinho (as telas abertas também, pelo tempo real).
 *
 * Mesmas regras para o site (ProducaoController, sessão + CSRF) e o aplicativo (ApiController, token).
 * Erro de regra vira CadastroException com a mensagem e o código HTTP.
 *
 * O "previsto" de cada refeição é o que a previsão do sistema calculava para aquele dia (só com os dados de
 * antes dele). Ele é gravado na primeira vez que o dia recebe produção e não muda mais, para o gráfico
 * Previsto × Realizado mostrar a previsão de verdade, não uma refeita depois.
 */
class RegistroProducao
{
    public const MAX_KG = 100000;
    public const DIAS_HISTORICO = 14;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Tudo o que a tela precisa para um dia: as 4 refeições (previsto, produzido, desperdiçado), totais,
     * a sugestão da previsão e os últimos 14 dias.
     */
    public function dia(int $empresaId, string $data): array
    {
        $dia = self::validarData($data);
        $data = $dia->format('Y-m-d');

        $stmt = $this->db->prepare(
            'SELECT tipo, quantidade_prevista_kg AS previsto, quantidade_produzida_kg AS produzido,
                    quantidade_desperdicada_kg AS desperdicado
             FROM producao_refeicoes WHERE empresa_id = ? AND data = ?'
        );
        $stmt->execute([$empresaId, $data]);
        $gravado = [];
        foreach ($stmt->fetchAll() as $l) {
            $gravado[$l['tipo']] = $l;
        }

        $sugestao = $this->sugestao($empresaId, $dia);
        $refeicoes = [];
        foreach (Producao::TIPOS as $tipo => $rotulo) {
            $l = $gravado[$tipo] ?? null;
            $previsto = $l && (float) $l['previsto'] > 0 ? (float) $l['previsto'] : ($sugestao['porTipo'][$tipo] ?? null);
            $refeicoes[] = [
                'tipo'         => $tipo,
                'rotulo'       => $rotulo,
                'previsto'     => $previsto !== null ? round($previsto, 1) : null,
                'produzido'    => $l ? round((float) $l['produzido'], 2) : null,
                'desperdicado' => $l ? round((float) $l['desperdicado'], 2) : null,
                'registrado'   => $l !== null,
            ];
        }

        $produzido = array_sum(array_map(fn($r) => (float) $r['produzido'], $refeicoes));
        $desperdicado = array_sum(array_map(fn($r) => (float) $r['desperdicado'], $refeicoes));

        return [
            'data'            => $data,
            'hoje'            => date('Y-m-d'),
            'refeicoes'       => $refeicoes,
            'totais'          => [
                'produzido'    => round($produzido, 2),
                'desperdicado' => round($desperdicado, 2),
                'percentual'   => $produzido > 0 ? round($desperdicado / $produzido * 100, 1) : null,
            ],
            'sugestao'        => ['pessoas' => $sugestao['pessoas'], 'kg' => $sugestao['kg'], 'feriado' => $sugestao['feriado']],
            'historico'       => $this->historico($empresaId),
            'metaDesperdicio' => Previsao::META_DESPERDICIO,
            'temBalanca'      => $this->temBalanca($empresaId),
        ];
    }

    /**
     * Grava a produção de um dia. $dados = ['data' => 'Y-m-d', 'itens' => [['tipo', 'produzido', 'desperdicado'?], ...]].
     * "desperdicado" ausente/vazio mantém o valor atual (que pode ter vindo da balança). Devolve o dia atualizado.
     */
    public function salvar(int $empresaId, array $dados): array
    {
        $dia = self::validarData(is_string($dados['data'] ?? null) ? $dados['data'] : '');
        $data = $dia->format('Y-m-d');

        $itens = $dados['itens'] ?? null;
        if (!is_array($itens) || $itens === []) {
            throw new CadastroException('Informe a produção de pelo menos uma refeição.');
        }

        $validos = [];
        foreach ($itens as $item) {
            $tipo = is_array($item) && is_string($item['tipo'] ?? null) ? $item['tipo'] : '';
            if (!isset(Producao::TIPOS[$tipo])) {
                throw new CadastroException('Refeição desconhecida.');
            }
            if (isset($validos[$tipo])) {
                throw new CadastroException('A refeição ' . Producao::TIPOS[$tipo] . ' apareceu duas vezes.');
            }
            $rotulo = Producao::TIPOS[$tipo];
            $produzido = self::quilos($item['produzido'] ?? null, $rotulo . ' (produzido)');
            $desperdicado = self::quilos($item['desperdicado'] ?? null, $rotulo . ' (desperdiçado)');
            if ($produzido === null && $desperdicado === null) {
                continue;   // linha em branco: não mexe nessa refeição
            }
            $validos[$tipo] = ['produzido' => $produzido, 'desperdicado' => $desperdicado];
        }
        if ($validos === []) {
            throw new CadastroException('Preencha os quilos produzidos de pelo menos uma refeição.');
        }

        $atuais = $this->linhas($empresaId, $data);
        foreach ($validos as $tipo => $v) {
            $produzido = $v['produzido'] ?? (float) ($atuais[$tipo]['produzido'] ?? 0);
            $desperdicado = $v['desperdicado'] ?? (float) ($atuais[$tipo]['desperdicado'] ?? 0);
            if ($produzido > 0 && $desperdicado > $produzido) {
                throw new CadastroException('Em ' . Producao::TIPOS[$tipo] . ', o desperdício (' . Previsao::n($desperdicado, 1)
                    . ' kg) não pode ser maior que o produzido (' . Previsao::n($produzido, 1) . ' kg).');
            }
        }

        $previsto = $this->sugestao($empresaId, $dia)['porTipo'];
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                'INSERT INTO producao_refeicoes
                    (empresa_id, data, tipo, quantidade_prevista_kg, quantidade_produzida_kg, quantidade_desperdicada_kg, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    quantidade_produzida_kg = VALUES(quantidade_produzida_kg),
                    quantidade_desperdicada_kg = VALUES(quantidade_desperdicada_kg),
                    quantidade_prevista_kg = IF(quantidade_prevista_kg > 0, quantidade_prevista_kg, VALUES(quantidade_prevista_kg))'
            );
            foreach ($validos as $tipo => $v) {
                $stmt->execute([
                    $empresaId, $data, $tipo,
                    round($previsto[$tipo] ?? 0, 2),
                    round($v['produzido'] ?? (float) ($atuais[$tipo]['produzido'] ?? 0), 2),
                    round($v['desperdicado'] ?? (float) ($atuais[$tipo]['desperdicado'] ?? 0), 2),
                    date('Y-m-d H:i:s'),
                ]);
            }
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log('SICAPDA produção: ' . $e->getMessage());
            throw new CadastroException('Não foi possível salvar a produção. Tente novamente.', 500);
        }

        // Com a produção nova, o % de desperdício do dia pode ter passado da meta: avisa por e-mail quem pediu.
        Email::depoisDaResposta(fn() => EmailsAutomaticos::desperdicioAlto($empresaId, $data));

        return $this->dia($empresaId, $data);
    }

    // ─── Apoio ───────────────────────────────────────────────────────────────

    /**
     * O que a previsão do sistema calculava para o dia, usando só os dados de ANTES dele:
     * pessoas, kg do dia e kg de cada refeição (pela proporção de refeições da meta cadastrada).
     */
    private function sugestao(int $empresaId, DateTimeImmutable $dia): array
    {
        $p = (new Previsao($empresaId, $dia))->previsaoDoDia($dia);
        $porTipo = [];
        $totalRefeicoes = array_sum($p['refeicoes']);
        if ($p['kg'] !== null && $totalRefeicoes > 0) {
            foreach ($p['refeicoes'] as $tipo => $qtd) {
                $porTipo[$tipo] = round($p['kg'] * $qtd / $totalRefeicoes, 1);
            }
        }
        return ['pessoas' => (int) $p['pessoas'], 'kg' => $p['kg'], 'feriado' => $p['feriado'] ?? null, 'porTipo' => $porTipo];
    }

    /** Totais dos últimos 14 dias com produção (mais recente primeiro). */
    private function historico(int $empresaId): array
    {
        $hoje = new DateTimeImmutable('today');
        $serie = (new Producao())->serieDiaria(
            $empresaId, $hoje->modify('-' . (self::DIAS_HISTORICO - 1) . ' days')->format('Y-m-d'), $hoje->format('Y-m-d')
        );
        krsort($serie);
        $saida = [];
        foreach ($serie as $data => $s) {
            $saida[] = [
                'data'         => $data,
                'previsto'     => round($s['previsto'], 1),
                'produzido'    => round($s['produzido'], 1),
                'desperdicado' => round($s['desperdicado'], 1),
                'percentual'   => $s['produzido'] > 0 ? round($s['desperdicado'] / $s['produzido'] * 100, 1) : null,
            ];
        }
        return $saida;
    }

    private function linhas(int $empresaId, string $data): array
    {
        $stmt = $this->db->prepare(
            'SELECT tipo, quantidade_produzida_kg AS produzido, quantidade_desperdicada_kg AS desperdicado
             FROM producao_refeicoes WHERE empresa_id = ? AND data = ?'
        );
        $stmt->execute([$empresaId, $data]);
        $saida = [];
        foreach ($stmt->fetchAll() as $l) {
            $saida[$l['tipo']] = $l;
        }
        return $saida;
    }

    private function temBalanca(int $empresaId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM dispositivos_iot WHERE empresa_id = ? AND tipo = 'balanca' LIMIT 1");
        $stmt->execute([$empresaId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Data no formato Y-m-d, sem ser no futuro. */
    private static function validarData(string $data): DateTimeImmutable
    {
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $data);
        if (!$dia || $dia->format('Y-m-d') !== $data || $data < '2000-01-01') {
            throw new CadastroException('Informe uma data válida.');
        }
        if ($data > date('Y-m-d')) {
            throw new CadastroException('Não dá para registrar produção de um dia que ainda não chegou.');
        }
        return $dia;
    }

    /** Quilos informados (aceita "12,5" e 12.5). null = em branco. */
    private static function quilos(mixed $valor, string $campo): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_string($valor)) {
            $valor = str_replace(',', '.', trim($valor));
        }
        $kg = is_scalar($valor) && !is_bool($valor) ? filter_var($valor, FILTER_VALIDATE_FLOAT) : false;
        if ($kg === false || !is_finite($kg) || $kg < 0 || $kg > self::MAX_KG) {
            throw new CadastroException('Em ' . $campo . ', informe um peso entre 0 e 100.000 kg.');
        }
        return $kg;
    }
}
