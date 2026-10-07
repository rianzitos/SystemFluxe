<?php

/**
 * Backend da tela de Configurações: perfil (nome, e-mail, cargo),
 * senha e foto do usuário logado. Todas as ações respondem JSON.
 */
class ConfiguracaoController
{
    private Usuario $usuario;

    public function __construct()
    {
        $this->usuario = new Usuario();
    }

    // ─── Perfil (nome, e-mail, cargo) ─────────────────────────────────────────

    public function atualizarPerfil(): void
    {
        $id = $this->exigirSessao();

        $nome       = trim($_POST['nome'] ?? '');
        $email      = mb_strtolower(trim($_POST['email'] ?? ''));
        $cargo      = trim($_POST['cargo'] ?? '');
        $senhaAtual = (string) ($_POST['senha_atual'] ?? '');

        if ($nome === '' || mb_strlen($nome) > 150) {
            $this->responder(false, 'Informe um nome válido (até 150 caracteres).', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $this->responder(false, 'Informe um e-mail válido.', 422);
        }
        if (mb_strlen($cargo) > 100) {
            $this->responder(false, 'O cargo pode ter no máximo 100 caracteres.', 422);
        }

        $atual = $this->usuario->buscarPerfilCompleto($id);
        if (!$atual) {
            $this->responder(false, 'Usuário não encontrado.', 404);
        }

        // Trocar o e-mail muda o login: exige confirmar a senha atual.
        if (mb_strtolower($atual['email']) !== $email) {
            if ($senhaAtual === '') {
                $this->responder(false, 'Para alterar o e-mail, informe sua senha atual.', 422);
            }
            $this->conferirSenha($id, $senhaAtual);

            if ($this->usuario->emailExisteParaOutro($email, $id)) {
                $this->responder(false, 'Este e-mail já está em uso por outra conta.', 409);
            }
        }

        try {
            $this->usuario->atualizarPerfil($id, $nome, $email, $cargo);
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao atualizar perfil: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível salvar. Tente novamente.', 500);
        }

        $_SESSION['usuario_nome']  = $nome;
        $_SESSION['usuario_email'] = $email;

        $this->responder(true, 'Perfil atualizado com sucesso!', 200, [
            'nome'  => $nome,
            'email' => $email,
            'cargo' => $cargo,
        ]);
    }

    // ─── Senha ────────────────────────────────────────────────────────────────

    public function atualizarSenha(): void
    {
        $id = $this->exigirSessao();

        $atual     = (string) ($_POST['senha_atual'] ?? '');
        $nova      = (string) ($_POST['senha_nova'] ?? '');
        $confirmar = (string) ($_POST['senha_confirmar'] ?? '');

        if ($atual === '' || $nova === '' || $confirmar === '') {
            $this->responder(false, 'Preencha todos os campos de senha.', 422);
        }
        if (strlen($nova) < 8) {
            $this->responder(false, 'A nova senha deve ter pelo menos 8 caracteres.', 422);
        }
        if (strlen($nova) > 72) {
            // bcrypt ignora tudo após 72 bytes.
            $this->responder(false, 'A nova senha pode ter no máximo 72 caracteres.', 422);
        }
        if ($nova !== $confirmar) {
            $this->responder(false, 'As senhas não coincidem.', 422);
        }

        $this->conferirSenha($id, $atual);

        if (hash_equals($atual, $nova)) {
            $this->responder(false, 'A nova senha deve ser diferente da atual.', 422);
        }

        try {
            $this->usuario->atualizarSenha($id, $nova);
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao atualizar senha: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível salvar. Tente novamente.', 500);
        }

        // Senha mudou: renova o ID da sessão (evita fixação de sessão).
        session_regenerate_id(true);

        $this->responder(true, 'Senha atualizada com sucesso!');
    }

    // ─── Foto ─────────────────────────────────────────────────────────────────

    public function atualizarFoto(): void
    {
        $id = $this->exigirSessao();

        $arquivo = $_FILES['foto_perfil'] ?? null;
        if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->responder(false, 'Selecione uma imagem.', 422);
        }
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            $this->responder(false, 'Falha no envio da imagem. Tente novamente.', 422);
        }
        if ($arquivo['size'] > 5 * 1024 * 1024) {
            $this->responder(false, 'A imagem deve ter no máximo 5 MB.', 422);
        }

        // O tipo é detectado pelo conteúdo do arquivo, nunca pelo nome/extensão.
        $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($arquivo['tmp_name']);
        $extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($extensoes[$tipo]) || @getimagesize($arquivo['tmp_name']) === false) {
            $this->responder(false, 'Formato inválido. Envie uma imagem JPG ou PNG.', 422);
        }

        $pasta = __DIR__ . '/../../public/uploads/perfil/';
        if (!is_dir($pasta) && !mkdir($pasta, 0755, true) && !is_dir($pasta)) {
            $this->responder(false, 'Não foi possível salvar a imagem.', 500);
        }

        $nomeArquivo = 'perfil_' . bin2hex(random_bytes(12)) . '.' . $extensoes[$tipo];
        if (!move_uploaded_file($arquivo['tmp_name'], $pasta . $nomeArquivo)) {
            $this->responder(false, 'Não foi possível salvar a imagem.', 500);
        }

        $caminho = 'uploads/perfil/' . $nomeArquivo;
        $fotoAntiga = $_SESSION['usuario_foto'] ?? null;

        try {
            $this->usuario->atualizarFoto($id, $caminho);
        } catch (PDOException $e) {
            @unlink($pasta . $nomeArquivo);
            error_log('SICAPDA: falha ao atualizar foto: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível salvar. Tente novamente.', 500);
        }

        $_SESSION['usuario_foto'] = $caminho;

        // Remove a foto anterior do disco (só se estiver dentro da pasta de perfis).
        if ($fotoAntiga && str_starts_with($fotoAntiga, 'uploads/perfil/') && basename($fotoAntiga) !== $nomeArquivo) {
            @unlink($pasta . basename($fotoAntiga));
        }

        $this->responder(true, 'Foto atualizada com sucesso!', 200, ['foto' => '/' . $caminho]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Valida sessão + CSRF e devolve o ID do usuário logado. */
    private function exigirSessao(): int
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->responder(false, 'Método não permitido.', 405);
        }
        if (empty($_SESSION['usuario_id'])) {
            $this->responder(false, 'Sessão expirada. Faça login novamente.', 401);
        }

        CsrfMiddleware::validar();

        return (int) $_SESSION['usuario_id'];
    }

    /** Encerra com 403 se a senha informada não for a do usuário. */
    private function conferirSenha(int $id, string $senha): void
    {
        $hash = $this->usuario->buscarHashSenha($id);
        if (!$hash || !password_verify($senha, $hash)) {
            $this->responder(false, 'Senha atual incorreta.', 403);
        }
    }

    private function responder(bool $sucesso, string $mensagem, int $status = 200, array $extra = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['sucesso' => $sucesso, 'mensagem' => $mensagem] + $extra);
        exit;
    }
}
