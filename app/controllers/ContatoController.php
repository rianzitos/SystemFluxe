<?php

/**
 * POST /contato — formulário "Contato" das páginas públicas (/ e /sicapda). Público, sem login.
 *
 * Responde JSON quando chamado pelo fetch() do public/js/contato.js e, sem JavaScript, redireciona de volta
 * para a página com ?contato=ok|erro#contato. Anti-spam: campo isca invisível (robôs preenchem), conferência
 * de origem (só aceita envios deste site) e limite por IP (Contato).
 */
class ContatoController
{
    public function enviar(): never
    {
        $origem = ($_POST['origem'] ?? '') === 'sicapda' ? 'sicapda' : 'fluxe';

        // Campo isca: invisível para pessoas. Se veio preenchido, finge que deu certo e descarta.
        if (trim((string) ($_POST['empresa_site'] ?? '')) !== '') {
            $this->responder(true, 'Mensagem enviada!', 200, $origem);
        }

        if (!$this->mesmaOrigem()) {
            $this->responder(false, 'Envio não permitido. Recarregue a página e tente novamente.', 403, $origem);
        }

        try {
            $mensagem = (new Contato())->receber(
                $_POST,
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
            );
        } catch (CadastroException $e) {
            $this->responder(false, $e->getMessage(), $e->status(), $origem);
        } catch (Throwable $e) {
            error_log('SICAPDA contato: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível enviar agora. Tente novamente ou fale com a gente pelo WhatsApp.', 500, $origem);
        }

        $this->responder(true, $mensagem, 200, $origem);
    }

    /** Navegadores mandam Origin (ou Referer) em POST: precisa ser este mesmo site. */
    private function mesmaOrigem(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $de = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($de === '' || $de === 'null') {
            return false;
        }
        $hostDe = strtolower((string) parse_url($de, PHP_URL_HOST));
        $porta = parse_url($de, PHP_URL_PORT);
        return $hostDe !== '' && $hostDe . ($porta ? ':' . $porta : '') === $host;
    }

    private function responder(bool $sucesso, string $mensagem, int $status, string $origem): never
    {
        $querJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        if ($querJson) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['sucesso' => $sucesso, 'mensagem' => $mensagem], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Sem JavaScript: volta para a página do formulário, que mostra o resultado.
        $pagina = $origem === 'sicapda' ? '/sicapda' : '/';
        header('Location: ' . $pagina . '?contato=' . ($sucesso ? 'ok' : 'erro') . '#contato', true, 303);
        exit;
    }
}
