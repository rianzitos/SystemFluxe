<?php

/**
 * Cadastro das pessoas da empresa (tabela funcionarios) e dos cartões delas (credenciais_acesso),
 * para a aba Cadastro da tela de Pessoas. Tudo é filtrado pela empresa: uma empresa nunca vê nem
 * altera pessoas ou cartões de outra.
 *
 * Pessoas nunca são apagadas, só desativadas: apagar levaria junto os cartões e deixaria os
 * registros de acesso sem dono, o que distorce "Acessos Realizados" e a previsão.
 */
class Pessoa
{
    /** UID aceito ao vincular: o que a ESP32 manda (hexadecimal) e ids de demonstração com hífen. */
    public const PADRAO_UID = '/^[A-Z0-9_-]{4,100}$/';

    private const DIAS_PARA_LIMPAR_LEITURAS = 30;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    // ─── Consulta ────────────────────────────────────────────────────────────

    /** Todas as pessoas da empresa com os cartões de cada uma (ativas primeiro, depois por nome). */
    public function listar(int $empresaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.id, f.nome, f.cargo, f.categoria, f.matricula, f.ativo,
                    c.id AS cartao_id, c.identificador, c.ativo AS cartao_ativo
             FROM funcionarios f
             LEFT JOIN credenciais_acesso c ON c.funcionario_id = f.id
             WHERE f.empresa_id = ?
             ORDER BY f.ativo DESC, f.nome, f.id, c.id
             LIMIT 5000'
        );
        $stmt->execute([$empresaId]);

        $pessoas = [];
        foreach ($stmt->fetchAll() as $linha) {
            $id = (int) $linha['id'];
            $pessoas[$id] ??= [
                'id'        => $id,
                'nome'      => $linha['nome'],
                'cargo'     => $linha['cargo'],
                'categoria' => $linha['categoria'],
                'matricula' => $linha['matricula'],
                'ativo'     => (bool) $linha['ativo'],
                'cartoes'   => [],
            ];
            if ($linha['cartao_id'] !== null) {
                $pessoas[$id]['cartoes'][] = [
                    'id'    => (int) $linha['cartao_id'],
                    'uid'   => $linha['identificador'],
                    'ativo' => (bool) $linha['cartao_ativo'],
                ];
            }
        }
        return array_values($pessoas);
    }

    public function buscar(int $id, int $empresaId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT id, nome, cargo, categoria, matricula, ativo FROM funcionarios WHERE id = ? AND empresa_id = ?'
        );
        $stmt->execute([$id, $empresaId]);
        return $stmt->fetch();
    }

    /** Cartão da empresa (pelo dono) ou false. */
    public function buscarCartao(int $cartaoId, int $empresaId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.funcionario_id, c.identificador, c.ativo
             FROM credenciais_acesso c JOIN funcionarios f ON f.id = c.funcionario_id
             WHERE c.id = ? AND f.empresa_id = ?'
        );
        $stmt->execute([$cartaoId, $empresaId]);
        return $stmt->fetch();
    }

    /**
     * Quem já tem este UID, em qualquer empresa (o UID é único no banco todo).
     * null = livre; senão ['mesma_empresa' => bool, 'nome' => ?string]. O nome só vem se for da mesma empresa.
     */
    public function donoDoCartao(string $uid, int $empresaId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT f.nome, f.empresa_id FROM credenciais_acesso c
             JOIN funcionarios f ON f.id = c.funcionario_id WHERE c.identificador = ? LIMIT 1'
        );
        $stmt->execute([$uid]);
        $dono = $stmt->fetch();
        if (!$dono) {
            return null;
        }

        $mesma = (int) $dono['empresa_id'] === $empresaId;
        return ['mesma_empresa' => $mesma, 'nome' => $mesma ? $dono['nome'] : null];
    }

    // ─── Pessoas ─────────────────────────────────────────────────────────────

    /** Cadastra a pessoa (e o cartão dela, se vier) de uma vez: se o cartão falhar, a pessoa também não fica. */
    public function criar(int $empresaId, string $nome, string $cargo, string $categoria, ?string $matricula, ?string $uid = null): int
    {
        $this->db->beginTransaction();
        try {
            $id = $this->inserirPessoa($empresaId, $nome, $cargo, $categoria, $matricula);
            if ($uid !== null) {
                $this->inserirCartao($id, $uid);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($uid !== null) {
            $this->esquecerLeitura($empresaId, [$uid]);
        }
        return $id;
    }

    public function atualizar(int $id, int $empresaId, string $nome, string $cargo, string $categoria, ?string $matricula): void
    {
        $stmt = $this->db->prepare(
            'UPDATE funcionarios SET nome = ?, cargo = ?, categoria = ?, matricula = ? WHERE id = ? AND empresa_id = ?'
        );
        $stmt->execute([$nome, $cargo, $categoria, $matricula, $id, $empresaId]);
    }

    public function definirAtivo(int $id, int $empresaId, bool $ativo): void
    {
        $stmt = $this->db->prepare('UPDATE funcionarios SET ativo = ? WHERE id = ? AND empresa_id = ?');
        $stmt->execute([$ativo ? 1 : 0, $id, $empresaId]);
    }

    private function inserirPessoa(int $empresaId, string $nome, string $cargo, string $categoria, ?string $matricula): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO funcionarios (empresa_id, nome, matricula, cargo, categoria, ativo, criado_em)
             VALUES (?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$empresaId, $nome, $matricula, $cargo, $categoria, date('Y-m-d H:i:s')]);
        return (int) $this->db->lastInsertId();
    }

    // ─── Cartões ─────────────────────────────────────────────────────────────

    /** Vincula o cartão à pessoa (o chamador já conferiu empresa e se o UID está livre). */
    public function vincularCartao(int $funcionarioId, int $empresaId, string $uid): void
    {
        $this->inserirCartao($funcionarioId, $uid);
        $this->esquecerLeitura($empresaId, [$uid]);
    }

    private function inserirCartao(int $funcionarioId, string $uid): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO credenciais_acesso (funcionario_id, tipo, identificador, ativo, criado_em)
             VALUES (?, 'rfid', ?, 1, ?)"
        );
        $stmt->execute([$funcionarioId, $uid, date('Y-m-d H:i:s')]);
    }

    public function definirCartaoAtivo(int $cartaoId, bool $ativo): void
    {
        $this->db->prepare('UPDATE credenciais_acesso SET ativo = ? WHERE id = ?')->execute([$ativo ? 1 : 0, $cartaoId]);
    }

    /** Remove o cartão (o UID fica livre). Os registros de acesso antigos continuam, só sem o cartão. */
    public function removerCartao(int $cartaoId): void
    {
        $this->db->prepare('DELETE FROM credenciais_acesso WHERE id = ?')->execute([$cartaoId]);
    }

    // ─── Cartões lidos e ainda sem dono ──────────────────────────────────────

    /** Chamado pela catraca quando lê um cartão que ninguém tem: guarda o UID (uma linha por empresa e UID). */
    public function registrarLeituraDesconhecida(int $empresaId, string $uid, ?int $dispositivoId): void
    {
        $agora = date('Y-m-d H:i:s');

        $this->db->prepare('DELETE FROM cartoes_nao_cadastrados WHERE ultima_leitura < ?')
            ->execute([date('Y-m-d H:i:s', time() - self::DIAS_PARA_LIMPAR_LEITURAS * 86400)]);

        $this->db->prepare(
            'INSERT INTO cartoes_nao_cadastrados (empresa_id, identificador, dispositivo_id, leituras, primeira_leitura, ultima_leitura)
             VALUES (?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE leituras = leituras + 1, ultima_leitura = VALUES(ultima_leitura),
                                     dispositivo_id = VALUES(dispositivo_id)'
        )->execute([$empresaId, $uid, $dispositivoId, $agora, $agora]);
    }

    /** Últimos cartões lidos e ainda sem dono (mais recente primeiro). Sem a tabela (migration 005), volta vazio. */
    public function leiturasDesconhecidas(int $empresaId, int $limite = 15): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT n.identificador, n.leituras, n.ultima_leitura, d.nome AS dispositivo
                 FROM cartoes_nao_cadastrados n
                 LEFT JOIN dispositivos_iot d ON d.id = n.dispositivo_id
                 WHERE n.empresa_id = ?
                   AND NOT EXISTS (SELECT 1 FROM credenciais_acesso c WHERE c.identificador = n.identificador)
                 ORDER BY n.ultima_leitura DESC
                 LIMIT ?'
            );
            $stmt->bindValue(1, $empresaId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limite, PDO::PARAM_INT);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log('SICAPDA: cartoes_nao_cadastrados indisponível (rode a migration 005): ' . $e->getMessage());
            return [];
        }

        return array_map(fn($l) => [
            'uid'         => $l['identificador'],
            'leituras'    => (int) $l['leituras'],
            'dispositivo' => $l['dispositivo'] ?? 'Catraca removida',
            'quando'      => Datas::haQuanto($l['ultima_leitura']),
            'instante'    => $l['ultima_leitura'],
        ], $stmt->fetchAll());
    }

    /** Tira da lista de "cartões lidos" os UIDs que acabaram de ganhar dono. Nunca derruba quem chamou. */
    private function esquecerLeitura(int $empresaId, array $uids): void
    {
        try {
            foreach ($uids as $uid) {
                $this->db->prepare('DELETE FROM cartoes_nao_cadastrados WHERE empresa_id = ? AND identificador = ?')
                    ->execute([$empresaId, $uid]);
            }
        } catch (PDOException $e) {
            error_log('SICAPDA: cartoes_nao_cadastrados indisponível (rode a migration 005): ' . $e->getMessage());
        }
    }

    // ─── Importação de planilha ──────────────────────────────────────────────

    /**
     * Grava as linhas já validadas por CsvPessoas (chaves: linha, nome, cargo, categoria, matricula, cartao).
     * Linhas que batem com algo já cadastrado (cartão em uso, matrícula repetida) são ignoradas e
     * explicadas, o que também faz importar o mesmo arquivo duas vezes ser inofensivo.
     * Devolve ['pessoas' => int, 'cartoes' => int, 'erros' => [['linha' => n, 'motivo' => '...']]].
     */
    public function importar(int $empresaId, array $linhas): array
    {
        $erros = [];
        $pessoas = 0;
        $cartoes = 0;
        $importados = [];

        $uidsEmUso = $this->uidsEmUso(array_values(array_filter(array_column($linhas, 'cartao'))));
        $matriculas = $this->matriculasDaEmpresa($empresaId);

        $this->db->beginTransaction();
        try {
            foreach ($linhas as $l) {
                if ($l['cartao'] !== null && isset($uidsEmUso[$l['cartao']])) {
                    $erros[] = ['linha' => $l['linha'], 'motivo' => 'o cartão ' . $l['cartao'] . ' já está vinculado a alguém'];
                    continue;
                }
                if ($l['matricula'] !== null && isset($matriculas[mb_strtolower($l['matricula'])])) {
                    $erros[] = ['linha' => $l['linha'], 'motivo' => 'a matrícula ' . $l['matricula'] . ' já está cadastrada'];
                    continue;
                }

                // Se uma linha falhar no meio (pessoa gravada, cartão não), volta só ela.
                $this->db->exec('SAVEPOINT linha_importada');
                try {
                    $id = $this->inserirPessoa($empresaId, $l['nome'], $l['cargo'], $l['categoria'], $l['matricula']);
                    if ($l['cartao'] !== null) {
                        $this->inserirCartao($id, $l['cartao']);
                        $cartoes++;
                        $importados[] = $l['cartao'];
                    }
                    $this->db->exec('RELEASE SAVEPOINT linha_importada');
                    $pessoas++;
                } catch (PDOException $e) {
                    $this->db->exec('ROLLBACK TO SAVEPOINT linha_importada');
                    error_log('SICAPDA: falha ao importar a linha ' . $l['linha'] . ': ' . $e->getMessage());
                    if ($l['cartao'] !== null && ($e->errorInfo[1] ?? 0) === 1062) {
                        $erros[] = ['linha' => $l['linha'], 'motivo' => 'o cartão ' . $l['cartao'] . ' acabou de ser vinculado a alguém'];
                    } else {
                        $erros[] = ['linha' => $l['linha'], 'motivo' => 'não foi possível salvar esta linha'];
                    }
                    continue;
                }

                if ($l['matricula'] !== null) {
                    $matriculas[mb_strtolower($l['matricula'])] = true;
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->esquecerLeitura($empresaId, $importados);
        usort($erros, fn($a, $b) => $a['linha'] <=> $b['linha']);

        return ['pessoas' => $pessoas, 'cartoes' => $cartoes, 'erros' => $erros];
    }

    /** UIDs (entre os dados) que já existem no banco, em qualquer empresa: ['UID' => true]. */
    private function uidsEmUso(array $uids): array
    {
        $emUso = [];
        foreach (array_chunk($uids, 500) as $parte) {
            $marcas = implode(',', array_fill(0, count($parte), '?'));
            $stmt = $this->db->prepare("SELECT identificador FROM credenciais_acesso WHERE identificador IN ($marcas)");
            $stmt->execute($parte);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $emUso[strtoupper($uid)] = true;
            }
        }
        return $emUso;
    }

    /** Matrículas já usadas na empresa, em minúsculas: ['abc123' => true]. */
    private function matriculasDaEmpresa(int $empresaId): array
    {
        $stmt = $this->db->prepare("SELECT matricula FROM funcionarios WHERE empresa_id = ? AND matricula IS NOT NULL AND matricula <> ''");
        $stmt->execute([$empresaId]);

        $usadas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $usadas[mb_strtolower($m)] = true;
        }
        return $usadas;
    }
}
