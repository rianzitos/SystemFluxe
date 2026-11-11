<?php

/**
 * Regras dos dispositivos IoT da empresa (catracas, leitores RFID e balanças de desperdício), num só lugar:
 * Configurações → Integrações do site (ConfiguracaoController, com sessão e CSRF) e a mesma aba do aplicativo
 * (ApiController, com Bearer token) validam e gravam do mesmo jeito.
 *
 * Não conhece HTTP nem permissão (quem chama já conferiu que é o administrador da empresa): recebe os dados
 * como array e devolve ['mensagem' => ..., ...extras]. Erro de regra vira CadastroException com o código HTTP.
 * Tudo é filtrado pela empresa informada: uma empresa nunca vê nem mexe em dispositivo de outra.
 */
class Integracoes
{
    private Dispositivo $dispositivo;

    public function __construct(?Dispositivo $dispositivo = null)
    {
        $this->dispositivo = $dispositivo ?? new Dispositivo();
    }

    /**
     * O que a aba Integrações mostra: dispositivos (token mascarado, online/offline pelo último sinal), os tipos
     * que dá para cadastrar, as refeições da balança e o desperdício já registrado hoje em cada uma.
     */
    public function resumo(int $empresaId): array
    {
        $hoje = Balanca::hoje($empresaId);

        return [
            'dispositivos'    => $this->dispositivo->listar($empresaId),
            'tipos'           => self::opcoes(Dispositivo::TIPOS),
            'refeicoes'       => self::opcoes(Producao::TIPOS),
            'desperdicioHoje' => array_map(fn($tipo, $rotulo) => [
                'tipo'   => $tipo,
                'rotulo' => $rotulo,
                'kg'     => isset($hoje[$tipo]) ? round((float) $hoje[$tipo], 2) : null,
            ], array_keys(Producao::TIPOS), array_values(Producao::TIPOS)),
            'data'            => date('Y-m-d'),
            'segundosOffline' => Dispositivo::SEGUNDOS_OFFLINE,
        ];
    }

    /** Cadastra a catraca/leitor/balança e devolve o token INTEIRO, que não será mostrado de novo. */
    public function criar(int $empresaId, array $dados): array
    {
        $nome = trim(self::texto($dados['nome'] ?? ''));
        $tipo = self::texto($dados['tipo'] ?? 'rfid');
        $localizacao = trim(self::texto($dados['localizacao'] ?? ''));

        if ($nome === '' || mb_strlen($nome) > 150) {
            throw new CadastroException('Informe o nome do dispositivo (até 150 caracteres).');
        }
        if (!isset(Dispositivo::TIPOS[$tipo])) {
            throw new CadastroException('Tipo de dispositivo inválido.');
        }
        if (mb_strlen($localizacao) > 150) {
            throw new CadastroException('A localização pode ter no máximo 150 caracteres.');
        }

        try {
            $novo = $this->dispositivo->criar($empresaId, $nome, $tipo, $localizacao !== '' ? $localizacao : null);
            $dispositivo = $this->dispositivo->buscarDaEmpresa($novo['id'], $empresaId);
        } catch (PDOException $e) {
            self::falha($e, 'criar dispositivo', 'Não foi possível criar o dispositivo. Tente novamente.');
        }

        return ['mensagem' => 'Dispositivo criado.', 'dispositivo' => $dispositivo, 'token' => $novo['token']];
    }

    /** Gera outro token para o dispositivo; o aparelho que usa o antigo deixa de ser aceito. */
    public function novoToken(int $empresaId, array $dados): array
    {
        $id = self::inteiro($dados['id'] ?? 0);

        try {
            $token = $id > 0 ? $this->dispositivo->regenerarToken($id, $empresaId) : false;
            $dispositivo = $token !== false ? $this->dispositivo->buscarDaEmpresa($id, $empresaId) : false;
        } catch (PDOException $e) {
            self::falha($e, 'regenerar token', 'Não foi possível gerar o token. Tente novamente.');
        }

        if ($token === false) {
            throw new CadastroException('Dispositivo não encontrado.', 404);
        }

        return ['mensagem' => 'Novo token gerado.', 'dispositivo' => $dispositivo, 'token' => $token];
    }

    /**
     * "Simular leitura": passa um UID por uma catraca da empresa como se a ESP32 o tivesse lido, pela mesma regra
     * (Catraca::passar). Grava um acesso DE VERDADE (aparece no painel e nos relatórios); não marca o dispositivo
     * como online, porque isso só quem manda o ping é o aparelho.
     */
    public function simularLeitura(int $empresaId, array $dados): array
    {
        $id = self::inteiro($dados['id'] ?? 0);
        $dispositivo = $id > 0 ? $this->dispositivo->buscarDaEmpresa($id, $empresaId) : false;
        if (!$dispositivo) {
            throw new CadastroException('Dispositivo não encontrado.', 404);
        }
        if ($dispositivo['tipo'] === 'balanca') {
            throw new CadastroException('Use o formulário de pesagem para esta balança.');
        }

        $uid = Catraca::normalizarUid(self::texto($dados['uid'] ?? ''));
        if ($uid === '' || strlen($uid) > 100) {
            throw new CadastroException('Informe o UID do cartão.');
        }

        try {
            $resultado = Catraca::passar($empresaId, $id, $uid);
        } catch (PDOException $e) {
            self::falha($e, 'simular leitura', 'Não foi possível simular a leitura. Tente novamente.');
        }

        return ['mensagem' => $resultado['mensagem'], 'resultado' => $resultado, 'uid' => $uid];
    }

    /**
     * "Testar pesagem": registra o total de desperdício de uma refeição (Balanca::registrar). Substitui o total da
     * refeição no dia para a empresa toda, como o botão "Encerrar turno" da balança.
     */
    public function registrarPesagem(int $empresaId, array $dados): array
    {
        $dispositivo = $this->dispositivo->buscarDaEmpresa(self::inteiro($dados['id'] ?? 0), $empresaId);
        if (!$dispositivo) {
            throw new CadastroException('Balança não encontrada.', 404);
        }
        $dispositivo['empresa_id'] = $empresaId;

        try {
            return Balanca::registrar($dispositivo, $dados);
        } catch (InvalidArgumentException $e) {
            throw new CadastroException($e->getMessage());
        } catch (PDOException $e) {
            self::falha($e, 'registrar pesagem', 'Não foi possível salvar a pesagem.');
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** ['valor' => rótulo] → [{valor, rotulo}] (o formato de lista que o aplicativo usa nos seletores). */
    private static function opcoes(array $mapa): array
    {
        return array_map(fn($valor, $rotulo) => ['valor' => $valor, 'rotulo' => $rotulo], array_keys($mapa), array_values($mapa));
    }

    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? $valor : (is_int($valor) || is_float($valor) ? (string) $valor : '');
    }

    private static function inteiro(mixed $valor): int
    {
        return is_int($valor) ? $valor : (is_string($valor) && ctype_digit($valor) ? (int) $valor : 0);
    }

    private static function falha(PDOException $e, string $acao, string $mensagem): never
    {
        error_log('SICAPDA: falha ao ' . $acao . ': ' . $e->getMessage());
        throw new CadastroException($mensagem, 500);
    }
}
