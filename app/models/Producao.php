<?php

/**
 * Consultas sobre producao_refeicoes (kg previsto / produzido / desperdiçado
 * por dia e por tipo de refeição) e a meta de refeições (tabela refeicoes).
 */
class Producao
{
    public const TIPOS = ['cafe' => 'Café da manhã', 'almoco' => 'Almoço', 'jantar' => 'Jantar', 'ceia' => 'Ceia'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Totais por dia no período: ['Y-m-d' => ['previsto'=>, 'produzido'=>, 'desperdicado'=>]] (kg).
     */
    public function serieDiaria(int $empresaId, string $de, string $ate): array
    {
        $stmt = $this->db->prepare(
            'SELECT data,
                    SUM(quantidade_prevista_kg)     AS previsto,
                    SUM(quantidade_produzida_kg)    AS produzido,
                    SUM(quantidade_desperdicada_kg) AS desperdicado
             FROM producao_refeicoes
             WHERE empresa_id = ? AND data BETWEEN ? AND ?
             GROUP BY data'
        );
        $stmt->execute([$empresaId, $de, $ate]);

        $saida = [];
        foreach ($stmt->fetchAll() as $l) {
            $saida[$l['data']] = [
                'previsto'     => (float) $l['previsto'],
                'produzido'    => (float) $l['produzido'],
                'desperdicado' => (float) $l['desperdicado'],
            ];
        }
        return $saida;
    }

    /** Totais do período: ['previsto'=>, 'produzido'=>, 'desperdicado'=>, 'dias'=>]. */
    public function totais(int $empresaId, string $de, string $ate): array
    {
        $serie = $this->serieDiaria($empresaId, $de, $ate);
        $t = ['previsto' => 0.0, 'produzido' => 0.0, 'desperdicado' => 0.0, 'dias' => count($serie)];
        foreach ($serie as $d) {
            $t['previsto']     += $d['previsto'];
            $t['produzido']    += $d['produzido'];
            $t['desperdicado'] += $d['desperdicado'];
        }
        return $t;
    }

    /** kg produzidos por tipo de refeição no período: ['almoco' => kg, ...]. */
    public function produzidoPorTipo(int $empresaId, string $de, string $ate): array
    {
        $stmt = $this->db->prepare(
            'SELECT tipo, SUM(quantidade_produzida_kg) AS kg
             FROM producao_refeicoes
             WHERE empresa_id = ? AND data BETWEEN ? AND ?
             GROUP BY tipo'
        );
        $stmt->execute([$empresaId, $de, $ate]);

        $saida = [];
        foreach ($stmt->fetchAll() as $l) {
            $saida[$l['tipo']] = (float) $l['kg'];
        }
        return $saida;
    }

    /**
     * Metas cadastradas na empresa (tabela refeicoes): ['cafe' => 120, ...].
     * Se houver mais de um registro por tipo, vale o mais recente.
     */
    public function metasDiarias(int $empresaId): array
    {
        $stmt = $this->db->prepare('SELECT tipo, media_diaria FROM refeicoes WHERE empresa_id = ? ORDER BY id ASC');
        $stmt->execute([$empresaId]);

        $saida = [];
        foreach ($stmt->fetchAll() as $l) {
            $saida[$l['tipo']] = (int) $l['media_diaria'];
        }
        return $saida;
    }

    /**
     * Grava o desperdício (kg) de uma refeição na data informada (padrão: hoje). Cria a linha do dia se ainda não
     * existir (previsto e produzido ficam em 0 e são preenchidos por quem os informa).
     * Sobrescreve, não soma: o envio da balança pode ser repetido sem contar em dobro.
     */
    public function registrarDesperdicio(int $empresaId, string $tipo, float $kg, ?string $data = null): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO producao_refeicoes
                (empresa_id, data, tipo, quantidade_desperdicada_kg, criado_em)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantidade_desperdicada_kg = VALUES(quantidade_desperdicada_kg)'
        );
        $stmt->execute([$empresaId, $data ?? date('Y-m-d'), $tipo, round($kg, 2), date('Y-m-d H:i:s')]);
    }
}
