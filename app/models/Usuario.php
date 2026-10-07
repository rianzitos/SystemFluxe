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
            'SELECT id, nome, email, senha, perfil, empresa_id, foto FROM usuarios WHERE email = ? LIMIT 1'
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

    /**
     * Dados do perfil + nome da empresa (usado na tela de Configurações).
     */
    public function buscarPerfilCompleto(int $id): array|false {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.nome, u.email, u.perfil, u.cargo, u.foto, e.nome_fantasia AS empresa_nome
             FROM usuarios u
             LEFT JOIN empresas e ON e.id = u.empresa_id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Hash da senha atual (para conferir a senha antes de alterações sensíveis).
     */
    public function buscarHashSenha(int $id): string|false {
        $stmt = $this->db->prepare('SELECT senha FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetchColumn();
    }

    /**
     * Verifica se o e-mail pertence a OUTRO usuário (ignora o próprio).
     */
    public function emailExisteParaOutro(string $email, int $idAtual): bool {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $idAtual]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function atualizarPerfil(int $id, string $nome, string $email, string $cargo): void {
        $stmt = $this->db->prepare('UPDATE usuarios SET nome = ?, email = ?, cargo = ? WHERE id = ?');
        $stmt->execute([$nome, $email, $cargo !== '' ? $cargo : null, $id]);
    }

    public function atualizarSenha(int $id, string $novaSenha): void {
        $stmt = $this->db->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
        $stmt->execute([password_hash($novaSenha, PASSWORD_BCRYPT), $id]);
    }

    public function atualizarFoto(int $id, string $caminho): void {
        $stmt = $this->db->prepare('UPDATE usuarios SET foto = ? WHERE id = ?');
        $stmt->execute([$caminho, $id]);
    }
}
