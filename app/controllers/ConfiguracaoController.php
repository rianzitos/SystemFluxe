<?php

/**
 * Backend da tela de Configurações: perfil (nome, e-mail, cargo), senha e foto do usuário logado,
 * preferências (notificações e sistema), sessões ativas e os dispositivos IoT da empresa
 * (só administradores). Todas as ações respondem JSON.
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

        // Senha mudou: renova o ID da sessão (evita fixação de sessão) e mantém o registro dela em "Sessões ativas".
        $hashAntigo = Sessao::hashAtual();
        session_regenerate_id(true);
        try {
            (new Sessao())->trocarId($hashAntigo);
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao atualizar o registro da sessão: ' . $e->getMessage());
        }

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

    // ─── Preferências ─────────────────────────────────────────────────────────

    /** Liga/desliga um alerta (previsão, acesso negado, dispositivo offline) e devolve o novo número do sino. */
    public function salvarNotificacao(): void
    {
        $id = $this->exigirSessao();

        $chave = (string) ($_POST['chave'] ?? '');
        if (!in_array($chave, Preferencia::NOTIFICACOES, true)) {
            $this->responder(false, 'Notificação desconhecida.', 422);
        }
        $ativo = ($_POST['ativo'] ?? '') === '1';

        try {
            (new Preferencia())->salvarNotificacao($id, $chave, $ativo);
            $empresaId = Pagina::empresaId();
            $sino = Notificacoes::contar((new Painel($empresaId))->totalAlertas(), $empresaId);
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao salvar notificação: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível salvar. Tente novamente.', 500);
        }

        $this->responder(true, 'Preferência salva.', 200, ['ativo' => $ativo, 'sino' => $sino]);
    }

    /** Período padrão e itens por página dos Relatórios. */
    public function salvarSistema(): void
    {
        $id = $this->exigirSessao();

        $periodo = (string) ($_POST['periodo_padrao'] ?? '');
        $itens = (int) ($_POST['itens_pagina'] ?? 0);
        if (!in_array($periodo, Preferencia::PERIODOS, true) || !in_array($itens, Preferencia::ITENS_PAGINA, true)) {
            $this->responder(false, 'Valor inválido. Escolha uma das opções da lista.', 422);
        }

        try {
            (new Preferencia())->salvarSistema($id, $periodo, $itens);
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao salvar preferências do sistema: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível salvar. Tente novamente.', 500);
        }

        $this->responder(true, 'Preferências salvas.');
    }

    // ─── Sessões ativas ───────────────────────────────────────────────────────

    /** Encerra uma sessão (id) ou todas as outras (todas=1). A sessão atual nunca é encerrada por aqui. */
    public function encerrarSessao(): void
    {
        $id = $this->exigirSessao();

        try {
            $sessao = new Sessao();
            if (($_POST['todas'] ?? '') === '1') {
                $n = $sessao->encerrarOutras($id);
                $this->responder(true, $n === 1 ? '1 sessão encerrada.' : $n . ' sessões encerradas.', 200, ['encerradas' => $n]);
            }
            if (!$sessao->encerrar($id, (int) ($_POST['id'] ?? 0))) {
                $this->responder(false, 'Sessão não encontrada (talvez já tenha sido encerrada).', 404);
            }
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao encerrar sessão: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível encerrar a sessão. Tente novamente.', 500);
        }

        $this->responder(true, 'Sessão encerrada.');
    }

    // ─── Dispositivos IoT (só administrador) ──────────────────────────────────

    /** Cadastra uma catraca/leitor e devolve o token inteiro, que não será mostrado de novo. */
    public function criarDispositivo(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin();

        $nome = trim((string) ($_POST['nome'] ?? ''));
        $tipo = (string) ($_POST['tipo'] ?? 'rfid');
        $localizacao = trim((string) ($_POST['localizacao'] ?? ''));

        if ($nome === '' || mb_strlen($nome) > 150) {
            $this->responder(false, 'Informe o nome do dispositivo (até 150 caracteres).', 422);
        }
        if (!isset(Dispositivo::TIPOS[$tipo])) {
            $this->responder(false, 'Tipo de dispositivo inválido.', 422);
        }
        if (mb_strlen($localizacao) > 150) {
            $this->responder(false, 'A localização pode ter no máximo 150 caracteres.', 422);
        }

        try {
            $modelo = new Dispositivo();
            $novo = $modelo->criar($empresaId, $nome, $tipo, $localizacao !== '' ? $localizacao : null);
            $dispositivo = $modelo->buscarDaEmpresa($novo['id'], $empresaId);
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao criar dispositivo: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível criar o dispositivo. Tente novamente.', 500);
        }

        $this->responder(true, 'Dispositivo criado.', 200, ['dispositivo' => $dispositivo, 'token' => $novo['token']]);
    }

    /** Gera outro token para o dispositivo; o aparelho que usa o antigo deixa de ser aceito. */
    public function regenerarTokenDispositivo(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin();

        $id = (int) ($_POST['id'] ?? 0);

        try {
            $modelo = new Dispositivo();
            $token = $id > 0 ? $modelo->regenerarToken($id, $empresaId) : false;
            $dispositivo = $token !== false ? $modelo->buscarDaEmpresa($id, $empresaId) : false;
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao regenerar token: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível gerar o token. Tente novamente.', 500);
        }

        if ($token === false) {
            $this->responder(false, 'Dispositivo não encontrado.', 404);
        }

        $this->responder(true, 'Novo token gerado.', 200, ['dispositivo' => $dispositivo, 'token' => $token]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Valida sessão + CSRF e devolve o ID do usuário logado. */
    private function exigirSessao(): int
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->responder(false, 'Método não permitido.', 405);
        }
        if (!AuthMiddleware::sessaoAtiva()) {
            $this->responder(false, 'Sessão expirada. Faça login novamente.', 401);
        }

        CsrfMiddleware::validar();

        return (int) $_SESSION['usuario_id'];
    }

    /** Só o administrador da empresa gerencia dispositivos. Devolve o ID da empresa. */
    private function exigirAdmin(): int
    {
        $empresaId = Pagina::empresaId();
        if (($_SESSION['usuario_perfil'] ?? '') !== 'admin' || $empresaId <= 0) {
            $this->responder(false, 'Somente o administrador da empresa pode gerenciar dispositivos.', 403);
        }
        return $empresaId;
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
        header('Cache-Control: no-store');   // algumas respostas carregam o token inteiro de um dispositivo
        echo json_encode(['sucesso' => $sucesso, 'mensagem' => $mensagem] + $extra);
        exit;
    }
}
