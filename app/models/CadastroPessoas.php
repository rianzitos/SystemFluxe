<?php

/**
 * Regras do cadastro de pessoas e cartões (nome, cargo, categoria, matrícula, UID do cartão...), num só lugar:
 * o site (PessoaController, com sessão e CSRF) e o aplicativo (ApiController, com Bearer token) aplicam
 * exatamente as mesmas validações e gravam do mesmo jeito.
 *
 * Não conhece HTTP nem permissão (quem chama já conferiu que é o administrador da empresa): recebe os dados
 * como array (o $_POST do formulário ou o corpo JSON do app) e devolve ['mensagem' => ..., ...extras] em caso de
 * sucesso. Se algo estiver errado, lança CadastroException com a mensagem e o código HTTP a responder.
 * Toda consulta é filtrada pela empresa informada: uma empresa nunca mexe em pessoa ou cartão de outra.
 */
class CadastroPessoas
{
    private Pessoa $pessoa;

    public function __construct(?Pessoa $pessoa = null)
    {
        $this->pessoa = $pessoa ?? new Pessoa();
    }

    // ─── Pessoas ─────────────────────────────────────────────────────────────

    /** Cadastra a pessoa; se vier "uid", vincula o cartão junto (tudo ou nada). */
    public function criar(int $empresaId, array $dados): array
    {
        [$nome, $cargo, $categoria, $matricula] = $this->dadosDaPessoa($dados);
        $uid = $this->uidOpcional($empresaId, $dados);

        try {
            $id = $this->pessoa->criar($empresaId, $nome, $cargo, $categoria, $matricula, $uid);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'cadastrar a pessoa');
        }

        return ['mensagem' => 'Pessoa cadastrada.', 'id' => $id];
    }

    public function editar(int $empresaId, array $dados): array
    {
        $id = self::inteiro($dados['id'] ?? 0);
        $this->pessoaDaEmpresa($id, $empresaId);
        [$nome, $cargo, $categoria, $matricula] = $this->dadosDaPessoa($dados);

        try {
            $this->pessoa->atualizar($id, $empresaId, $nome, $cargo, $categoria, $matricula);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'salvar a pessoa');
        }

        return ['mensagem' => 'Dados atualizados.'];
    }

    /** Desativa ou reativa a pessoa. Desativada, ela é negada na catraca e some das contagens, mas o histórico fica. */
    public function definirAtivo(int $empresaId, array $dados): array
    {
        $id = self::inteiro($dados['id'] ?? 0);
        $this->pessoaDaEmpresa($id, $empresaId);
        $ativo = self::ligado($dados['ativo'] ?? '');

        try {
            $this->pessoa->definirAtivo($id, $empresaId, $ativo);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'alterar a pessoa');
        }

        return ['mensagem' => $ativo ? 'Pessoa reativada.' : 'Pessoa desativada.'];
    }

    // ─── Cartões ─────────────────────────────────────────────────────────────

    /** Últimos cartões lidos nas catracas que ainda não têm dono (a tela consulta enquanto o formulário está aberto). */
    public function leituras(int $empresaId): array
    {
        return ['mensagem' => 'ok', 'leituras' => $this->pessoa->leiturasDesconhecidas($empresaId)];
    }

    public function vincularCartao(int $empresaId, array $dados): array
    {
        $id = self::inteiro($dados['funcionario_id'] ?? 0);
        $this->pessoaDaEmpresa($id, $empresaId);
        $uid = $this->uidOpcional($empresaId, $dados);
        if ($uid === null) {
            throw new CadastroException('Informe o cartão (UID).', 422);
        }

        try {
            $this->pessoa->vincularCartao($id, $empresaId, $uid);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'vincular o cartão');
        }

        return ['mensagem' => 'Cartão vinculado.'];
    }

    public function definirCartaoAtivo(int $empresaId, array $dados): array
    {
        $cartao = $this->cartaoDaEmpresa(self::inteiro($dados['id'] ?? 0), $empresaId);
        $ativo = self::ligado($dados['ativo'] ?? '');

        try {
            $this->pessoa->definirCartaoAtivo((int) $cartao['id'], $ativo);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'alterar o cartão');
        }

        return ['mensagem' => $ativo ? 'Cartão ativado.' : 'Cartão desativado.'];
    }

    public function removerCartao(int $empresaId, array $dados): array
    {
        $cartao = $this->cartaoDaEmpresa(self::inteiro($dados['id'] ?? 0), $empresaId);

        try {
            $this->pessoa->removerCartao((int) $cartao['id']);
        } catch (PDOException $e) {
            $this->falhaDeGravacao($e, 'remover o cartão');
        }

        return ['mensagem' => 'Cartão removido.'];
    }

    // ─── Validação ───────────────────────────────────────────────────────────

    /** Nome, cargo, categoria e matrícula do formulário, já validados. */
    private function dadosDaPessoa(array $dados): array
    {
        $limpa = fn(string $campo) => trim((string) preg_replace('/\s+/u', ' ', self::texto($dados[$campo] ?? '')));

        $nome = $limpa('nome');
        $cargo = $limpa('cargo');
        $categoria = self::texto($dados['categoria'] ?? 'operador');
        $matricula = $limpa('matricula');

        if ($nome === '' || mb_strlen($nome) > 150) {
            throw new CadastroException('Informe o nome (até 150 caracteres).', 422);
        }
        if ($cargo === '' || mb_strlen($cargo) > 100) {
            throw new CadastroException('Informe o cargo ou função (até 100 caracteres).', 422);
        }
        if (!isset(Acesso::CATEGORIAS[$categoria])) {
            throw new CadastroException('Escolha uma categoria válida.', 422);
        }
        if (mb_strlen($matricula) > 30) {
            throw new CadastroException('A matrícula pode ter no máximo 30 caracteres.', 422);
        }

        return [$nome, $cargo, $categoria, $matricula !== '' ? $matricula : null];
    }

    /**
     * UID do formulário (campo "uid"), normalizado e conferido: formato válido e livre no banco.
     * null se o campo veio vazio (para quem aceita cadastrar sem cartão).
     */
    private function uidOpcional(int $empresaId, array $dados): ?string
    {
        $uid = Catraca::normalizarUid(self::texto($dados['uid'] ?? ''));
        if ($uid === '') {
            return null;
        }
        if (!preg_match(Pessoa::PADRAO_UID, $uid)) {
            throw new CadastroException('Cartão inválido. Use só letras, números, hífen ou sublinhado (de 4 a 100 caracteres).', 422);
        }

        $dono = $this->pessoa->donoDoCartao($uid, $empresaId);
        if ($dono !== null) {
            throw new CadastroException($dono['mesma_empresa']
                ? 'Este cartão já está vinculado a ' . $dono['nome'] . '. Remova-o dessa pessoa antes de usar de novo.'
                : 'Este cartão já está em uso por outra empresa.', 409);
        }

        return $uid;
    }

    private function pessoaDaEmpresa(int $id, int $empresaId): array
    {
        $pessoa = $id > 0 ? $this->pessoa->buscar($id, $empresaId) : false;
        if (!$pessoa) {
            throw new CadastroException('Pessoa não encontrada.', 404);
        }
        return $pessoa;
    }

    private function cartaoDaEmpresa(int $id, int $empresaId): array
    {
        $cartao = $id > 0 ? $this->pessoa->buscarCartao($id, $empresaId) : false;
        if (!$cartao) {
            throw new CadastroException('Cartão não encontrado.', 404);
        }
        return $cartao;
    }

    /** Falha ao gravar: cartão que alguém vinculou no meio tempo vira 409; o resto, erro genérico no log. */
    private function falhaDeGravacao(PDOException $e, string $acao): never
    {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            throw new CadastroException('Este cartão acabou de ser vinculado a outra pessoa.', 409);
        }
        error_log('SICAPDA: falha ao ' . $acao . ': ' . $e->getMessage());
        throw new CadastroException('Não foi possível ' . $acao . '. Tente novamente.', 500);
    }

    // ─── Conversões ──────────────────────────────────────────────────────────

    /** Os dados vêm do formulário (sempre texto) ou do JSON do app (pode ser número, booleano ou até lista): só texto simples vale. */
    private static function texto(mixed $valor): string
    {
        return is_scalar($valor) ? (string) $valor : '';
    }

    private static function inteiro(mixed $valor): int
    {
        return is_scalar($valor) ? (int) $valor : 0;
    }

    /** "1" (formulário), 1 ou true (JSON) = ligado; qualquer outra coisa, inclusive campo ausente, = desligado. */
    private static function ligado(mixed $valor): bool
    {
        return $valor === '1' || $valor === 1 || $valor === true;
    }
}
