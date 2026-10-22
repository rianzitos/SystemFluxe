<?php

/**
 * API dos dispositivos IoT (catracas/leitores ESP32). Quem chama aqui é o aparelho, não uma pessoa
 * nem um navegador: não há login, sessão, cookie nem CORS. A autenticação é o token do dispositivo
 * (tabela dispositivos_iot) no cabeçalho  X-Device-Token  de toda requisição.
 *
 * Rotas (todas POST, sob /api/iot):
 *   POST /api/iot/acesso   {uid, direcao?}  → o cartão foi lido: libera ou nega e grava em logs_acesso
 *   POST /api/iot/ping                      → "estou vivo" (a ESP32 manda de tempos em tempos)
 *   POST /api/iot/desperdicio {desperdicio_kg, tipo} → a balança fechou o turno: grava o desperdício do dia
 * Resposta de /acesso (HTTP 200 sempre que o dispositivo é válido; quem decide abrir a catraca é o
 * campo "autorizado"):
 *   {sucesso, autorizado, direcao?, nome?, motivo?, mensagem, repetido?}
 *
 * Tudo o que o painel, os relatórios e a previsão de demanda leem (logs_acesso) passa a ser
 * alimentado por aqui, sem mexer em mais nada.
 */
class IotController
{
    public function despachar(string $uri, string $metodo): never
    {
        $rota = '/' . trim(substr($uri, strlen('/api/iot')), '/');

        if ($metodo !== 'POST') {
            Api::erro(405, 'Método não permitido.');
        }

        $dispositivo = $this->autenticarDispositivo();

        try {
            switch ($rota) {
                case '/acesso':
                    $this->acesso($dispositivo);
                case '/ping':
                    $this->ping($dispositivo);
                case '/desperdicio':
                    $this->desperdicio($dispositivo);
            }
        } catch (Throwable $e) {
            error_log('SICAPDA IoT ' . $rota . ': ' . $e->getMessage());
            Api::erro(500, 'Não foi possível processar a leitura.');
        }

        Api::erro(404, 'Rota não encontrada.');
    }

    /** Dispositivo dono do token enviado, ou encerra com 401. */
    private function autenticarDispositivo(): array
    {
        $token = trim((string) ($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ''));
        if ($token === '' || strlen($token) > 64) {
            Api::erro(401, 'Dispositivo não autenticado.');
        }

        $dispositivo = (new Dispositivo())->buscarPorToken($token);
        if (!$dispositivo) {
            Api::erro(401, 'Token de dispositivo inválido.');
        }
        return $dispositivo;
    }

    // ─── Rotas ───────────────────────────────────────────────────────────────

    private function ping(array $dispositivo): never
    {
        (new Dispositivo())->registrarPing((int) $dispositivo['id']);
        Api::json(['hora' => date('Y-m-d H:i:s')]);
    }

    private function acesso(array $dispositivo): never
    {
        $corpo = Api::corpo();
        $uid = Catraca::normalizarUid((string) ($corpo['uid'] ?? ''));
        if ($uid === '' || strlen($uid) > 100) {
            Api::erro(422, 'Informe o uid do cartão.');
        }

        // Um leitor dedicado à saída pode mandar "direcao": "saida". Sem isso, alterna sozinho.
        $direcaoFixa = in_array($corpo['direcao'] ?? null, ['entrada', 'saida'], true) ? $corpo['direcao'] : null;

        (new Dispositivo())->registrarPing((int) $dispositivo['id']);

        // A decisão (liberar/negar, entrada/saída, log) é do serviço Catraca, o mesmo do "Simular leitura".
        Api::json(Catraca::passar((int) $dispositivo['empresa_id'], (int) $dispositivo['id'], $uid, $direcaoFixa));
    }

    private function desperdicio(array $dispositivo): never
    {
        if (($dispositivo['tipo'] ?? '') !== 'balanca') {
            Api::erro(403, 'Este dispositivo não é uma balança.');
        }

        $corpo = Api::corpo();

        $kg = filter_var($corpo['desperdicio_kg'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($kg === false || $kg < 0 || $kg > 100000) {
            Api::erro(422, 'Informe um desperdicio_kg válido.');
        }

        $tipo = (string) ($corpo['tipo'] ?? '');
        if (!in_array($tipo, ['cafe', 'almoco', 'jantar', 'ceia'], true)) {
            Api::erro(422, 'Tipo de refeição inválido.');
        }

        (new Dispositivo())->registrarPing((int) $dispositivo['id']);

        // A empresa vem do dispositivo autenticado, nunca do corpo do pedido.
        (new Producao())->registrarDesperdicio((int) $dispositivo['empresa_id'], $tipo, (float) $kg);
        Api::json([
            'sucesso'  => true,
            'mensagem' => "Desperdício de {$kg} kg registrado ({$tipo}).",
        ]);
    }
}
