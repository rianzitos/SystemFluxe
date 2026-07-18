<?php

class Empresa {
    private PDO $db;

    public function __construct() {
        $this->db = Database::connect();
    }

    /**
     * Verifica se já existe empresa com esse CNPJ.
     */
    public function cnpjExiste(string $cnpj): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM empresas WHERE cnpj = ?');
        $stmt->execute([$cnpj]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function buscarPorId(int $id): array|false {
        $stmt = $this->db->prepare('SELECT * FROM empresas WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Cria a empresa e retorna o ID inserido.
     *
     * $dados espera as chaves:
     * razao_social, nome_fantasia, cnpj, inscricao_estadual, segmento,
     * qtd_colaboradores, endereco, cep, cidade, estado, telefone, site,
     * horario_funcionamento, responsavel_contrato, email_institucional,
     * possui_refeitorio, controle_atual_refeicoes, recursos_acesso (array),
     * integracoes (array)
     */
    public function criar(array $dados): int {
        $stmt = $this->db->prepare(
            'INSERT INTO empresas
                (razao_social, nome_fantasia, cnpj, inscricao_estadual, segmento,
                 qtd_colaboradores, endereco, cep, cidade, estado, telefone, site,
                 horario_funcionamento, responsavel_contrato, email_institucional,
                 possui_refeitorio, controle_atual_refeicoes, recursos_acesso,
                 integracoes, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );

        $stmt->execute([
            $dados['razao_social'],
            $dados['nome_fantasia'],
            $dados['cnpj'],
            $dados['inscricao_estadual'] ?: null,
            $dados['segmento'],
            $dados['qtd_colaboradores'],
            $dados['endereco'],
            $dados['cep'],
            $dados['cidade'],
            $dados['estado'],
            $dados['telefone'],
            $dados['site'] ?: null,
            $dados['horario_funcionamento'],
            $dados['responsavel_contrato'],
            $dados['email_institucional'],
            $dados['possui_refeitorio'] ? 1 : 0,
            $dados['controle_atual_refeicoes'],
            json_encode($dados['recursos_acesso'] ?? []),
            json_encode($dados['integracoes'] ?? []),
        ]);

        return (int) $this->db->lastInsertId();
    }
}