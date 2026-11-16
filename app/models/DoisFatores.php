<?php

/**
 * Verificação em duas etapas: além da senha, o login (no site e no aplicativo) pede um código de 6 dígitos.
 * As regras ficam todas aqui; o site (AcessoController e ConfiguracaoController, com sessão e CSRF) e o aplicativo
 * (ApiController, com Bearer token) só mudam o formato da resposta. Um erro de regra vira CadastroException com a
 * mensagem e o código HTTP (403 senha atual errada, 409 estado errado, 410 recomeçar, 422 código errado, 429 abuso).
 *
 * Métodos:
 *   app    aplicativo autenticador (Google Authenticator, Microsoft Authenticator, Authy...): TOTP, RFC 6238.
 *          O segredo fica cifrado no banco (AES-256-GCM, chave derivada do API_SECRET) e o mesmo código não
 *          entra duas vezes.
 *   email  código enviado por e-mail a cada login (vale 10 minutos; só o HMAC dele fica no banco).
 * Os 10 códigos de recuperação (uso único, só o SHA-256 no banco) entram no lugar do código se a pessoa perder o
 * celular ou o acesso ao e-mail.
 *
 * Limites: 5 tentativas por código; 10 erros em 15 minutos bloqueiam novos logins da conta por 15 minutos; um
 * reenvio por minuto e até 5 por login.
 *
 * Precisa da migration 008. Sem ela, nada muda: o login pede só a senha e a tela avisa que não está disponível.
 */
class DoisFatores
{
    public const METODOS = ['app' => 'Aplicativo autenticador', 'email' => 'Código por e-mail'];
    public const MINUTOS_VALIDADE = 10;
    public const TENTATIVAS = 5;
    public const QTD_RECUPERACAO = 10;

    private const FALHAS_POR_CONTA = 10;
    private const MINUTOS_BLOQUEIO = 15;
    private const SEGUNDOS_ENTRE_ENVIOS = 60;
    private const ENVIOS_POR_DESAFIO = 5;
    /** Sem 0, 1, I e O (confundem na hora de digitar). 32 símbolos × 10 = 50 bits por código. */
    private const ALFABETO_RECUPERACAO = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    // ─── Estado ──────────────────────────────────────────────────────────────

    /** A tabela ou a coluna ainda não existe (migration 008 não rodou). */
    public static function faltaMigration(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1054, 1146], true)
            || in_array((string) $e->getCode(), ['42S02', '42S22'], true);
    }

    /**
     * Método que o login desta conta precisa pedir ('app' ou 'email'), ou null (só a senha basta).
     * Sem a migration 008 também é null: ninguém conseguiu ativar sem ela.
     */
    public function metodoExigido(int $usuarioId): ?string
    {
        try {
            $u = $this->usuario($usuarioId);
        } catch (PDOException $e) {
            if (self::faltaMigration($e)) {
                return null;
            }
            throw $e;
        }
        return $u && self::ativa($u) ? $u['dois_fatores_metodo'] : null;
    }

    /** O que a tela de Segurança mostra (site e aplicativo). */
    public function estado(int $usuarioId): array
    {
        try {
            $u = $this->usuario($usuarioId);
            $restantes = $u ? $this->codigosRestantes($usuarioId) : 0;
        } catch (PDOException $e) {
            if (!self::faltaMigration($e)) {
                throw $e;
            }
            return ['disponivel' => false, 'ativa' => false, 'metodo' => null, 'metodo_rotulo' => null, 'ativada_em' => null,
                'codigos_restantes' => 0, 'pendente' => null, 'email' => '', 'email_disponivel' => false];
        }
        if (!$u) {
            throw new CadastroException('Usuário não encontrado.', 404);
        }

        $ativa = self::ativa($u);
        $metodo = $u['dois_fatores_metodo'];
        return [
            'disponivel'        => true,
            'ativa'             => $ativa,
            'metodo'            => $ativa ? $metodo : null,
            'metodo_rotulo'     => $ativa ? self::METODOS[$metodo] : null,
            'ativada_em'        => $ativa ? date('d/m/Y', strtotime($u['dois_fatores_ativado_em'])) : null,
            'codigos_restantes' => $ativa ? $restantes : 0,
            // Escolhido no cadastro da empresa, mas ainda não configurado: a tela convida a concluir.
            'pendente'          => !$ativa && isset(self::METODOS[$metodo ?? '']) ? $metodo : null,
            'email'             => self::mascararEmail((string) $u['email']),
            'email_disponivel'  => Email::configurado(),
        ];
    }

    // ─── Login: segunda etapa ────────────────────────────────────────────────

    /**
     * A senha já foi conferida e a conta tem a verificação ativa: abre o desafio (vale 10 minutos) e, no método por
     * e-mail, envia o código. Devolve o identificador do desafio (guardado na sessão do site ou no aplicativo).
     */
    public function iniciarLogin(int $usuarioId, string $ip): array
    {
        $u = $this->usuario($usuarioId);
        if (!$u || !self::ativa($u)) {
            throw new CadastroException('A verificação em duas etapas não está ativa nesta conta.', 409);
        }
        $this->conferirBloqueio($usuarioId);

        $agora = time();
        $this->db->prepare('DELETE FROM dois_fatores_desafios WHERE criado_em < ?')
            ->execute([date('Y-m-d H:i:s', $agora - 86400)]);
        // Só o login mais recente vale (quem tentou entrar em outra aba recomeça por ali).
        $this->db->prepare("UPDATE dois_fatores_desafios SET usado_em = ? WHERE usuario_id = ? AND finalidade = 'login' AND usado_em IS NULL")
            ->execute([date('Y-m-d H:i:s', $agora), $usuarioId]);

        [$token, $desafio] = $this->criarDesafio($usuarioId, 'login', $u['dois_fatores_metodo'], $ip);
        if ($u['dois_fatores_metodo'] === 'email') {
            $this->enviarCodigo($desafio, $u);
        }

        return [
            'token'    => $token,
            'metodo'   => $u['dois_fatores_metodo'],
            'destino'  => self::mascararEmail((string) $u['email']),
            'minutos'  => self::MINUTOS_VALIDADE,
            'expira_em' => $agora + self::MINUTOS_VALIDADE * 60,
        ];
    }

    /**
     * Confere o código do login (do aplicativo, do e-mail ou um de recuperação) e devolve o ID do usuário.
     * 410 = recomeçar pelo e-mail e senha (venceu, já usado ou errou demais); 422 = código errado, tente de novo.
     */
    public function verificarLogin(string $token, mixed $codigo): int
    {
        $desafio = $this->desafioPorToken($token, 'login');
        if (!$desafio) {
            throw new CadastroException('A verificação expirou. Entre de novo com e-mail e senha.', 410);
        }
        $u = $this->usuario((int) $desafio['usuario_id']);
        if (!$u || !self::ativa($u)) {
            throw new CadastroException('A verificação em duas etapas mudou nesta conta. Entre de novo com e-mail e senha.', 410);
        }

        $normal = self::normalizar($codigo);
        if ($normal === '') {
            throw new CadastroException('Digite o código.');
        }
        $this->reservarTentativa($desafio, 'Você errou o código ' . self::TENTATIVAS . ' vezes. Por segurança, entre de novo com e-mail e senha.');

        $porRecuperacao = false;
        if (preg_match('/^\d{6}$/', $normal)) {
            $ok = $desafio['metodo'] === 'app' ? $this->conferirTotp($u, $normal) : $this->conferirCodigoEmail($desafio, $normal);
        } else {
            $ok = $porRecuperacao = $this->usarRecuperacao((int) $u['id'], $normal);
        }
        if (!$ok) {
            $this->recusar($desafio, 'Você errou o código ' . self::TENTATIVAS . ' vezes. Por segurança, entre de novo com e-mail e senha.');
        }

        $this->concluirDesafio($desafio);
        if ($porRecuperacao) {
            $restantes = $this->codigosRestantes((int) $u['id']);
            $id = (int) $u['id'];
            Email::depoisDaResposta(fn() => EmailsAutomaticos::doisFatoresAviso($id, 'recuperacao_usada', $restantes));
        }
        return (int) $u['id'];
    }

    /** "Reenviar código" do login por e-mail: código novo (o anterior deixa de valer) e mais 10 minutos. */
    public function reenviarCodigo(string $token): array
    {
        $desafio = $this->desafioPorToken($token, 'login');
        if (!$desafio) {
            throw new CadastroException('A verificação expirou. Entre de novo com e-mail e senha.', 410);
        }
        if ($desafio['metodo'] !== 'email') {
            throw new CadastroException('O código vem do aplicativo autenticador do seu celular, não por e-mail.', 409);
        }
        $u = $this->usuario((int) $desafio['usuario_id']);
        if (!$u || !self::ativa($u)) {
            throw new CadastroException('A verificação em duas etapas mudou nesta conta. Entre de novo com e-mail e senha.', 410);
        }
        $this->conferirReenvio($desafio);
        $this->enviarCodigo($desafio, $u);

        return ['mensagem' => 'Enviamos um código novo para ' . self::mascararEmail((string) $u['email']) . '. O anterior não vale mais.'];
    }

    // ─── Ativar, desativar e códigos de recuperação ──────────────────────────

    /**
     * Aplicativo autenticador, passo 1: chave nova (o QR Code). Ainda não vale para o login: só depois de
     * confirmar um código em ativar(). Devolve o QrCode para cada lado mostrar do seu jeito.
     */
    public function prepararApp(int $usuarioId): array
    {
        $u = $this->exigirInativa($usuarioId);
        $segredo = Totp::novoSegredo();
        $this->db->prepare('UPDATE usuarios SET dois_fatores_segredo = ? WHERE id = ? AND dois_fatores_ativado_em IS NULL')
            ->execute([self::cifrar($segredo), $usuarioId]);

        $uri = Totp::uri($segredo, (string) $u['email']);
        return [
            'mensagem' => 'Escaneie o QR Code com o aplicativo autenticador e digite o código que aparecer.',
            'segredo'  => $segredo,
            'chave'    => Totp::formatar($segredo),
            'uri'      => $uri,
            'qr'       => QrCode::gerar($uri),
        ];
    }

    /** Código por e-mail, passo 1: manda um código para confirmar que a pessoa recebe os e-mails do SICAPDA. */
    public function prepararEmail(int $usuarioId, string $ip): array
    {
        $u = $this->exigirInativa($usuarioId);
        if (!Email::configurado()) {
            throw new CadastroException('O envio de e-mails não está configurado neste servidor. Use o aplicativo autenticador.', 503);
        }
        $this->conferirBloqueio($usuarioId);

        $stmt = $this->db->prepare(
            "SELECT MAX(criado_em) FROM dois_fatores_desafios WHERE usuario_id = ? AND finalidade = 'ativacao'"
        );
        $stmt->execute([$usuarioId]);
        $ultimo = $stmt->fetchColumn();
        $espera = $ultimo ? strtotime($ultimo) + self::SEGUNDOS_ENTRE_ENVIOS - time() : 0;
        if ($espera > 0) {
            throw new CadastroException('Aguarde ' . $espera . ' segundos para pedir outro código.', 429);
        }

        $this->db->prepare("UPDATE dois_fatores_desafios SET usado_em = ? WHERE usuario_id = ? AND finalidade = 'ativacao' AND usado_em IS NULL")
            ->execute([date('Y-m-d H:i:s'), $usuarioId]);
        [, $desafio] = $this->criarDesafio($usuarioId, 'ativacao', 'email', $ip);
        $this->enviarCodigo($desafio, $u);

        $destino = self::mascararEmail((string) $u['email']);
        return ['mensagem' => 'Enviamos um código de 6 dígitos para ' . $destino . '. Ele vale por ' . self::MINUTOS_VALIDADE . ' minutos.', 'destino' => $destino];
    }

    /**
     * Passo 2: confere a senha atual e o código e liga a verificação. Devolve os 10 códigos de recuperação, que
     * aparecem só esta vez.
     */
    public function ativar(int $usuarioId, mixed $metodo, mixed $codigo, mixed $senhaAtual): array
    {
        if (!is_string($metodo) || !isset(self::METODOS[$metodo])) {
            throw new CadastroException('Escolha como receber o código: aplicativo autenticador ou e-mail.');
        }
        $u = $this->exigirInativa($usuarioId);
        self::conferirSenha($u, $senhaAtual);

        $normal = self::normalizar($codigo);
        if (!preg_match('/^\d{6}$/', $normal)) {
            throw new CadastroException('Digite o código de 6 dígitos.');
        }
        $agora = date('Y-m-d H:i:s');

        if ($metodo === 'app') {
            $segredo = self::decifrar($u['dois_fatores_segredo']);
            if ($segredo === null) {
                throw new CadastroException('Gere o QR Code de novo e escaneie com o aplicativo.', 409);
            }
            $passo = Totp::verificar($segredo, $normal);
            if ($passo === null) {
                throw new CadastroException('Código incorreto. Digite o código que aparece agora no aplicativo (se ele não bater, confira se o horário do celular está automático).');
            }
            $stmt = $this->db->prepare(
                "UPDATE usuarios SET dois_fatores_habilitado = 1, dois_fatores_metodo = 'app', dois_fatores_ativado_em = ?,
                    dois_fatores_ultimo_passo = ? WHERE id = ? AND dois_fatores_ativado_em IS NULL"
            );
            $stmt->execute([$agora, $passo, $usuarioId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT * FROM dois_fatores_desafios WHERE usuario_id = ? AND finalidade = 'ativacao' AND usado_em IS NULL AND expira_em > ?
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$usuarioId, $agora]);
            $desafio = $stmt->fetch();
            if (!$desafio) {
                throw new CadastroException('O código venceu ou ainda não foi pedido. Clique em "Enviar código" de novo.', 410);
            }
            $this->reservarTentativa($desafio, 'Você errou o código ' . self::TENTATIVAS . ' vezes. Peça um código novo.');
            if (!$this->conferirCodigoEmail($desafio, $normal)) {
                $this->recusar($desafio, 'Você errou o código ' . self::TENTATIVAS . ' vezes. Peça um código novo.');
            }
            $this->concluirDesafio($desafio);
            $stmt = $this->db->prepare(
                "UPDATE usuarios SET dois_fatores_habilitado = 1, dois_fatores_metodo = 'email', dois_fatores_segredo = NULL,
                    dois_fatores_ativado_em = ?, dois_fatores_ultimo_passo = NULL WHERE id = ? AND dois_fatores_ativado_em IS NULL"
            );
            $stmt->execute([$agora, $usuarioId]);
        }
        if ($stmt->rowCount() !== 1) {
            throw new CadastroException('A verificação em duas etapas já foi ativada (talvez em outra aba).', 409);
        }

        $codigos = $this->gerarRecuperacao($usuarioId);
        Email::depoisDaResposta(fn() => EmailsAutomaticos::doisFatoresAviso($usuarioId, 'ativada'));

        return ['mensagem' => 'Verificação em duas etapas ativada! Guarde os códigos de recuperação.', 'codigos' => $codigos];
    }

    /** Desliga (exige a senha atual). A partir daí o login volta a pedir só e-mail e senha. */
    public function desativar(int $usuarioId, mixed $senhaAtual): array
    {
        $u = $this->exigirAtiva($usuarioId);
        self::conferirSenha($u, $senhaAtual);

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE usuarios SET dois_fatores_habilitado = 0, dois_fatores_metodo = NULL, dois_fatores_segredo = NULL,
                    dois_fatores_ativado_em = NULL, dois_fatores_ultimo_passo = NULL WHERE id = ?'
            )->execute([$usuarioId]);
            $this->db->prepare('DELETE FROM dois_fatores_recuperacao WHERE usuario_id = ?')->execute([$usuarioId]);
            $this->db->prepare('UPDATE dois_fatores_desafios SET usado_em = ? WHERE usuario_id = ? AND usado_em IS NULL')
                ->execute([date('Y-m-d H:i:s'), $usuarioId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        Email::depoisDaResposta(fn() => EmailsAutomaticos::doisFatoresAviso($usuarioId, 'desativada'));
        return ['mensagem' => 'Verificação em duas etapas desativada. O login volta a pedir só e-mail e senha.'];
    }

    /** Novos 10 códigos de recuperação (exige a senha atual); os anteriores deixam de valer. */
    public function novosCodigos(int $usuarioId, mixed $senhaAtual): array
    {
        $u = $this->exigirAtiva($usuarioId);
        self::conferirSenha($u, $senhaAtual);

        $codigos = $this->gerarRecuperacao($usuarioId);
        Email::depoisDaResposta(fn() => EmailsAutomaticos::doisFatoresAviso($usuarioId, 'novos_codigos'));
        return ['mensagem' => 'Novos códigos gerados. Os anteriores não valem mais.', 'codigos' => $codigos];
    }

    /** "Agora não" do convite (a verificação escolhida no cadastro da empresa e ainda não configurada). */
    public function dispensar(int $usuarioId): array
    {
        $this->db->prepare('UPDATE usuarios SET dois_fatores_metodo = NULL WHERE id = ? AND dois_fatores_ativado_em IS NULL')
            ->execute([$usuarioId]);
        return ['mensagem' => 'Tudo bem. Você pode ativar quando quiser em Configurações → Segurança.'];
    }

    // ─── Apoio: usuário e senha ──────────────────────────────────────────────

    private function usuario(int $id): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT id, nome, email, senha, dois_fatores_habilitado, dois_fatores_metodo, dois_fatores_segredo,
                    dois_fatores_ativado_em, dois_fatores_ultimo_passo
             FROM usuarios WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    private static function ativa(array $u): bool
    {
        return (int) $u['dois_fatores_habilitado'] === 1
            && $u['dois_fatores_ativado_em'] !== null
            && isset(self::METODOS[$u['dois_fatores_metodo'] ?? ''])
            && ($u['dois_fatores_metodo'] !== 'app' || !empty($u['dois_fatores_segredo']));
    }

    private function exigirInativa(int $usuarioId): array
    {
        $u = $this->usuario($usuarioId);
        if (!$u) {
            throw new CadastroException('Usuário não encontrado.', 404);
        }
        if (self::ativa($u)) {
            throw new CadastroException('A verificação em duas etapas já está ativa. Para trocar o método, desative e ative de novo.', 409);
        }
        return $u;
    }

    private function exigirAtiva(int $usuarioId): array
    {
        $u = $this->usuario($usuarioId);
        if (!$u) {
            throw new CadastroException('Usuário não encontrado.', 404);
        }
        if (!self::ativa($u)) {
            throw new CadastroException('A verificação em duas etapas não está ativa nesta conta.', 409);
        }
        return $u;
    }

    private static function conferirSenha(array $u, mixed $senha): void
    {
        if (!is_string($senha) || $senha === '') {
            throw new CadastroException('Confirme com a sua senha atual.');
        }
        if (!password_verify($senha, (string) $u['senha'])) {
            throw new CadastroException('Senha atual incorreta.', 403);
        }
    }

    // ─── Apoio: desafios e códigos ───────────────────────────────────────────

    /** @return array{0: string, 1: array} [identificador de verdade, linha gravada] */
    private function criarDesafio(int $usuarioId, string $finalidade, string $metodo, string $ip): array
    {
        $token = bin2hex(random_bytes(32));
        $agora = time();
        $linha = [
            'usuario_id' => $usuarioId,
            'finalidade' => $finalidade,
            'metodo'     => $metodo,
            'token_hash' => hash('sha256', $token),
            'tentativas' => 0,
            'envios'     => 0,
            'ultimo_envio' => null,
            'expira_em'  => date('Y-m-d H:i:s', $agora + self::MINUTOS_VALIDADE * 60),
        ];
        $this->db->prepare(
            'INSERT INTO dois_fatores_desafios (usuario_id, finalidade, metodo, token_hash, ip, expira_em, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$usuarioId, $finalidade, $metodo, $linha['token_hash'], substr($ip, 0, 45) ?: null, $linha['expira_em'],
            date('Y-m-d H:i:s', $agora)]);
        $linha['id'] = (int) $this->db->lastInsertId();
        return [$token, $linha];
    }

    /** Desafio ainda válido (não usado, não vencido) pelo identificador. */
    private function desafioPorToken(mixed $token, string $finalidade): array|false
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM dois_fatores_desafios WHERE token_hash = ? AND finalidade = ? AND usado_em IS NULL AND expira_em > ? LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), $finalidade, date('Y-m-d H:i:s')]);
        return $stmt->fetch();
    }

    /** 10 erros de código em 15 minutos (somando todos os desafios da conta) bloqueiam por 15 minutos. */
    private function conferirBloqueio(int $usuarioId): void
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(tentativas), 0) FROM dois_fatores_desafios WHERE usuario_id = ? AND criado_em >= ?');
        $stmt->execute([$usuarioId, date('Y-m-d H:i:s', time() - self::MINUTOS_BLOQUEIO * 60)]);
        if ((int) $stmt->fetchColumn() >= self::FALHAS_POR_CONTA) {
            throw new CadastroException('Muitas tentativas com código errado. Por segurança, aguarde ' . self::MINUTOS_BLOQUEIO . ' minutos e tente de novo.', 429);
        }
    }

    /**
     * Gasta uma tentativa ANTES de conferir: dois envios ao mesmo tempo não passam do limite. Se o código estiver
     * certo, concluirDesafio() devolve a tentativa (só os erros contam para o bloqueio da conta).
     */
    private function reservarTentativa(array $desafio, string $mensagemEsgotou): void
    {
        $stmt = $this->db->prepare(
            'UPDATE dois_fatores_desafios SET tentativas = tentativas + 1
             WHERE id = ? AND usado_em IS NULL AND expira_em > ? AND tentativas < ?'
        );
        $stmt->execute([$desafio['id'], date('Y-m-d H:i:s'), self::TENTATIVAS]);
        if ($stmt->rowCount() !== 1) {
            throw new CadastroException($mensagemEsgotou, 410);
        }
    }

    /** Código errado: 422 com quantas tentativas restam (ou 410 na última, encerrando o desafio). */
    private function recusar(array $desafio, string $mensagemEsgotou): never
    {
        $stmt = $this->db->prepare('SELECT tentativas FROM dois_fatores_desafios WHERE id = ?');
        $stmt->execute([$desafio['id']]);
        $restam = self::TENTATIVAS - (int) $stmt->fetchColumn();
        if ($restam <= 0) {
            $this->db->prepare('UPDATE dois_fatores_desafios SET usado_em = ? WHERE id = ? AND usado_em IS NULL')
                ->execute([date('Y-m-d H:i:s'), $desafio['id']]);
            throw new CadastroException($mensagemEsgotou, 410);
        }
        throw new CadastroException('Código incorreto. ' . ($restam === 1 ? 'Resta 1 tentativa.' : 'Restam ' . $restam . ' tentativas.'));
    }

    /** Marca como usado (só um envio entra, mesmo com dois ao mesmo tempo) e devolve a tentativa reservada. */
    private function concluirDesafio(array $desafio): void
    {
        $stmt = $this->db->prepare(
            'UPDATE dois_fatores_desafios SET usado_em = ?, tentativas = GREATEST(tentativas - 1, 0) WHERE id = ? AND usado_em IS NULL'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $desafio['id']]);
        if ($stmt->rowCount() !== 1) {
            throw new CadastroException('Este código já foi usado. Entre de novo com e-mail e senha.', 410);
        }
    }

    private function conferirReenvio(array $desafio): void
    {
        if ((int) $desafio['envios'] >= self::ENVIOS_POR_DESAFIO) {
            throw new CadastroException('Você já pediu vários códigos. Entre de novo com e-mail e senha para receber outro.', 429);
        }
        $espera = $desafio['ultimo_envio'] ? strtotime($desafio['ultimo_envio']) + self::SEGUNDOS_ENTRE_ENVIOS - time() : 0;
        if ($espera > 0) {
            throw new CadastroException('Aguarde ' . $espera . ' segundos para pedir outro código.', 429);
        }
    }

    /** Sorteia o código, guarda só o HMAC dele, dá mais 10 minutos e envia (depois da resposta). */
    private function enviarCodigo(array $desafio, array $u): void
    {
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $agora = time();
        $this->db->prepare(
            'UPDATE dois_fatores_desafios SET codigo_hash = ?, envios = envios + 1, ultimo_envio = ?, expira_em = ? WHERE id = ?'
        )->execute([self::hashCodigo($desafio['token_hash'], $codigo), date('Y-m-d H:i:s', $agora),
            date('Y-m-d H:i:s', $agora + self::MINUTOS_VALIDADE * 60), $desafio['id']]);

        [$nome, $email, $finalidade] = [(string) $u['nome'], (string) $u['email'], $desafio['finalidade']];
        Email::depoisDaResposta(fn() => EmailsAutomaticos::codigoVerificacao($nome, $email, $codigo, self::MINUTOS_VALIDADE, $finalidade));
    }

    private function conferirCodigoEmail(array $desafio, string $codigo): bool
    {
        return !empty($desafio['codigo_hash']) && hash_equals($desafio['codigo_hash'], self::hashCodigo($desafio['token_hash'], $codigo));
    }

    private static function hashCodigo(string $tokenHash, string $codigo): string
    {
        return hash_hmac('sha256', $tokenHash . ':' . $codigo, Segredo::chave('dois-fatores-codigo'));
    }

    /** Código do aplicativo: confere e grava o intervalo usado (o mesmo código não entra de novo). */
    private function conferirTotp(array $u, string $codigo): bool
    {
        $segredo = self::decifrar($u['dois_fatores_segredo']);
        if ($segredo === null) {
            error_log('SICAPDA 2FA: não foi possível decifrar o segredo do usuário ' . $u['id'] . ' (o API_SECRET mudou?).');
            throw new CadastroException('Não foi possível conferir o código do aplicativo neste servidor. Entre com um código de recuperação.');
        }
        $ultimo = $u['dois_fatores_ultimo_passo'] !== null ? (int) $u['dois_fatores_ultimo_passo'] : null;
        $passo = Totp::verificar($segredo, $codigo, $ultimo);
        if ($passo === null) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE usuarios SET dois_fatores_ultimo_passo = ?
             WHERE id = ? AND (dois_fatores_ultimo_passo IS NULL OR dois_fatores_ultimo_passo < ?)'
        );
        $stmt->execute([$passo, $u['id'], $passo]);
        return $stmt->rowCount() === 1;
    }

    // ─── Apoio: códigos de recuperação ───────────────────────────────────────

    /** Troca os códigos da conta por 10 novos e devolve-os formatados ("ABCDE-FGHIJ"). */
    private function gerarRecuperacao(int $usuarioId): array
    {
        $codigos = [];
        $limite = strlen(self::ALFABETO_RECUPERACAO) - 1;
        while (count($codigos) < self::QTD_RECUPERACAO) {
            $c = '';
            for ($i = 0; $i < 10; $i++) {
                $c .= self::ALFABETO_RECUPERACAO[random_int(0, $limite)];
            }
            $codigos[$c] = true;
        }
        $codigos = array_keys($codigos);

        $agora = date('Y-m-d H:i:s');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM dois_fatores_recuperacao WHERE usuario_id = ?')->execute([$usuarioId]);
            $stmt = $this->db->prepare('INSERT INTO dois_fatores_recuperacao (usuario_id, codigo_hash, criado_em) VALUES (?, ?, ?)');
            foreach ($codigos as $c) {
                $stmt->execute([$usuarioId, hash('sha256', $c), $agora]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return array_map(fn($c) => substr($c, 0, 5) . '-' . substr($c, 5), $codigos);
    }

    private function usarRecuperacao(int $usuarioId, string $normal): bool
    {
        if (!preg_match('/^[' . self::ALFABETO_RECUPERACAO . ']{10}$/', $normal)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'UPDATE dois_fatores_recuperacao SET usado_em = ? WHERE usuario_id = ? AND codigo_hash = ? AND usado_em IS NULL LIMIT 1'
        );
        $stmt->execute([date('Y-m-d H:i:s'), $usuarioId, hash('sha256', $normal)]);
        return $stmt->rowCount() === 1;
    }

    private function codigosRestantes(int $usuarioId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM dois_fatores_recuperacao WHERE usuario_id = ? AND usado_em IS NULL');
        $stmt->execute([$usuarioId]);
        return (int) $stmt->fetchColumn();
    }

    // ─── Apoio: texto ────────────────────────────────────────────────────────

    /** "123 456", "abcde-fghij" → "123456", "ABCDEFGHIJ". */
    private static function normalizar(mixed $codigo): string
    {
        if (is_int($codigo)) {
            $codigo = str_pad((string) $codigo, 6, '0', STR_PAD_LEFT);
        }
        return is_string($codigo) ? strtoupper(preg_replace('/[\s.\-_]+/', '', trim($codigo))) : '';
    }

    /** "rian@fluxe.com" → "ri•••@fluxe.com" (mostra para onde foi o código sem expor o endereço inteiro). */
    public static function mascararEmail(string $email): string
    {
        [$nome, $dominio] = array_pad(explode('@', $email, 2), 2, '');
        $visivel = mb_substr($nome, 0, max(1, min(2, mb_strlen($nome) - 1)));
        return $visivel . '•••@' . $dominio;
    }

    // ─── Apoio: cifra do segredo do aplicativo ───────────────────────────────

    private static function cifrar(string $segredo): string
    {
        if (!function_exists('openssl_encrypt')) {
            return 'b32:' . $segredo;   // servidor sem OpenSSL (raro): guarda sem cifrar, ainda funciona
        }
        $iv = random_bytes(12);
        $tag = '';
        $cifrado = openssl_encrypt($segredo, 'aes-256-gcm', Segredo::chave('dois-fatores-totp'), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $cifrado);
    }

    private static function decifrar(?string $guardado): ?string
    {
        if ($guardado === null || $guardado === '') {
            return null;
        }
        if (str_starts_with($guardado, 'b32:')) {
            return substr($guardado, 4);
        }
        $bin = str_starts_with($guardado, 'v1:') ? base64_decode(substr($guardado, 3), true) : false;
        if ($bin === false || strlen($bin) <= 28 || !function_exists('openssl_decrypt')) {
            return null;
        }
        $segredo = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', Segredo::chave('dois-fatores-totp'), OPENSSL_RAW_DATA,
            substr($bin, 0, 12), substr($bin, 12, 16));
        return $segredo === false ? null : $segredo;
    }
}
