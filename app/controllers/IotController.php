<?php

/**
 * API dos dispositivos IoT (catracas/leitores ESP32). Quem chama aqui é o aparelho, não uma pessoa
 * nem um navegador: não há login, sessão, cookie nem CORS. A autenticação é o token do dispositivo
 * (tabela dispositivos_iot) no cabeçalho  X-Device-Token  de toda requisição.
 *
 * Rotas (todas POST, sob /api/iot):
 *   POST /api/iot/acesso   {uid, direcao?}  → o cartão foi lido: libera ou nega e grava em logs_acesso
 *   POST /api/iot/ping                      → "estou vivo" (a ESP32 manda de tempos em tempos)
 *
 * Resposta de /acesso (HTTP 200 sempre que o dispositivo é válido; quem decide abrir a catraca é o
 * campo "autorizado"):
 *   {sucesso, autorizado, direcao?, nome?, motivo?, mensagem, repetido?}
 *
 * Tudo o que o painel, os relatórios e a previsão de demanda leem (logs_acesso) passa a ser
 * alimentado por aqui, sem mexer em mais nada.
 */
class IotController
{
    /** Mesma passagem lida de novo no mesmo leitor dentro desta janela não vira outro registro. */
    private const JANELA_REPETICAO_SEGUNDOS = 10;

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

    /** UID como o banco guarda: sem espaços nem ":" e em maiúsculas ("a1:b2 c3" → "A1B2C3"). */
    private static function normalizarUid(string $uid): string
    {
        return strtoupper((string) preg_replace('/[\s:]+/', '', trim($uid)));
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
        $uid = self::normalizarUid((string) ($corpo['uid'] ?? ''));
        if ($uid === '' || strlen($uid) > 100) {
            Api::erro(422, 'Informe o uid do cartão.');
        }

        // Um leitor dedicado à saída pode mandar "direcao": "saida". Sem isso, alterna sozinho.
        $direcaoFixa = in_array($corpo['direcao'] ?? null, ['entrada', 'saida'], true) ? $corpo['direcao'] : null;

        $dispositivoId = (int) $dispositivo['id'];
        $empresaId = (int) $dispositivo['empresa_id'];
        $acesso = new Acesso();
        $agora = date('Y-m-d H:i:s');

        (new Dispositivo())->registrarPing($dispositivoId);

        $credencial = $acesso->buscarCredencial($empresaId, $uid);

        if (!$credencial) {
            // Cartão desconhecido (ou de outra empresa): fica registrado como tentativa negada.
            $acesso->registrarLeitura($empresaId, $dispositivoId, null, null, 'entrada', 'negado', $agora);
            Api::json([
                'autorizado' => false,
                'motivo'     => 'cartao_desconhecido',
                'mensagem'   => 'Cartão não cadastrado.',
            ]);
        }

        $funcionarioId = (int) $credencial['funcionario_id'];

        if (!(int) $credencial['credencial_ativa'] || !(int) $credencial['funcionario_ativo']) {
            $acesso->registrarLeitura(
                $empresaId, $dispositivoId, $funcionarioId, (int) $credencial['credencial_id'],
                'entrada', 'negado', $agora
            );
            Api::json([
                'autorizado' => false,
                'motivo'     => 'inativo',
                'mensagem'   => 'Acesso desativado.',
            ]);
        }

        // Entrada ou saída: se o último registro de hoje foi uma entrada, esta passagem é a saída.
        $ultimo = $acesso->ultimoRegistroAutorizado($funcionarioId, date('Y-m-d') . ' 00:00:00');

        // O cartão fica encostado no leitor por alguns instantes: sem esta trava a mesma
        // passagem viraria entrada e saída em sequência.
        if ($ultimo && (int) $ultimo['dispositivo_id'] === $dispositivoId
            && time() - strtotime($ultimo['data_hora']) < self::JANELA_REPETICAO_SEGUNDOS) {
            Api::json($this->respostaAutorizada($credencial, $ultimo['direcao']) + ['repetido' => true]);
        }

        $direcao = $direcaoFixa ?? (($ultimo && $ultimo['direcao'] === 'entrada') ? 'saida' : 'entrada');

        $acesso->registrarLeitura(
            $empresaId, $dispositivoId, $funcionarioId, (int) $credencial['credencial_id'],
            $direcao, 'autorizado', $agora
        );
        Api::json($this->respostaAutorizada($credencial, $direcao));
    }

    private function respostaAutorizada(array $credencial, string $direcao): array
    {
        // Só o primeiro nome: o visor do leitor fica à vista de quem está na fila.
        $primeiroNome = explode(' ', trim((string) $credencial['nome']))[0];

        return [
            'autorizado' => true,
            'direcao'    => $direcao,
            'nome'       => $primeiroNome,
            'mensagem'   => $direcao === 'entrada' ? 'Entrada liberada.' : 'Saída registrada.',
        ];
    }
}
