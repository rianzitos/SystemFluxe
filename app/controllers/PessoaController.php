<?php

/**
 * Backend da aba Cadastro da tela de Pessoas: cadastrar e editar pessoas, ativar/desativar, vincular
 * cartões RFID (inclusive pela lista de "últimos cartões lidos" nas catracas) e importar planilha.
 * Só o administrador da empresa altera; tudo responde JSON (menos o download do modelo da planilha).
 *
 * Rotas: POST /pessoas/criar | editar | ativo | cartao | cartao/ativo | cartao/remover | importar,
 *        GET /pessoas/leituras e GET /pessoas/modelo.csv.
 *
 * As regras (validação, dono do cartão, gravação) ficam em CadastroPessoas, que o aplicativo também usa
 * pela API (/api/pessoas/*, ApiController): aqui só entram a sessão, o CSRF e a permissão do site.
 */
class PessoaController
{
    use RespondeJson;

    private const TAMANHO_MAX_PLANILHA = 1024 * 1024; // 1 MB

    private Pessoa $pessoa;
    private CadastroPessoas $cadastro;

    public function __construct()
    {
        $this->pessoa = new Pessoa();
        $this->cadastro = new CadastroPessoas($this->pessoa);
    }

    // ─── Pessoas ─────────────────────────────────────────────────────────────

    /** Cadastra a pessoa; se vier "uid", vincula o cartão junto (tudo ou nada). */
    public function criar(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $this->executar(fn() => $this->cadastro->criar($empresaId, $_POST));
    }

    public function editar(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $this->executar(fn() => $this->cadastro->editar($empresaId, $_POST));
    }

    /** Desativa ou reativa a pessoa. Desativada, ela é negada na catraca e some das contagens, mas o histórico fica. */
    public function definirAtivo(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $this->executar(fn() => $this->cadastro->definirAtivo($empresaId, $_POST));
    }

    // ─── Cartões ─────────────────────────────────────────────────────────────

    /** Últimos cartões lidos nas catracas que ainda não têm dono (a tela consulta enquanto o modal está aberto). */
    public function leituras(): void
    {
        $this->exigirSessaoGet();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $this->executar(fn() => $this->cadastro->leituras($empresaId));
    }

    public function vincularCartao(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $this->executar(fn() => $this->cadastro->vincularCartao($empresaId, $_POST));
    }

    public function definirCartaoAtivo(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $this->executar(fn() => $this->cadastro->definirCartaoAtivo($empresaId, $_POST));
    }

    public function removerCartao(): void
    {
        $this->exigirSessao();
        $empresaId = $this->exigirAdmin('pessoas e cartões');

        $this->executar(fn() => $this->cadastro->removerCartao($empresaId, $_POST));
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

    /**
     * Roda uma ação do cadastro e responde: ['mensagem' => ..., ...extras] vira {sucesso: true, mensagem, ...extras};
     * um erro de regra (CadastroException) vira {sucesso: false, mensagem} com o código HTTP dele.
     */
    private function executar(callable $acao): never
    {
        try {
            $resultado = $acao();
        } catch (CadastroException $e) {
            $this->responder(false, $e->getMessage(), $e->status());
        }

        $mensagem = $resultado['mensagem'];
        unset($resultado['mensagem']);
        $this->responder(true, $mensagem, 200, $resultado);
    }
}
