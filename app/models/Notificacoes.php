<?php

/**
 * O que o sino e a lista de alertas do painel mostram para o usuário logado.
 *
 * Soma duas fontes, cada uma ligada ou desligada pelas preferências de notificação dele
 * (tela de Configurações → Notificações):
 *   - previsão de demanda: os alertas que o Previsao já calcula (desperdício, picos, feriados...);
 *   - catracas: acessos negados hoje e dispositivos IoT que pararam de responder.
 * Só a versão web usa isto. A API do aplicativo continua devolvendo os alertas de previsão como antes.
 */
class Notificacoes
{
    private static function preferencias(): array
    {
        return (new Preferencia())->obter((int) ($_SESSION['usuario_id'] ?? 0))['notificacoes'];
    }

    /** Alertas das catracas (mesmo formato dos de Previsao::alertas()). */
    private static function dasCatracas(int $empresaId, array $pref): array
    {
        $alertas = [];

        if ($pref['acesso_negado']) {
            $stmt = Database::connect()->prepare(
                "SELECT COUNT(*) FROM logs_acesso WHERE empresa_id = ? AND resultado = 'negado' AND data_hora >= ?"
            );
            $stmt->execute([$empresaId, date('Y-m-d') . ' 00:00:00']);
            $negados = (int) $stmt->fetchColumn();

            if ($negados > 0) {
                $alertas[] = [
                    'tipo'      => 'aviso',
                    'titulo'    => 'Acessos negados hoje',
                    'descricao' => $negados === 1
                        ? '1 tentativa de acesso foi negada nas catracas hoje.'
                        : $negados . ' tentativas de acesso foram negadas nas catracas hoje.',
                ];
            }
        }

        if ($pref['dispositivo_offline']) {
            foreach ((new Dispositivo())->semSinal($empresaId) as $d) {
                $alertas[] = [
                    'tipo'      => 'aviso',
                    'titulo'    => 'Dispositivo sem sinal',
                    'descricao' => $d['nome'] . ': último sinal ' . Datas::haQuanto($d['ultimo_ping']) . '.',
                ];
            }
        }

        return $alertas;
    }

    /** Lista final de alertas: os das catracas primeiro (mais urgentes), depois os de previsão (se ligados). */
    public static function aplicar(array $alertasPrevisao, int $empresaId): array
    {
        $pref = self::preferencias();
        return array_merge(self::dasCatracas($empresaId, $pref), $pref['previsao_demanda'] ? $alertasPrevisao : []);
    }

    /** Número do sino, quando a tela só precisa da contagem dos alertas de previsão. */
    public static function contar(int $totalPrevisao, int $empresaId): int
    {
        $pref = self::preferencias();
        return ($pref['previsao_demanda'] ? $totalPrevisao : 0) + count(self::dasCatracas($empresaId, $pref));
    }
}
