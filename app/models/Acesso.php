<?php

/**
 * Consultas sobre logs_acesso (leituras das catracas).
 *
 * Todas as consultas são filtradas por empresa_id (isolamento entre clientes)
 * e consideram só leituras com resultado = 'autorizado'. Os intervalos de data
 * usam  data_hora >= :ini AND data_hora < :fim  (e não DATE(data_hora)) para
 * aproveitar o índice idx_logs_empresa_data.
 */
class Acesso
{
    public const CATEGORIAS = [
        'operador'   => 'Operador',
        'supervisor' => 'Supervisor',
        'prestador'  => 'Prestador de Serviço',
    ];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** Converte 'Y-m-d' (inclusive) em [início, fim exclusivo] de DATETIME. */
    private static function intervalo(string $de, string $ate): array
    {
        $fim = (new DateTimeImmutable($ate))->modify('+1 day')->format('Y-m-d');
        return [$de . ' 00:00:00', $fim . ' 00:00:00'];
    }

    // ─── Relatório: um registro por pessoa por dia ───────────────────────────

    /**
     * Monta a parte comum (FROM/WHERE/GROUP BY) dos registros diários:
     * 1ª entrada e última saída de cada funcionário em cada dia.
     */
    private function baseRegistros(int $empresaId, string $de, string $ate, string $busca, string $categoria): array
    {
        [$ini, $fim] = self::intervalo($de, $ate);

        $where = 'l.empresa_id = :emp AND l.resultado = \'autorizado\' AND l.funcionario_id IS NOT NULL
                  AND l.data_hora >= :ini AND l.data_hora < :fim';
        $params = [':emp' => $empresaId, ':ini' => $ini, ':fim' => $fim];

        if ($busca !== '') {
            // escapa % e _ para a busca ser literal
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            $where .= ' AND (f.nome LIKE :busca1 OR f.cargo LIKE :busca2)';
            $params[':busca1'] = $like;
            $params[':busca2'] = $like;
        }
        if ($categoria !== '' && isset(self::CATEGORIAS[$categoria])) {
            $where .= ' AND f.categoria = :cat';
            $params[':cat'] = $categoria;
        }

        $from = "FROM logs_acesso l
                 JOIN funcionarios f ON f.id = l.funcionario_id AND f.empresa_id = l.empresa_id
                 WHERE $where
                 GROUP BY f.id, f.nome, f.cargo, f.categoria, DATE(l.data_hora)";

        return [$from, $params];
    }

    /** Lista paginada de registros (1 linha por pessoa/dia). */
    public function registros(int $empresaId, string $de, string $ate, string $busca, string $categoria, int $limite, int $offset): array
    {
        [$from, $params] = $this->baseRegistros($empresaId, $de, $ate, $busca, $categoria);

        $sql = "SELECT f.id, f.nome, f.cargo, f.categoria, DATE(l.data_hora) AS dia,
                       MIN(CASE WHEN l.direcao = 'entrada' THEN l.data_hora END) AS entrada,
                       MAX(CASE WHEN l.direcao = 'saida'   THEN l.data_hora END) AS saida
                $from
                ORDER BY dia DESC, entrada ASC, f.nome ASC
                LIMIT :lim OFFSET :off";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'completarRegistro'], $stmt->fetchAll());
    }

    /** Todos os registros do filtro (para exportar CSV), limitado por segurança. */
    public function registrosParaExportar(int $empresaId, string $de, string $ate, string $busca, string $categoria, int $max = 50000): array
    {
        return $this->registros($empresaId, $de, $ate, $busca, $categoria, $max, 0);
    }

    private function completarRegistro(array $r): array
    {
        $r['duracao_min'] = null;
        if ($r['entrada'] && $r['saida'] && $r['saida'] > $r['entrada']) {
            $r['duracao_min'] = intdiv(strtotime($r['saida']) - strtotime($r['entrada']), 60);
        }
        return $r;
    }

    public function contarRegistros(int $empresaId, string $de, string $ate, string $busca, string $categoria): int
    {
        [$from, $params] = $this->baseRegistros($empresaId, $de, $ate, $busca, $categoria);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM (SELECT 1 $from) t");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Indicadores do relatório (respeitam os mesmos filtros da tabela):
     * duração média (só registros com entrada e saída) e pico de entrada.
     */
    public function resumoRegistros(int $empresaId, string $de, string $ate, string $busca, string $categoria): array
    {
        [$from, $params] = $this->baseRegistros($empresaId, $de, $ate, $busca, $categoria);

        $stmt = $this->db->prepare(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, entrada, saida)) AS media
             FROM (SELECT MIN(CASE WHEN l.direcao = 'entrada' THEN l.data_hora END) AS entrada,
                          MAX(CASE WHEN l.direcao = 'saida'   THEN l.data_hora END) AS saida
                   $from) t
             WHERE entrada IS NOT NULL AND saida > entrada"
        );
        $stmt->execute($params);
        $media = $stmt->fetchColumn();

        // Pico: hora do dia com mais primeiras-entradas
        $stmt = $this->db->prepare(
            "SELECT HOUR(entrada) AS hora, COUNT(*) AS qtd
             FROM (SELECT MIN(CASE WHEN l.direcao = 'entrada' THEN l.data_hora END) AS entrada
                   $from) t
             WHERE entrada IS NOT NULL
             GROUP BY HOUR(entrada)
             ORDER BY qtd DESC, hora ASC
             LIMIT 1"
        );
        $stmt->execute($params);
        $pico = $stmt->fetch();

        return [
            'duracao_media_min' => $media !== null && $media !== false ? (int) round((float) $media) : null,
            'pico_hora'         => $pico ? (int) $pico['hora'] : null,
        ];
    }

    // ─── Painel / análise mensal / pessoas ───────────────────────────────────

    /** Pessoas distintas com entrada autorizada em cada dia: ['Y-m-d' => int]. */
    public function pessoasPorDia(int $empresaId, string $de, string $ate): array
    {
        [$ini, $fim] = self::intervalo($de, $ate);

        $stmt = $this->db->prepare(
            "SELECT DATE(data_hora) AS dia, COUNT(DISTINCT funcionario_id) AS qtd
             FROM logs_acesso
             WHERE empresa_id = ? AND resultado = 'autorizado' AND direcao = 'entrada'
               AND funcionario_id IS NOT NULL AND data_hora >= ? AND data_hora < ?
             GROUP BY DATE(data_hora)"
        );
        $stmt->execute([$empresaId, $ini, $fim]);

        $saida = [];
        foreach ($stmt->fetchAll() as $linha) {
            $saida[$linha['dia']] = (int) $linha['qtd'];
        }
        return $saida;
    }

    /** Total de entradas autorizadas no período. */
    public function contarEntradas(int $empresaId, string $de, string $ate): int
    {
        [$ini, $fim] = self::intervalo($de, $ate);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM logs_acesso
             WHERE empresa_id = ? AND resultado = 'autorizado' AND direcao = 'entrada'
               AND data_hora >= ? AND data_hora < ?"
        );
        $stmt->execute([$empresaId, $ini, $fim]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Média de entradas por hora do dia, olhando os últimos $semanas dias da
     * semana iguais ao de $data. Retorna [hora => média].
     */
    public function mediaEntradasPorHora(int $empresaId, DateTimeImmutable $data, int $semanas = 4): array
    {
        $ini = $data->modify("-{$semanas} weeks")->format('Y-m-d') . ' 00:00:00';
        $fim = $data->format('Y-m-d') . ' 00:00:00';
        $dow = (int) $data->format('w') + 1; // DAYOFWEEK(): 1 = domingo

        $stmt = $this->db->prepare(
            "SELECT HOUR(data_hora) AS hora, COUNT(*) AS qtd, COUNT(DISTINCT DATE(data_hora)) AS dias
             FROM logs_acesso
             WHERE empresa_id = ? AND resultado = 'autorizado' AND direcao = 'entrada'
               AND data_hora >= ? AND data_hora < ? AND DAYOFWEEK(data_hora) = ?
             GROUP BY HOUR(data_hora)"
        );
        $stmt->execute([$empresaId, $ini, $fim, $dow]);

        $dias = 0;
        $linhas = $stmt->fetchAll();
        foreach ($linhas as $l) {
            $dias = max($dias, (int) $l['dias']);
        }

        $saida = [];
        foreach ($linhas as $l) {
            $saida[(int) $l['hora']] = $dias > 0 ? round($l['qtd'] / $dias, 1) : 0;
        }
        return $saida;
    }

    /**
     * Quem está no local agora: último registro autorizado de hoje é uma entrada.
     * Retorna lista com nome, cargo, categoria e hora da entrada.
     */
    public function presentesAgora(int $empresaId, string $hoje): array
    {
        $ini = $hoje . ' 00:00:00';

        $stmt = $this->db->prepare(
            "SELECT f.id, f.nome, f.cargo, f.categoria, MAX(l.data_hora) AS entrada
             FROM logs_acesso l
             JOIN funcionarios f ON f.id = l.funcionario_id AND f.empresa_id = l.empresa_id
             WHERE l.empresa_id = :emp AND l.resultado = 'autorizado' AND l.direcao = 'entrada'
               AND l.data_hora >= :ini AND f.ativo = 1
               AND NOT EXISTS (
                   SELECT 1 FROM logs_acesso l2
                   WHERE l2.funcionario_id = l.funcionario_id AND l2.resultado = 'autorizado'
                     AND l2.data_hora > l.data_hora
               )
             GROUP BY f.id, f.nome, f.cargo, f.categoria
             ORDER BY entrada ASC, f.nome ASC"
        );
        $stmt->execute([':emp' => $empresaId, ':ini' => $ini]);
        return $stmt->fetchAll();
    }

    public function contarFuncionariosAtivos(int $empresaId, ?string $ate = null): int
    {
        $sql = 'SELECT COUNT(*) FROM funcionarios WHERE empresa_id = ? AND ativo = 1';
        $params = [$empresaId];
        if ($ate !== null) {
            $sql .= ' AND criado_em < ?';
            $params[] = $ate . ' 00:00:00';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
}
