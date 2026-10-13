<?php

/**
 * Backend da aba Cadastro da tela de Pessoas: cadastrar e editar pessoas, ativar/desativar, vincular
 * cartões RFID (inclusive pela lista de "últimos cartões lidos" nas catracas) e importar planilha.
 * Só o administrador da empresa altera; tudo responde JSON (menos o download do modelo da planilha).
 *
 * Rotas: POST /pessoas/criar | editar | ativo | cartao | cartao/ativo | cartao/remover | importar,
 *        GET /pessoas/leituras e GET /pessoas/modelo.csv.
 */
class PessoaController
{
    use RespondeJson;

    private const TAMANHO_MAX_PLANILHA = 1024 * 1024; // 1 MB

    private Pessoa $pessoa;

    public function __construct()
    {
        $this->pessoa = new Pessoa();
    }

    // ─── Pessoas ─────────────────────────────────────────────────────────────

    /** Cadastra a pessoa; se vier "uid", vincula o cartão junto (tudo ou nada). */
    public function criar(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        [$nome, $cargo, $categoria, $matricula] = $this->dadosDaPessoa();
        $uid = $this->uidOpcional($empresaId);

        try {
            $id = $this->pessoa->criar($empresaId, $nome, $cargo, $categoria, $matricula, $uid);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'cadastrar a pessoa');
        }

        $this->responder(true, 'Pessoa cadastrada.', 200, ['id' => $id]);
    }

    public function editar(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $id = (int) ($_POST['id'] ?? 0);
        $this->pessoaDaEmpresa($id, $empresaId);
        [$nome, $cargo, $categoria, $matricula] = $this->dadosDaPessoa();

        try {
            $this->pessoa->atualizar($id, $empresaId, $nome, $cargo, $categoria, $matricula);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'salvar a pessoa');
        }

        $this->responder(true, 'Dados atualizados.');
    }

    /** Desativa ou reativa a pessoa. Desativada, ela é negada na catraca e some das contagens, mas o histórico fica. */
    public function definirAtivo(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $id = (int) ($_POST['id'] ?? 0);
        $this->pessoaDaEmpresa($id, $empresaId);
        $ativo = ($_POST['ativo'] ?? '') === '1';

        try {
            $this->pessoa->definirAtivo($id, $empresaId, $ativo);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'alterar a pessoa');
        }

        $this->responder(true, $ativo ? 'Pessoa reativada.' : 'Pessoa desativada.');
    }

    // ─── Cartões ─────────────────────────────────────────────────────────────

    /** Últimos cartões lidos nas catracas que ainda não têm dono (a tela consulta enquanto o modal está aberto). */
    public function leituras(): void
    {
        $this->exigirSessaoGet();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $this->responder(true, 'ok', 200, ['leituras' => $this->pessoa->leiturasDesconhecidas($empresaId)]);
    }

    public function vincularCartao(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $id = (int) ($_POST['funcionario_id'] ?? 0);
        $this->pessoaDaEmpresa($id, $empresaId);
        $uid = $this->uidOpcional($empresaId);
        if ($uid === null) {
            $this->responder(false, 'Informe o cartão (UID).', 422);
        }

        try {
            $this->pessoa->vincularCartao($id, $empresaId, $uid);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'vincular o cartão');
        }

        $this->responder(true, 'Cartão vinculado.');
    }

    public function definirCartaoAtivo(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $cartao = $this->cartaoDaEmpresa((int) ($_POST['id'] ?? 0), $empresaId);
        $ativo = ($_POST['ativo'] ?? '') === '1';

        try {
            $this->pessoa->definirCartaoAtivo((int) $cartao['id'], $ativo);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'alterar o cartão');
        }

        $this->responder(true, $ativo ? 'Cartão ativado.' : 'Cartão desativado.');
    }

    public function removerCartao(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $cartao = $this->cartaoDaEmpresa((int) ($_POST['id'] ?? 0), $empresaId);

        try {
            $this->pessoa->removerCartao((int) $cartao['id']);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'remover o cartão');
        }

        $this->responder(true, 'Cartão removido.');
    }

    // ─── Planilha ────────────────────────────────────────────────────────────

    public function importar(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $arquivo = $_FILES['arquivo'] ?? null;
        if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->responder(false, 'Escolha o arquivo da planilha (.csv).', 422);
        }
        if ($arquivo['error'] !== UPLOAD_ERR_OK) {
            $this->responder(false, 'Falha no envio do arquivo. Tente novamente.', 422);
        }
        if ($arquivo['size'] > self::TAMANHO_MAX_PLANILHA) {
            $this->responder(false, 'O arquivo passa de 1 MB. Divida a planilha em partes menores.', 422);
        }

        $conteudo = (string) file_get_contents($arquivo['tmp_name']);

        try {
            $lido = CsvPessoas::interpretar($conteudo);
        } catch (InvalidArgumentException $e) {
            $this->responder(false, $e->getMessage(), 422);
        }

        try {
            $resultado = $this->pessoa->importar($empresaId, $lido['linhas']);
        } catch (PDOException $e) {
            error_log('SICAPDA: falha na importação: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível importar. Nada foi gravado; tente novamente.', 500);
        }

        $erros = array_merge($lido['erros'], $resultado['erros']);
        usort($erros, fn($a, $b) => $a['linha'] <=> $b['linha']);

        $this->responder(true, 'Importação concluída.', 200, [
            'total'     => $lido['total'],
            'pessoas'   => $resultado['pessoas'],
            'cartoes'   => $resultado['cartoes'],
            'ignoradas' => count($erros),
            'erros'     => array_slice($erros, 0, 100),
        ]);
    }

    /** Baixa o modelo (cabeçalho + 2 exemplos) para preencher no Excel. */
    public function modeloCsv(): void
    {
        AuthMiddleware::autenticado();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modelo-importacao-pessoas.csv"');
        header('Cache-Control: no-store');
        echo CsvPessoas::modelo();
        exit;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Nome, cargo, categoria e matrícula do formulário, já validados. */
    private function dadosDaPessoa(): array
    {
        $limpa = fn(string $campo) => trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST[$campo] ?? '')));

        $nome = $limpa('nome');
        $cargo = $limpa('cargo');
        $categoria = (string) ($_POST['categoria'] ?? 'operador');
        $matricula = $limpa('matricula');

        if ($nome === '' || mb_strlen($nome) > 150) {
            $this->responder(false, 'Informe o nome (até 150 caracteres).', 422);
        }
        if ($cargo === '' || mb_strlen($cargo) > 100) {
            $this->responder(false, 'Informe o cargo ou função (até 100 caracteres).', 422);
        }
        if (!isset(Acesso::CATEGORIAS[$categoria])) {
            $this->responder(false, 'Escolha uma categoria válida.', 422);
        }
        if (mb_strlen($matricula) > 30) {
            $this->responder(false, 'A matrícula pode ter no máximo 30 caracteres.', 422);
        }

        return [$nome, $cargo, $categoria, $matricula !== '' ? $matricula : null];
    }

    /**
     * UID do formulário (campo "uid"), normalizado e conferido: formato válido e livre no banco.
     * null se o campo veio vazio (para quem aceita cadastrar sem cartão).
     */
    private function uidOpcional(int $empresaId): ?string
    {
        $uid = Catraca::normalizarUid((string) ($_POST['uid'] ?? ''));
        if ($uid === '') {
            return null;
        }
        if (!preg_match(Pessoa::PADRAO_UID, $uid)) {
            $this->responder(false, 'Cartão inválido. Use só letras, números, hífen ou sublinhado (de 4 a 100 caracteres).', 422);
        }

        $dono = $this->pessoa->donoDoCartao($uid, $empresaId);
        if ($dono !== null) {
            $this->responder(false, $dono['mesma_empresa']
                ? 'Este cartão já está vinculado a ' . $dono['nome'] . '. Remova-o dessa pessoa antes de usar de novo.'
                : 'Este cartão já está em uso por outra empresa.', 409);
        }

        return $uid;
    }

    private function pessoaDaEmpresa(int $id, int $empresaId): array
    {
        $pessoa = $id > 0 ? $this->pessoa->buscar($id, $empresaId) : false;
        if (!$pessoa) {
            $this->responder(false, 'Pessoa não encontrada.', 404);
        }
        return $pessoa;
    }

    private function cartaoDaEmpresa(int $id, int $empresaId): array
    {
        $cartao = $id > 0 ? $this->pessoa->buscarCartao($id, $empresaId) : false;
        if (!$cartao) {
            $this->responder(false, 'Cartão não encontrado.', 404);
        }
        return $cartao;
    }

    /** Falha ao gravar: cartão que alguém vinculou no meio tempo vira 409; o resto, erro genérico no log. */
    private function falhaDeGravacao(PDOException $e, string $acao): never
    {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            $this->responder(false, 'Este cartão acabou de ser vinculado a outra pessoa.', 409);
        }
        error_log('SICAPDA: falha ao ' . $acao . ': ' . $e->getMessage());
        $this->responder(false, 'Não foi possível ' . $acao . '. Tente novamente.', 500);
    }
}
