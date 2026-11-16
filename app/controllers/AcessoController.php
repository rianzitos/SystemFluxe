<?php

class AcessoController
{
    private Usuario $usuario;

    public function __construct()
    {
        $this->usuario = new Usuario();
    }

    // ─── LOGIN ────────────────────────────────────────────────────────────────

    public function exibirLogin(): void
    {
        if (!empty($_SESSION['usuario_id'])) {
            $this->redirecionar('/painel');
        }

        // Voltar ao login abandona uma verificação em duas etapas que tinha ficado pela metade.
        unset($_SESSION['dois_fatores']);

        require_once __DIR__ . '/../../app/views/login.php';
    }

    public function processarLogin(): void
    {
        if (!CsrfMiddleware::valido()) {
            $this->voltarComErro('login', 'Sessão expirada. Tente novamente.');
            return;
        }

        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if (empty($email) || empty($senha)) {
            $this->voltarComErro('login', 'Preencha todos os campos.');
            return;
        }

        $usuario = $this->usuario->buscarPorEmail($email);

        // Compara com um hash falso quando o e-mail não existe: o tempo de resposta
        // fica igual e não dá para descobrir quais e-mails estão cadastrados.
        $hash = $usuario['senha'] ?? '$2y$10$usesomesillystringforeusesomesillystringfore.Ou3n7RQYhXJm';
        $senhaOk = password_verify($senha, $hash);

        if (!$usuario || !$senhaOk) {
            $this->voltarComErro('login', 'E-mail ou senha inválidos.');
            return;
        }

        // Verificação em duas etapas: a senha está certa, mas a sessão só abre depois do código.
        try {
            $doisFatores = new DoisFatores();
            if ($doisFatores->metodoExigido((int) $usuario['id']) !== null) {
                $desafio = $doisFatores->iniciarLogin((int) $usuario['id'], (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
                session_regenerate_id(true);
                $_SESSION['dois_fatores'] = [
                    'token'     => $desafio['token'],
                    'metodo'    => $desafio['metodo'],
                    'destino'   => $desafio['destino'],
                    'expira_em' => $desafio['expira_em'],
                ];
                $this->redirecionar('/login/verificacao');
            }
        } catch (CadastroException $e) {
            $this->voltarComErro('login', $e->getMessage());
            return;
        } catch (PDOException $e) {
            error_log('SICAPDA 2FA: falha ao iniciar a verificação: ' . $e->getMessage());
            $this->voltarComErro('login', 'Não foi possível entrar agora. Tente novamente em instantes.');
            return;
        }

        $this->entrar($usuario);
    }

    // ─── VERIFICAÇÃO EM DUAS ETAPAS (segunda etapa do login) ──────────────────

    /** Tela do código (app/views/verificacao.php). Sem login pela metade na sessão, volta ao login. */
    public function exibirVerificacao(): void
    {
        $pendente = $this->verificacaoPendente();
        $this->telaVerificacao($pendente);
    }

    public function processarVerificacao(): void
    {
        $pendente = $this->verificacaoPendente();
        if (!CsrfMiddleware::valido()) {
            $this->telaVerificacao($pendente, 'Sessão expirada. Tente novamente.');
        }

        try {
            $usuarioId = (new DoisFatores())->verificarLogin($pendente['token'], $_POST['codigo'] ?? '');
        } catch (CadastroException $e) {
            if ($e->status() === 410) {
                unset($_SESSION['dois_fatores']);
                $this->voltarComErro('login', $e->getMessage());
            }
            $this->telaVerificacao($pendente, $e->getMessage());
        } catch (PDOException $e) {
            error_log('SICAPDA 2FA: falha ao conferir o código: ' . $e->getMessage());
            $this->telaVerificacao($pendente, 'Não foi possível conferir o código agora. Tente novamente.');
        }

        $usuario = $this->usuario->buscarPorId($usuarioId);
        unset($_SESSION['dois_fatores']);
        if (!$usuario) {
            $this->voltarComErro('login', 'Usuário não encontrado.');
        }
        $this->entrar($usuario);
    }

    /** "Reenviar código" (método por e-mail). */
    public function reenviarCodigo(): void
    {
        $pendente = $this->verificacaoPendente();
        if (!CsrfMiddleware::valido()) {
            $this->telaVerificacao($pendente, 'Sessão expirada. Tente novamente.');
        }

        try {
            $r = (new DoisFatores())->reenviarCodigo($pendente['token']);
        } catch (CadastroException $e) {
            if ($e->status() === 410) {
                unset($_SESSION['dois_fatores']);
                $this->voltarComErro('login', $e->getMessage());
            }
            $this->telaVerificacao($pendente, $e->getMessage());
        }
        $_SESSION['dois_fatores']['expira_em'] = time() + DoisFatores::MINUTOS_VALIDADE * 60;
        $_SESSION['dois_fatores']['reenviado_em'] = time();
        $this->telaVerificacao($_SESSION['dois_fatores'], null, $r['mensagem']);
    }

    /** O login pela metade guardado na sessão; se não houver (ou venceu), volta ao login. */
    private function verificacaoPendente(): array
    {
        $pendente = $_SESSION['dois_fatores'] ?? null;
        if (!is_array($pendente) || empty($pendente['token'])) {
            $this->redirecionar('/login');
        }
        if ((int) ($pendente['expira_em'] ?? 0) <= time()) {
            unset($_SESSION['dois_fatores']);
            $this->voltarComErro('login', 'A verificação expirou. Entre de novo com e-mail e senha.');
        }
        return $pendente;
    }

    private function telaVerificacao(array $pendente, ?string $erro = null, ?string $aviso = null): never
    {
        header('Cache-Control: no-store');
        $metodo = $pendente['metodo'];
        $destino = $pendente['destino'] ?? '';
        $reenviadoEm = (int) ($pendente['reenviado_em'] ?? 0);
        require __DIR__ . '/../views/verificacao.php';
        exit;
    }

    /** Abre a sessão (senha conferida e, se a conta usa, o código da verificação em duas etapas também). */
    private function entrar(array $usuario): never
    {
        session_regenerate_id(true);

        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_perfil'] = $usuario['perfil'];
        $_SESSION['empresa_id'] = $usuario['empresa_id'];
        $_SESSION['usuario_foto'] = $usuario['foto'] ?? null;

        // Entra na lista de "Sessões ativas" das Configurações. Se a tabela ainda não existir, o login segue normal.
        try {
            (new Sessao())->registrar((int) $usuario['id']);
        } catch (PDOException $e) {
            error_log('SICAPDA: não foi possível registrar a sessão: ' . $e->getMessage());
        }

        // Escolheu a verificação em duas etapas no cadastro e ainda não configurou: leva direto para concluir.
        try {
            $pendente = (new DoisFatores())->estado((int) $usuario['id'])['pendente'];
        } catch (Throwable $e) {
            $pendente = null;
        }
        $this->redirecionar($pendente !== null ? '/configuracoes#seguranca' : '/painel');
    }

    // ─── CADASTRO ─────────────────────────────────────────────────────────────

    public function exibirCadastro(): void
    {
        if (!empty($_SESSION['usuario_id'])) {
            $this->redirecionar('/painel');
        }

        require_once __DIR__ . '/../../app/views/cadastro.php';
    }

    /**
     * Processa o wizard completo de cadastro (administrador + empresa + configuração).
     * Responde SEMPRE em JSON — quem chama é o próprio cadastro.php via fetch(),
     * então não há redirect nem sessão de flash aqui.
     */
    public function processarCadastro(): void
    {
        CsrfMiddleware::validar();

        $resultado = $this->salvarCadastro($_POST, $_FILES);

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($resultado);
    }

    /**
     * Faz toda a validação e a gravação (empresa + admin + refeições).
     * Retorna sempre um array no formato:
     *   ['sucesso' => bool, 'mensagem' => string|null]
     */
    private function salvarCadastro(array $post, array $files): array
    {
        // ─── Passo 1: Administrador ───
        $admNome = trim($post['admNome'] ?? '');
        $admCpf = trim($post['admCpf'] ?? '');
        $admTelefone = trim($post['admTelefone'] ?? '');
        $admEmail = trim($post['admEmail'] ?? '');
        $admCargo = trim($post['admCargo'] ?? '');
        $admMatricula = trim($post['admMatricula'] ?? '');

        // ─── Passo 2: Segurança ───
        $senha = $post['senha'] ?? '';
        $confirmaSenha = $post['confirmaSenha'] ?? '';
        // Verificação em duas etapas escolhida aqui fica pendente: o primeiro login leva a Configurações → Segurança
        // para configurar (ler o QR Code ou confirmar o e-mail). Só depois disso o login passa a pedir o código.
        $fa2Metodo = ($post['fa2'] ?? 'nao') === 'sim' && isset(DoisFatores::METODOS[$post['fa2metodo'] ?? ''])
            ? $post['fa2metodo'] : null;

        // ─── Passo 3: Empresa ───
        $razaoSocial = trim($post['razaoSocial'] ?? '');
        $nomeFantasia = trim($post['nomeFantasia'] ?? '');
        $cnpj = trim($post['cnpj'] ?? '');
        $inscEstadual = trim($post['inscEstadual'] ?? '');
        $segmento = trim($post['segmento'] ?? '');
        $qtdColab = (int) ($post['qtdColab'] ?? 0);
        $endereco = trim($post['endereco'] ?? '');
        $cep = trim($post['cep'] ?? '');
        $cidade = trim($post['cidade'] ?? '');
        $estado = trim($post['estado'] ?? '');
        $telEmpresa = trim($post['telEmpresa'] ?? '');
        $siteEmpresa = trim($post['siteEmpresa'] ?? '');
        $horarioFunc = trim($post['horarioFunc'] ?? '');
        $responsavel = trim($post['responsavel'] ?? '');
        $emailInst = trim($post['emailInst'] ?? '');

        // ─── Passo 4: Configuração ───
        $recursosAcesso = $post['recursosAcesso'] ?? [];
        $possuiRefeitorio = ($post['refeitorio'] ?? 'nao') === 'sim';
        $mealCafe = (int) ($post['mealCafe'] ?? 0);
        $mealAlmoco = (int) ($post['mealAlmoco'] ?? 0);
        $mealJantar = (int) ($post['mealJantar'] ?? 0);
        $mealCeia = (int) ($post['mealCeia'] ?? 0);
        $controleAtual = trim($post['controleAtual'] ?? 'Não');
        $integracoes = $post['integracoes'] ?? [];

        // ─── Passo 5: Termos ───
        $aceiteTermos = isset($post['aceiteTermos']);

        // ─── Validação dos campos obrigatórios ───
        $obrigatorios = [
            'Nome do administrador' => $admNome,
            'CPF' => $admCpf,
            'Telefone do admin' => $admTelefone,
            'E-mail do admin' => $admEmail,
            'Cargo' => $admCargo,
            'Senha' => $senha,
            'Confirmação de senha' => $confirmaSenha,
            'Razão social' => $razaoSocial,
            'Nome fantasia' => $nomeFantasia,
            'CNPJ' => $cnpj,
            'Segmento' => $segmento,
            'Endereço' => $endereco,
            'CEP' => $cep,
            'Cidade' => $cidade,
            'Estado' => $estado,
            'Telefone da empresa' => $telEmpresa,
            'Horário de funcionamento' => $horarioFunc,
            'Responsável pelo contrato' => $responsavel,
            'E-mail institucional' => $emailInst,
        ];

        foreach ($obrigatorios as $rotulo => $valor) {
            if ($valor === '') {
                return ['sucesso' => false, 'mensagem' => "Preencha o campo obrigatório: {$rotulo}."];
            }
        }

        if (!$aceiteTermos) {
            return ['sucesso' => false, 'mensagem' => 'É necessário aceitar os Termos de Uso e a Política de Privacidade.'];
        }

        if (!filter_var($admEmail, FILTER_VALIDATE_EMAIL) || !filter_var($emailInst, FILTER_VALIDATE_EMAIL)) {
            return ['sucesso' => false, 'mensagem' => 'Informe e-mails válidos.'];
        }

        if (strlen($senha) < 8) {
            return ['sucesso' => false, 'mensagem' => 'A senha deve ter pelo menos 8 caracteres.'];
        }

        if ($senha !== $confirmaSenha) {
            return ['sucesso' => false, 'mensagem' => 'As senhas não conferem.'];
        }

        $cpfLimpo = preg_replace('/\D/', '', $admCpf);
        $cnpjLimpo = preg_replace('/\D/', '', $cnpj);
        $cepLimpo = preg_replace('/\D/', '', $cep);

        if (strlen($cpfLimpo) !== 11) {
            return ['sucesso' => false, 'mensagem' => 'CPF inválido.'];
        }

        if (strlen($cnpjLimpo) !== 14) {
            return ['sucesso' => false, 'mensagem' => 'CNPJ inválido.'];
        }

        if ($this->usuario->emailExiste($admEmail)) {
            return ['sucesso' => false, 'mensagem' => 'Este e-mail já está cadastrado.'];
        }

        if ($this->usuario->cpfExiste($cpfLimpo)) {
            return ['sucesso' => false, 'mensagem' => 'Este CPF já está cadastrado.'];
        }

        $empresa = new Empresa();
        if ($empresa->cnpjExiste($cnpjLimpo)) {
            return ['sucesso' => false, 'mensagem' => 'Este CNPJ já está cadastrado.'];
        }

        // ─── Upload de foto (opcional) ───
        $fotoPath = null;
        if (!empty($files['admFoto']['name'])) {
            $fotoPath = $this->salvarFotoPerfil($files['admFoto']);
            if ($fotoPath === false) {
                return ['sucesso' => false, 'mensagem' => 'Não foi possível enviar a foto (formato ou tamanho inválido).'];
            }
        }

        $db = Database::connect();

        try {
            $db->beginTransaction();

            $empresaId = $empresa->criar([
                'razao_social' => $razaoSocial,
                'nome_fantasia' => $nomeFantasia,
                'cnpj' => $cnpjLimpo,
                'inscricao_estadual' => $inscEstadual,
                'segmento' => $segmento,
                'qtd_colaboradores' => $qtdColab,
                'endereco' => $endereco,
                'cep' => $cepLimpo,
                'cidade' => $cidade,
                'estado' => $estado,
                'telefone' => $telEmpresa,
                'site' => $siteEmpresa,
                'horario_funcionamento' => $horarioFunc,
                'responsavel_contrato' => $responsavel,
                'email_institucional' => $emailInst,
                'possui_refeitorio' => $possuiRefeitorio,
                'controle_atual_refeicoes' => $controleAtual,
                'recursos_acesso' => $recursosAcesso,
                'integracoes' => $integracoes,
            ]);

            $this->usuario->criar([
                'nome' => $admNome,
                'email' => $admEmail,
                'senha' => $senha,
                'cpf' => $cpfLimpo,
                'telefone' => $admTelefone,
                'cargo' => $admCargo,
                'matricula' => $admMatricula,
                'foto' => $fotoPath,
                'dois_fatores_habilitado' => false,
                'dois_fatores_metodo' => $fa2Metodo,
            ], $empresaId, 'admin');

            if ($possuiRefeitorio) {
                (new Refeicao())->salvarMediasIniciais($empresaId, [
                    'cafe' => $mealCafe,
                    'almoco' => $mealAlmoco,
                    'jantar' => $mealJantar,
                    'ceia' => $mealCeia,
                ]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            return ['sucesso' => false, 'mensagem' => 'Erro ao cadastrar. Tente novamente.'];
        }

        // E-mail de boas-vindas com os próximos passos (enviado depois de responder ao navegador).
        $nomeEmpresa = $nomeFantasia ?: $razaoSocial;
        Email::depoisDaResposta(fn() => EmailsAutomaticos::boasVindas($admNome, $admEmail, $nomeEmpresa, $emailInst));

        return [
            'sucesso' => true,
            'mensagem' => 'Cadastro realizado com sucesso!',
            'empresa' => $nomeFantasia ?: $razaoSocial,
            'admin' => $admNome,
            'email' => $admEmail,
        ];
    }

    /**
     * Move e valida a foto de perfil enviada. Retorna o caminho relativo salvo
     * ou false em caso de falha.
     */
    private function salvarFotoPerfil(array $arquivo): string|false
    {
        $permitidos = ['image/jpeg', 'image/png'];
        $tamanhoMax = 5 * 1024 * 1024; // 5MB

        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        if ($arquivo['size'] > $tamanhoMax) {
            return false;
        }

        $tipo = mime_content_type($arquivo['tmp_name']);
        if (!in_array($tipo, $permitidos, true)) {
            return false;
        }

        $ext = $tipo === 'image/png' ? 'png' : 'jpg';
        $nomeArquivo = uniqid('perfil_', true) . '.' . $ext;
        $destino = __DIR__ . '/../../public/uploads/perfil/' . $nomeArquivo;

        if (!is_dir(dirname($destino))) {
            mkdir(dirname($destino), 0755, true);
        }

        if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
            return false;
        }

        return 'uploads/perfil/' . $nomeArquivo;
    }

    // ─── LOGOUT ───────────────────────────────────────────────────────────────

    public function logout(): void
    {
        if (!empty($_SESSION['usuario_id'])) {
            try {
                (new Sessao())->remover();
            } catch (PDOException $e) {
                error_log('SICAPDA: não foi possível remover a sessão: ' . $e->getMessage());
            }
        }

        session_unset();
        session_destroy();
        $this->redirecionar('/login');
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private function redirecionar(string $rota): void
    {
        header('Location: ' . $rota);
        exit;
    }

    private function voltarComErro(string $pagina, string $mensagem): void
    {
        $_SESSION['flash_erro'] = $mensagem;
        $this->redirecionar('/' . $pagina);
    }
}