<?php

class AcessoController {
    private Usuario $usuario;

    public function __construct() {
        $this->usuario = new Usuario();
    }

    // ─── LOGIN ────────────────────────────────────────────────────────────────

    public function exibirLogin(): void {
        if (!empty($_SESSION['usuario_id'])) {
            $this->redirecionar('/painel');
        }

        require_once __DIR__ . '/../../app/views/login.php';
    }

    public function processarLogin(): void {
        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if (empty($email) || empty($senha)) {
            $this->voltarComErro('login', 'Preencha todos os campos.');
            return;
        }

        $usuario = $this->usuario->buscarPorEmail($email);

        if (!$usuario || !password_verify($senha, $usuario['senha'])) {
            $this->voltarComErro('login', 'E-mail ou senha inválidos.');
            return;
        }

        session_regenerate_id(true);

        $_SESSION['usuario_id']     = $usuario['id'];
        $_SESSION['usuario_nome']   = $usuario['nome'];
        $_SESSION['usuario_perfil'] = $usuario['perfil'];
        $_SESSION['empresa_id']     = $usuario['empresa_id'];

        $this->redirecionar('/painel');
    }

    // ─── CADASTRO ─────────────────────────────────────────────────────────────

    public function exibirCadastro(): void {
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
    public function processarCadastro(): void {
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
    private function salvarCadastro(array $post, array $files): array {
        // ─── Passo 1: Administrador ───
        $admNome      = trim($post['admNome'] ?? '');
        $admCpf       = trim($post['admCpf'] ?? '');
        $admTelefone  = trim($post['admTelefone'] ?? '');
        $admEmail     = trim($post['admEmail'] ?? '');
        $admCargo     = trim($post['admCargo'] ?? '');
        $admMatricula = trim($post['admMatricula'] ?? '');

        // ─── Passo 2: Segurança ───
        $senha         = $post['senha'] ?? '';
        $confirmaSenha = $post['confirmaSenha'] ?? '';
        $fa2Habilitado = ($post['fa2'] ?? 'nao') === 'sim';
        $fa2Metodo     = $post['fa2metodo'] ?? null;

        // ─── Passo 3: Empresa ───
        $razaoSocial  = trim($post['razaoSocial'] ?? '');
        $nomeFantasia = trim($post['nomeFantasia'] ?? '');
        $cnpj         = trim($post['cnpj'] ?? '');
        $inscEstadual = trim($post['inscEstadual'] ?? '');
        $segmento     = trim($post['segmento'] ?? '');
        $qtdColab     = (int) ($post['qtdColab'] ?? 0);
        $endereco     = trim($post['endereco'] ?? '');
        $cep          = trim($post['cep'] ?? '');
        $cidade       = trim($post['cidade'] ?? '');
        $estado       = trim($post['estado'] ?? '');
        $telEmpresa   = trim($post['telEmpresa'] ?? '');
        $siteEmpresa  = trim($post['siteEmpresa'] ?? '');
        $horarioFunc  = trim($post['horarioFunc'] ?? '');
        $responsavel  = trim($post['responsavel'] ?? '');
        $emailInst    = trim($post['emailInst'] ?? '');

        // ─── Passo 4: Configuração ───
        $recursosAcesso   = $post['recursosAcesso'] ?? [];
        $possuiRefeitorio = ($post['refeitorio'] ?? 'nao') === 'sim';
        $mealCafe         = (int) ($post['mealCafe'] ?? 0);
        $mealAlmoco       = (int) ($post['mealAlmoco'] ?? 0);
        $mealJantar       = (int) ($post['mealJantar'] ?? 0);
        $mealCeia         = (int) ($post['mealCeia'] ?? 0);
        $controleAtual    = trim($post['controleAtual'] ?? 'Não');
        $integracoes      = $post['integracoes'] ?? [];

        // ─── Passo 5: Termos ───
        $aceiteTermos = isset($post['aceiteTermos']);

        // ─── Validação dos campos obrigatórios ───
        $obrigatorios = [
            'Nome do administrador'     => $admNome,
            'CPF'                       => $admCpf,
            'Telefone do admin'         => $admTelefone,
            'E-mail do admin'           => $admEmail,
            'Cargo'                     => $admCargo,
            'Senha'                     => $senha,
            'Confirmação de senha'      => $confirmaSenha,
            'Razão social'              => $razaoSocial,
            'Nome fantasia'             => $nomeFantasia,
            'CNPJ'                      => $cnpj,
            'Segmento'                  => $segmento,
            'Endereço'                  => $endereco,
            'CEP'                       => $cep,
            'Cidade'                    => $cidade,
            'Estado'                    => $estado,
            'Telefone da empresa'       => $telEmpresa,
            'Horário de funcionamento'  => $horarioFunc,
            'Responsável pelo contrato' => $responsavel,
            'E-mail institucional'      => $emailInst,
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

        $cpfLimpo  = preg_replace('/\D/', '', $admCpf);
        $cnpjLimpo = preg_replace('/\D/', '', $cnpj);
        $cepLimpo  = preg_replace('/\D/', '', $cep);

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
                'razao_social'             => $razaoSocial,
                'nome_fantasia'            => $nomeFantasia,
                'cnpj'                     => $cnpjLimpo,
                'inscricao_estadual'       => $inscEstadual,
                'segmento'                 => $segmento,
                'qtd_colaboradores'        => $qtdColab,
                'endereco'                 => $endereco,
                'cep'                      => $cepLimpo,
                'cidade'                   => $cidade,
                'estado'                   => $estado,
                'telefone'                 => $telEmpresa,
                'site'                     => $siteEmpresa,
                'horario_funcionamento'    => $horarioFunc,
                'responsavel_contrato'     => $responsavel,
                'email_institucional'      => $emailInst,
                'possui_refeitorio'        => $possuiRefeitorio,
                'controle_atual_refeicoes' => $controleAtual,
                'recursos_acesso'          => $recursosAcesso,
                'integracoes'              => $integracoes,
            ]);

            $this->usuario->criar([
                'nome'                    => $admNome,
                'email'                   => $admEmail,
                'senha'                   => $senha,
                'cpf'                     => $cpfLimpo,
                'telefone'                => $admTelefone,
                'cargo'                   => $admCargo,
                'matricula'               => $admMatricula,
                'foto'                    => $fotoPath,
                'dois_fatores_habilitado' => $fa2Habilitado,
                'dois_fatores_metodo'     => $fa2Habilitado ? $fa2Metodo : null,
            ], $empresaId, 'admin');

            if ($possuiRefeitorio) {
                (new Refeicao())->salvarMediasIniciais($empresaId, [
                    'cafe'   => $mealCafe,
                    'almoco' => $mealAlmoco,
                    'jantar' => $mealJantar,
                    'ceia'   => $mealCeia,
                ]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            return ['sucesso' => false, 'mensagem' => 'Erro ao cadastrar. Tente novamente.'];
        }

        return [
            'sucesso'  => true,
            'mensagem' => 'Cadastro realizado com sucesso!',
            'empresa'  => $nomeFantasia ?: $razaoSocial,
            'admin'    => $admNome,
            'email'    => $admEmail,
        ];
    }

    /**
     * Move e valida a foto de perfil enviada. Retorna o caminho relativo salvo
     * ou false em caso de falha.
     */
    private function salvarFotoPerfil(array $arquivo): string|false {
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

    public function logout(): void {
        session_unset();
        session_destroy();
        $this->redirecionar('/login');
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private function redirecionar(string $rota): void {
        header('Location: ' . $rota);
        exit;
    }

    private function voltarComErro(string $pagina, string $mensagem): void {
        $_SESSION['flash_erro'] = $mensagem;
        $this->redirecionar('/' . $pagina);
    }
}