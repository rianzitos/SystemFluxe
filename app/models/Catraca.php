<?php

/**
 * A decisão de uma passagem de cartão na catraca: conferir o cartão, liberar ou negar, alternar
 * entrada/saída e gravar em logs_acesso. É usada pela API dos dispositivos (IotController, quando a
 * ESP32 lê um cartão) e pelo "Simular leitura" das Configurações, que passa pela mesma regra.
 *
 * Não marca o dispositivo como online: isso é o ping da própria ESP32 (quem chama decide).
 */
class Catraca
{
    /** Mesma passagem lida de novo no mesmo leitor dentro desta janela não vira outro registro. */
    public const JANELA_REPETICAO_SEGUNDOS = 10;

    /** UID como o banco guarda: sem espaços nem ":" e em maiúsculas ("a1:b2 c3" → "A1B2C3"). */
    public static function normalizarUid(string $uid): string
    {
        return strtoupper((string) preg_replace('/[\s:]+/', '', trim($uid)));
    }

    /**
     * Decide e registra a passagem do cartão $uid (já normalizado) no dispositivo dado.
     * $direcaoFixa ('entrada' ou 'saida') força o sentido; sem ela, alterna a cada passagem.
     *
     * Devolve o corpo da resposta, sem o campo "sucesso":
     *   autorizado, direcao?, nome?, motivo? ('cartao_desconhecido' | 'inativo'), mensagem, repetido?
     */
    public static function passar(int $empresaId, int $dispositivoId, string $uid, ?string $direcaoFixa = null): array
    {
        $acesso = new Acesso();
        $agora = date('Y-m-d H:i:s');

        $credencial = $acesso->buscarCredencial($empresaId, $uid);

        if (!$credencial) {
            // Cartão desconhecido (ou de outra empresa): fica registrado como tentativa negada e o UID
            // vai para a lista de "cartões lidos" da tela de Pessoas, para o administrador vincular.
            $acesso->registrarLeitura($empresaId, $dispositivoId, null, null, 'entrada', 'negado', $agora);
            try {
                (new Pessoa())->registrarLeituraDesconhecida($empresaId, $uid, $dispositivoId);
            } catch (PDOException $e) {
                error_log('SICAPDA: cartoes_nao_cadastrados indisponível (rode a migration 005): ' . $e->getMessage());
            }

            return [
                'autorizado' => false,
                'motivo'     => 'cartao_desconhecido',
                'uid'        => $uid,
                'mensagem'   => 'Cartão não cadastrado.',
            ];
        }

        $funcionarioId = (int) $credencial['funcionario_id'];

        if (!(int) $credencial['credencial_ativa'] || !(int) $credencial['funcionario_ativo']) {
            $acesso->registrarLeitura(
                $empresaId, $dispositivoId, $funcionarioId, (int) $credencial['credencial_id'],
                'entrada', 'negado', $agora
            );
            return [
                'autorizado' => false,
                'motivo'     => 'inativo',
                'mensagem'   => 'Acesso desativado.',
            ];
        }

        // Entrada ou saída: se o último registro de hoje foi uma entrada, esta passagem é a saída.
        $ultimo = $acesso->ultimoRegistroAutorizado($funcionarioId, date('Y-m-d') . ' 00:00:00');

        // O cartão fica encostado no leitor por alguns instantes: sem esta trava a mesma
        // passagem viraria entrada e saída em sequência.
        if ($ultimo && (int) $ultimo['dispositivo_id'] === $dispositivoId
            && time() - strtotime($ultimo['data_hora']) < self::JANELA_REPETICAO_SEGUNDOS) {
            return self::autorizada($credencial, $ultimo['direcao']) + ['repetido' => true];
        }

        $direcao = $direcaoFixa ?? (($ultimo && $ultimo['direcao'] === 'entrada') ? 'saida' : 'entrada');

        $acesso->registrarLeitura(
            $empresaId, $dispositivoId, $funcionarioId, (int) $credencial['credencial_id'],
            $direcao, 'autorizado', $agora
        );
        return self::autorizada($credencial, $direcao);
    }

    private static function autorizada(array $credencial, string $direcao): array
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
