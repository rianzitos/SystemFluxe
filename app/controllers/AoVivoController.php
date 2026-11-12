<?php

/** GET /aovivo — o que as telas consultam para se atualizar em tempo real (ver app/models/AoVivo.php). */
class AoVivoController
{
    use RespondeJson;

    public function estado(): void
    {
        $this->exigirSessaoGet();

        $empresaId = Pagina::empresaId();
        $ultimo = isset($_GET['ultimo']) && is_string($_GET['ultimo']) && ctype_digit($_GET['ultimo']) ? (int) $_GET['ultimo'] : null;

        // Consulta feita de poucos em poucos segundos: libera o arquivo da sessão já, senão ela
        // travaria as outras requisições do mesmo usuário (a própria página, o sino, os formulários).
        session_write_close();

        if ($empresaId <= 0) {
            $this->responder(true, 'ok', 200, ['assinatura' => '', 'ultimoLog' => 0, 'eventos' => [], 'novosAcessos' => 0, 'dispositivos' => [], 'pesagens' => []]);
        }

        try {
            $estado = (new AoVivo($empresaId))->estado($ultimo);
        } catch (Throwable $e) {
            error_log('SICAPDA ao vivo: ' . $e->getMessage());
            $this->responder(false, 'Não foi possível atualizar agora.', 500);
        }

        $this->responder(true, 'ok', 200, $estado);
    }
}
