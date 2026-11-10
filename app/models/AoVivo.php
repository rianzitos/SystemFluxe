<?php

/**
 * O que as telas internas consultam de poucos em poucos segundos para se atualizar sozinhas
 * (public/js/aoVivo.js ↔ GET /aovivo).
 *
 * A "assinatura" é um resumo (hash) de tudo que muda o conteúdo das telas: acessos, pesagens,
 * metas, pessoas, cartões e o status online/offline dos dispositivos. Quando ela muda, a tela
 * busca a versão nova e troca só os pedaços que mudaram. O hash nunca inclui o "último sinal" dos
 * dispositivos (muda a cada minuto) — só se eles estão online ou offline.
 */
class AoVivo
{
    private PDO $db;

    public function __construct(private int $empresaId)
    {
        $this->db = Database::connect();
    }

    /** Valor de uma consulta de resumo, ou '' se a tabela ainda não existe (migration não rodada). */
    private function resumo(string $sql, array $params): string
    {
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return implode('|', array_map(fn($v) => (string) $v, $stmt->fetch(PDO::FETCH_NUM) ?: []));
        } catch (PDOException $e) {
            return '';
        }
    }

    private function ultimoLog(): int
    {
        return (int) $this->resumo('SELECT COALESCE(MAX(id), 0) FROM logs_acesso WHERE empresa_id = ?', [$this->empresaId]);
    }

    /** Hash do estado atual da empresa (ver comentário da classe). */
    private function assinatura(array $dispositivos): string
    {
        $e = [$this->empresaId];

        $partes = [
            $this->ultimoLog(),
            $this->resumo(
                "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', data, tipo, quantidade_prevista_kg, quantidade_produzida_kg, quantidade_desperdicada_kg))), 0)
                 FROM producao_refeicoes WHERE empresa_id = ?",
                $e
            ),
            $this->resumo("SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', tipo, media_diaria))), 0) FROM refeicoes WHERE empresa_id = ?", $e),
            $this->resumo(
                "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', id, nome, cargo, categoria, matricula, ativo))), 0)
                 FROM funcionarios WHERE empresa_id = ?",
                $e
            ),
            $this->resumo(
                "SELECT COUNT(*), COALESCE(SUM(CRC32(CONCAT_WS('|', c.id, c.identificador, c.ativo))), 0)
                 FROM credenciais_acesso c JOIN funcionarios f ON f.id = c.funcionario_id WHERE f.empresa_id = ?",
                $e
            ),
            $this->resumo('SELECT COUNT(*), COALESCE(SUM(leituras), 0), MAX(ultima_leitura) FROM cartoes_nao_cadastrados WHERE empresa_id = ?', $e),
            implode(',', array_map(fn($d) => $d['id'] . ':' . $d['nome'] . ':' . $d['status'], $dispositivos)),
            date('Y-m-d H'),   // a previsão das próximas horas e "hoje" viram com o relógio
        ];

        return md5(implode('#', $partes));
    }

    /** Acessos com id maior que $depoisDe (os mais recentes primeiro limitados a 5), para os avisos da tela. */
    private function eventos(int $depoisDe): array
    {
        $stmt = $this->db->prepare(
            'SELECT l.id, l.direcao, l.resultado, l.data_hora, f.nome
             FROM logs_acesso l LEFT JOIN funcionarios f ON f.id = l.funcionario_id
             WHERE l.empresa_id = ? AND l.id > ? ORDER BY l.id DESC LIMIT 5'
        );
        $stmt->execute([$this->empresaId, $depoisDe]);

        return array_reverse(array_map(fn($l) => [
            'id'        => (int) $l['id'],
            'direcao'   => $l['direcao'],
            'resultado' => $l['resultado'],
            'nome'      => $l['nome'],
            'hora'      => date('H:i', strtotime($l['data_hora'])),
        ], $stmt->fetchAll()));
    }

    private function novos(int $depoisDe): int
    {
        return (int) $this->resumo('SELECT COUNT(*) FROM logs_acesso WHERE empresa_id = ? AND id > ?', [$this->empresaId, $depoisDe]);
    }

    /**
     * Estado para a tela: assinatura + os dados leves que ela usa para avisar (toasts) e para
     * atualizar o status dos dispositivos. $ultimoLog = último acesso que a tela já conhece (null na 1ª consulta).
     */
    public function estado(?int $ultimoLog): array
    {
        $dispositivos = (new Dispositivo())->listar($this->empresaId);

        $pesagens = [];
        foreach (Balanca::hoje($this->empresaId) as $tipo => $kg) {
            $pesagens[] = ['tipo' => $tipo, 'rotulo' => Producao::TIPOS[$tipo] ?? $tipo, 'kg' => round((float) $kg, 2)];
        }

        $maisRecente = $this->ultimoLog();

        return [
            'assinatura'  => $this->assinatura($dispositivos),
            'ultimoLog'   => $maisRecente,
            'eventos'     => $ultimoLog !== null && $maisRecente > $ultimoLog ? $this->eventos($ultimoLog) : [],
            'novosAcessos' => $ultimoLog !== null && $maisRecente > $ultimoLog ? $this->novos($ultimoLog) : 0,
            'dispositivos' => array_map(fn($d) => [
                'id' => $d['id'], 'nome' => $d['nome'], 'status' => $d['status'], 'ultimo_sinal' => $d['ultimo_sinal'],
            ], $dispositivos),
            'pesagens'    => $pesagens,
        ];
    }
}
