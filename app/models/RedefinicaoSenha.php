<?php

/**
 * "Esqueceu a senha?" (site e aplicativo): link de uso único enviado por e-mail.
 *
 * Segurança:
 *   - a resposta é sempre a mesma, exista ou não o e-mail (não dá para descobrir quem tem conta);
 *   - o token tem 256 bits aleatórios e só o SHA-256 dele fica no banco; vale 60 minutos e uma vez;
 *   - limite de pedidos por conta e por IP; um pedido novo invalida os links anteriores;
 *   - ao trocar a senha, todas as sessões do site daquela conta são encerradas e chega um aviso por e-mail.
 * Precisa da migration 007 (tabela senha_redefinicoes).
 */
class RedefinicaoSenha
{
    public const MINUTOS_VALIDADE = 60;
    private const LIMITE_POR_CONTA_HORA = 3;
    private const LIMITE_POR_IP_HORA = 10;

    public const MENSAGEM_ENVIADA = 'Se este e-mail estiver cadastrado, você vai receber em instantes um link para criar uma senha nova. Confira também a caixa de spam.';

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** Pede o link. Devolve sempre MENSAGEM_ENVIADA; erro só para e-mail inválido (422) ou abuso (429). */
    public function solicitar(string $email, string $ip): string
    {
        $email = trim($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CadastroException('Informe um e-mail válido.');
        }

        $desde = date('Y-m-d H:i:s', time() - 3600);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM senha_redefinicoes WHERE ip = ? AND criado_em >= ?');
        $stmt->execute([$ip, $desde]);
        if ((int) $stmt->fetchColumn() >= self::LIMITE_POR_IP_HORA) {
            throw new CadastroException('Muitos pedidos seguidos. Aguarde alguns minutos e tente de novo.', 429);
        }

        $usuario = (new Usuario())->buscarPorEmail($email);
        if (!$usuario) {
            // Mesma resposta de um e-mail que existe: não revela quem tem conta.
            usleep(random_int(150000, 350000));   // tempo de resposta parecido com o de um e-mail que existe
            return self::MENSAGEM_ENVIADA;
        }

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM senha_redefinicoes WHERE usuario_id = ? AND criado_em >= ?');
        $stmt->execute([$usuario['id'], $desde]);
        if ((int) $stmt->fetchColumn() >= self::LIMITE_POR_CONTA_HORA) {
            return self::MENSAGEM_ENVIADA;   // já mandamos alguns: não lota a caixa de ninguém
        }

        $token = bin2hex(random_bytes(32));
        $agora = time();
        $this->db->prepare('UPDATE senha_redefinicoes SET usado_em = ? WHERE usuario_id = ? AND usado_em IS NULL')
            ->execute([date('Y-m-d H:i:s', $agora), $usuario['id']]);
        $this->db->prepare(
            'INSERT INTO senha_redefinicoes (usuario_id, token_hash, ip, expira_em, criado_em) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $usuario['id'], hash('sha256', $token), $ip,
            date('Y-m-d H:i:s', $agora + self::MINUTOS_VALIDADE * 60), date('Y-m-d H:i:s', $agora),
        ]);

        $link = Env::urlSite() . '/senha/redefinir?token=' . $token;
        $nome = (string) $usuario['nome'];
        Email::depoisDaResposta(fn() => EmailsAutomaticos::redefinirSenha($nome, $email, $link, self::MINUTOS_VALIDADE));

        return self::MENSAGEM_ENVIADA;
    }

    /** Pedido válido (não usado, não vencido) do token, com o nome e o e-mail da pessoa; false se não serve. */
    public function buscarValido(string $token): array|false
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $stmt = $this->db->prepare(
            'SELECT r.id, r.usuario_id, u.nome, u.email
             FROM senha_redefinicoes r JOIN usuarios u ON u.id = r.usuario_id
             WHERE r.token_hash = ? AND r.usado_em IS NULL AND r.expira_em > ? LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token), date('Y-m-d H:i:s')]);
        return $stmt->fetch();
    }

    /** Troca a senha pelo link. Lança CadastroException com o motivo. */
    public function redefinir(string $token, string $nova, string $confirmar): void
    {
        $pedido = $this->buscarValido($token);
        if (!$pedido) {
            throw new CadastroException('Este link já foi usado ou venceu. Peça um novo em "Esqueceu a senha?".', 410);
        }
        if (strlen($nova) < 8) {
            throw new CadastroException('A nova senha deve ter pelo menos 8 caracteres.');
        }
        if (strlen($nova) > 72) {
            throw new CadastroException('A nova senha pode ter no máximo 72 caracteres.');
        }
        if ($nova !== $confirmar) {
            throw new CadastroException('As senhas não coincidem.');
        }

        $usuarioId = (int) $pedido['usuario_id'];
        $agora = date('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            // Marca o link como usado primeiro: se duas abas enviarem juntas, só uma troca a senha.
            $stmt = $this->db->prepare('UPDATE senha_redefinicoes SET usado_em = ? WHERE id = ? AND usado_em IS NULL');
            $stmt->execute([$agora, $pedido['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new CadastroException('Este link já foi usado. Peça um novo em "Esqueceu a senha?".', 410);
            }
            (new Usuario())->atualizarSenha($usuarioId, $nova);
            $this->db->prepare('UPDATE senha_redefinicoes SET usado_em = ? WHERE usuario_id = ? AND usado_em IS NULL')
                ->execute([$agora, $usuarioId]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Quem tinha a senha antiga (inclusive um invasor) perde as sessões abertas no site.
        try {
            (new Sessao())->encerrarTodas($usuarioId);
        } catch (PDOException $e) {
            error_log('SICAPDA: não foi possível encerrar as sessões após redefinir a senha: ' . $e->getMessage());
        }

        Email::depoisDaResposta(fn() => EmailsAutomaticos::senhaAlterada($usuarioId, 'pelo link de "Esqueceu a senha?"'));
    }
}
