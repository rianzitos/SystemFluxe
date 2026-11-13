<?php

/**
 * Recuperação de senha pelo site (o aplicativo usa POST /api/senha/esqueci, que leva ao mesmo e-mail):
 *   GET/POST /senha/esqueci     pede o e-mail e envia o link
 *   GET/POST /senha/redefinir   ?token=... abre o formulário da senha nova e troca a senha
 * As regras ficam em RedefinicaoSenha; a tela é app/views/senha.php (mesmo visual do login).
 */
class SenhaController
{
    public function esqueci(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->tela('esqueci');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if (!CsrfMiddleware::valido()) {
            $this->tela('esqueci', ['erro' => 'Sessão expirada. Tente novamente.', 'email' => $email]);
        }

        try {
            $mensagem = (new RedefinicaoSenha())->solicitar($email, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        } catch (CadastroException $e) {
            $this->tela('esqueci', ['erro' => $e->getMessage(), 'email' => $email]);
        } catch (PDOException $e) {
            error_log('SICAPDA senha: ' . $e->getMessage() . ' (rode a migration 007)');
            $this->tela('esqueci', ['erro' => 'A recuperação de senha está indisponível no momento. Fale com o administrador da sua empresa.', 'email' => $email]);
        }

        $this->tela('enviado', ['mensagem' => $mensagem, 'email' => $email]);
    }

    public function redefinir(): void
    {
        $token = (string) ($_POST['token'] ?? ($_GET['token'] ?? ''));
        $modelo = new RedefinicaoSenha();

        try {
            $pedido = $modelo->buscarValido($token);
        } catch (PDOException $e) {
            error_log('SICAPDA senha: ' . $e->getMessage() . ' (rode a migration 007)');
            $pedido = false;
        }
        if (!$pedido) {
            $this->tela('invalido');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->tela('redefinir', ['token' => $token, 'email' => $pedido['email']]);
        }
        if (!CsrfMiddleware::valido()) {
            $this->tela('redefinir', ['token' => $token, 'email' => $pedido['email'], 'erro' => 'Sessão expirada. Tente novamente.']);
        }

        try {
            $modelo->redefinir($token, (string) ($_POST['senha'] ?? ''), (string) ($_POST['confirmar'] ?? ''));
        } catch (CadastroException $e) {
            $this->tela($e->status() === 410 ? 'invalido' : 'redefinir', ['token' => $token, 'email' => $pedido['email'], 'erro' => $e->getMessage()]);
        }

        // Se esta mesma pessoa estava logada neste navegador, a sessão antiga também cai.
        session_regenerate_id(true);
        unset($_SESSION['usuario_id'], $_SESSION['usuario_nome'], $_SESSION['usuario_perfil'], $_SESSION['empresa_id'],
            $_SESSION['usuario_foto'], $_SESSION['sessao_registrada'], $_SESSION['sessao_ping']);
        $_SESSION['flash_sucesso'] = 'Senha alterada! Entre com a senha nova.';
        header('Location: /login', true, 303);
        exit;
    }

    private function tela(string $modo, array $dados = []): never
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');   // o token do link não vaza para sites externos (ícones do CDN)
        extract(['modo' => $modo] + $dados + ['erro' => null, 'email' => '', 'token' => '', 'mensagem' => '']);
        require __DIR__ . '/../views/senha.php';
        exit;
    }
}
