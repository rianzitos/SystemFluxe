<?php

class Usuario {
    private PDO $db;

    public function __construct() {
        $this->db = Database::connect();
    }

    /**
     * Busca um usuário pelo e-mail.
     */
    public function buscarPorEmail(string $email): array|false {
        $stmt = $this->db->prepare(
            'SELECT id, nome, email, senha, perfil, empresa_id FROM usuarios WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        return $stmt->fetch();
    }

    /**
     * Busca um usuário pelo ID.
     */
    public function buscarPorId(int $id): array|false {
        $stmt = $this->db->prepare(
            'SELECT id, nome, email, perfil, cpf, telefone, cargo, matricula, foto, empresa_id
             FROM usuarios WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Verifica se o e-mail já está cadastrado.
     */
    public function emailExiste(string $email): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Verifica se o CPF já está cadastrado.
     */
    public function cpfExiste(string $cpf): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM usuarios WHERE cpf = ?');
        $stmt->execute([$cpf]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Cria o usuário administrador vinculado a uma empresa e retorna o ID inserido.
     *
     * $dados espera as chaves: nome, email, senha, cpf, telefone, cargo, matricula,
     * foto, dois_fatores_habilitado, dois_fatores_metodo
     */
    public function criar(array $dados, int $empresaId, string $perfil = 'admin'): int {
        $hash = password_hash($dados['senha'], PASSWORD_BCRYPT);

        $stmt = $this->db->prepare(
            'INSERT INTO usuarios
                (nome, email, senha, perfil, cpf, telefone, cargo, matricula, foto,
                 dois_fatores_habilitado, dois_fatores_metodo, empresa_id, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );

        $stmt->execute([
            $dados['nome'],
            $dados['email'],
            $hash,
            $perfil,
            $dados['cpf'],
            $dados['telefone'],
            $dados['cargo'],
            $dados['matricula'] ?: null,
            $dados['foto'] ?: null,
            !empty($dados['dois_fatores_habilitado']) ? 1 : 0,
            $dados['dois_fatores_metodo'] ?: null,
            $empresaId,
        ]);

        return (int) $this->db->lastInsertId();
    }
}