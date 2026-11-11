<?php

/**
 * Backend da tela de Configurações: perfil (nome, e-mail, cargo), senha e foto do usuário logado,
 * preferências (notificações e sistema), sessões ativas e os dispositivos IoT da empresa
 * (só administradores). Todas as ações respondem JSON.
 *
 * As regras (validação e gravação) ficam em Conta e Integracoes, que o aplicativo também usa pela API
 * (ApiController): aqui só entram a sessão, o CSRF e a permissão do site.
 */
class ConfiguracaoController
{
    use RespondeJson;

    private Conta $conta;
    private Integracoes $integracoes;

    public function __construct()
    {
        $this->conta = new Conta();
        $this->integracoes = new Integracoes();
    }

    // ─── Perfil (nome, e-mail, cargo) ─────────────────────────────────────────

    public function atualizarPerfil(): void
    {
        $id = $this->exigirSessao();

        $resultado = $this->executar(fn() => $this->conta->atualizarPerfil($id, $_POST));

        $_SESSION['usuario_nome']  = $resultado['nome'];
        $_SESSION['usuario_email'] = $resultado['email'];

        $this->responder(true, $resultado['mensagem'], 200, [
            'nome'  => $resultado['nome'],
            'email' => $resultado['email'],
            'cargo' => $resultado['cargo'],
        ]);
    }

    // ─── Senha ────────────────────────────────────────────────────────────────

    public function atualizarSenha(): void
    {
        $id = $this->exigirSessao();

        $resultado = $this->executar(fn() => $this->conta->atualizarSenha($id, $_POST));

        // Senha mudou: renova o ID da sessão (evita fixação de sessão) e mantém o registro dela em "Sessões ativas".
        $hashAntigo = Sessao::hashAtual();
        session_regenerate_id(true);
        try {
            (new Sessao())->trocarId($hashAntigo);
        } catch (PDOException $e) {
            error_log('SICAPDA: falha ao atualizar o registro da sessão: ' . $e->getMessage());
        }

        $this->responder(true, $resultado['mensagem']);
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

        $resultado = $this->executar(fn() => $this->conta->salvarFoto($id, $arquivo['tmp_name'], (int) $arquivo['size']));

        $_SESSION['usuario_foto'] = $resultado['foto'];

        $this->responder(true, $resultado['mensagem'], 200, ['foto' => '/' . $resultado['foto']]);
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

    /** Cadastra uma catraca/leitor/balança e devolve o token inteiro, que não será mostrado de novo. */
    public function criarDispositivo(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('dispositivos');

        $r = $this->executar(fn() => $this->integracoes->criar($empresaId, $_POST));
        $this->responder(true, $r['mensagem'], 200, ['dispositivo' => $r['dispositivo'], 'token' => $r['token']]);
    }

    /** Gera outro token para o dispositivo; o aparelho que usa o antigo deixa de ser aceito. */
    public function regenerarTokenDispositivo(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('dispositivos');

        $r = $this->executar(fn() => $this->integracoes->novoToken($empresaId, $_POST));
        $this->responder(true, $r['mensagem'], 200, ['dispositivo' => $r['dispositivo'], 'token' => $r['token']]);
    }

    /**
     * "Simular leitura": passa um UID por uma catraca da empresa pela mesma regra de uma leitura real
     * (Integracoes::simularLeitura). Grava um acesso DE VERDADE.
     */
    public function simularLeitura(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('dispositivos');

        $r = $this->executar(fn() => $this->integracoes->simularLeitura($empresaId, $_POST));
        $this->responder(true, $r['mensagem'], 200, ['resultado' => $r['resultado'], 'uid' => $r['uid']]);
    }

    public function registrarPesagem(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('dispositivos');

        $r = $this->executar(fn() => $this->integracoes->registrarPesagem($empresaId, $_POST));
        $this->responder(true, $r['mensagem'], 200, $r);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Roda uma regra de Conta/Integracoes; um erro de regra (CadastroException) vira a resposta JSON com o código dele. */
    private function executar(callable $acao): array
    {
        try {
            return $acao();
        } catch (CadastroException $e) {
            $this->responder(false, $e->getMessage(), $e->status());
        }
    }
}
