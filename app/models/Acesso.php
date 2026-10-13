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

    // ─── Relatório: uma linha por permanência (entrada → saída) ──────────────

    /**
     * Saída de uma permanência: a primeira saída autorizada depois da entrada e antes da entrada
     * seguinte da mesma pessoa (se não houver entrada seguinte, qualquer saída depois). Assim cada
     * volta (almoço, saída rápida) vira a sua própria linha, e uma saída nunca "gruda" na entrada errada.
     * Espera os apelidos: $e (a entrada) e $proxima (a data da entrada seguinte, ou NULL).
     */
    private static function sqlSaida(string $e, string $proxima): string
    {
        return "(SELECT MIN(s.data_hora) FROM logs_acesso s
                 WHERE s.funcionario_id = $e.funcionario_id AND s.empresa_id = $e.empresa_id
                   AND s.resultado = 'autorizado' AND s.direcao = 'saida'
                   AND s.data_hora > $e.entrada
                   AND ($proxima IS NULL OR s.data_hora < $proxima))";
    }

    /** Data da próxima entrada autorizada da mesma pessoa depois da entrada $e (ou NULL). */
    private static function sqlProximaEntrada(string $e): string
    {
        return "(SELECT MIN(n.data_hora) FROM logs_acesso n
                 WHERE n.funcionario_id = $e.funcionario_id AND n.empresa_id = $e.empresa_id
                   AND n.resultado = 'autorizado' AND n.direcao = 'entrada'
                   AND n.data_hora > $e.entrada)";
    }

    /**
     * Filtro comum (WHERE) das entradas autorizadas do relatório. A permanência pertence ao dia da
     * ENTRADA; a saída pode cair depois do fim do período (turno que passa da meia-noite).
     */
    private function filtroEntradas(int $empresaId, string $de, string $ate, string $busca, string $categoria): array
    {
        [$ini, $fim] = self::intervalo($de, $ate);

        $where = 'l.empresa_id = :emp AND l.resultado = \'autorizado\' AND l.direcao = \'entrada\'
                  AND l.funcionario_id IS NOT NULL AND l.data_hora >= :ini AND l.data_hora < :fim';
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

        return [$where, $params];
    }

    /**
     * Lista paginada: uma linha por permanência. Cada pessoa pode aparecer várias vezes no mesmo dia
     * (entrou, saiu, voltou...). "em_andamento" = ainda está dentro (última passagem dela é esta entrada, hoje).
     */
    public function registros(int $empresaId, string $de, string $ate, string $busca, string $categoria, int $limite, int $offset): array
    {
        [$where, $params] = $this->filtroEntradas($empresaId, $de, $ate, $busca, $categoria);

        // A página é recortada primeiro (consulta de dentro); só para essas linhas se procura a saída.
        $sql = 'SELECT u.funcionario_id AS id, u.nome, u.cargo, u.categoria, DATE(u.entrada) AS dia,
                       u.entrada, ' . self::sqlSaida('u', 'u.proxima_entrada') . ' AS saida, u.proxima_entrada
                FROM (
                    SELECT t.*, ' . self::sqlProximaEntrada('t') . ' AS proxima_entrada
                    FROM (
                        SELECT l.id AS entrada_id, l.funcionario_id, l.empresa_id, l.data_hora AS entrada,
                               f.nome, f.cargo, f.categoria
                        FROM logs_acesso l
                        JOIN funcionarios f ON f.id = l.funcionario_id AND f.empresa_id = l.empresa_id
                        WHERE ' . $where . '
                        ORDER BY DATE(l.data_hora) DESC, l.data_hora ASC, f.nome ASC, l.id ASC
                        LIMIT :lim OFFSET :off
                    ) t
                ) u
                ORDER BY dia DESC, u.entrada ASC, u.nome ASC, u.entrada_id ASC';

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
        // Ainda dentro: sem saída, sem outra entrada depois e a entrada é de hoje (mesmo critério de presentesAgora()).
        $r['em_andamento'] = $r['saida'] === null && $r['proxima_entrada'] === null && $r['dia'] === date('Y-m-d');
        unset($r['proxima_entrada']);
        return $r;
    }

    /** Total de permanências (= entradas autorizadas) do filtro. */
    public function contarRegistros(int $empresaId, string $de, string $ate, string $busca, string $categoria): int
    {
        [$where, $params] = $this->filtroEntradas($empresaId, $de, $ate, $busca, $categoria);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM logs_acesso l
             JOIN funcionarios f ON f.id = l.funcionario_id AND f.empresa_id = l.empresa_id
             WHERE ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Indicadores do relatório (respeitam os mesmos filtros da tabela):
     * duração média das permanências concluídas e o horário de pico de entradas.
     */
    public function resumoRegistros(int $empresaId, string $de, string $ate, string $busca, string $categoria): array
    {
        [$where, $params] = $this->filtroEntradas($empresaId, $de, $ate, $busca, $categoria);

        $stmt = $this->db->prepare(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, u.entrada, ' . self::sqlSaida('u', 'u.proxima_entrada') . ')) AS media
             FROM (
                 SELECT t.*, ' . self::sqlProximaEntrada('t') . ' AS proxima_entrada
                 FROM (
                     SELECT l.funcionario_id, l.empresa_id, l.data_hora AS entrada
                     FROM logs_acesso l
                     JOIN funcionarios f ON f.id = l.funcionario_id AND f.empresa_id = l.empresa_id
                     WHERE ' . $where . '
                 ) t
             ) u'
        );
        $stmt->execute($params);
        $media = $stmt->fetchColumn();

        // Pico: hora do dia com mais entradas
        $stmt = $this->db->prepare(
            'SELECT HOUR(l.data_hora) AS hora, COUNT(*) AS qtd
             FROM logs_acesso l
             JOIN funcionarios f ON f.id = l.funcionario_id AND f.empresa_id = l.empresa_id
             WHERE ' . $where . '
             GROUP BY HOUR(l.data_hora)
             ORDER BY qtd DESC, hora ASC
             LIMIT 1'
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

    // ─── Leituras vindas das catracas (IoT) ──────────────────────────────────

    /**
     * Credencial pelo identificador (UID do cartão), só se o dono for funcionário da empresa.
     * Um cartão de outra empresa volta como inexistente, igual a um cartão desconhecido.
     * Retorna credencial_id, credencial_ativa, funcionario_id, nome e funcionario_ativo.
     */
    public function buscarCredencial(int $empresaId, string $identificador): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT c.id AS credencial_id, c.ativo AS credencial_ativa,
                    f.id AS funcionario_id, f.nome, f.ativo AS funcionario_ativo
             FROM credenciais_acesso c
             JOIN funcionarios f ON f.id = c.funcionario_id
             WHERE c.identificador = ? AND f.empresa_id = ?
             LIMIT 1'
        );
        $stmt->execute([$identificador, $empresaId]);
        return $stmt->fetch();
    }

    /**
     * Último registro autorizado do funcionário a partir de $desde ('Y-m-d H:i:s'), ou false.
     * É o que decide se a próxima passagem é entrada ou saída (mesma regra de presentesAgora()).
     */
    public function ultimoRegistroAutorizado(int $funcionarioId, string $desde): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT direcao, data_hora, dispositivo_id FROM logs_acesso
             WHERE funcionario_id = ? AND resultado = 'autorizado' AND data_hora >= ?
             ORDER BY data_hora DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([$funcionarioId, $desde]);
        return $stmt->fetch();
    }

    /**
     * Grava uma leitura da catraca (autorizada ou negada). funcionario_id e credencial_id são
     * nulos quando o cartão é desconhecido. A data é passada pelo chamador (fuso do PHP).
     */
    public function registrarLeitura(
        int $empresaId,
        int $dispositivoId,
        ?int $funcionarioId,
        ?int $credencialId,
        string $direcao,
        string $resultado,
        string $dataHora
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO logs_acesso (empresa_id, funcionario_id, dispositivo_id, credencial_id, direcao, resultado, data_hora)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$empresaId, $funcionarioId, $dispositivoId, $credencialId, $direcao, $resultado, $dataHora]);
    }
}
