<?php

/**
 * Sessões ativas do site (tabela sessoes_usuario): permite listar onde a conta está aberta e
 * encerrar uma sessão de verdade.
 *
 * Como funciona: no login a sessão é registrada com o SHA-256 do ID dela (nunca o ID). A cada
 * requisição autenticada (AuthMiddleware) conferimos se a linha ainda existe; "Encerrar" apaga a
 * linha, e a sessão que perdeu a sua é derrubada na próxima requisição.
 *
 * Só cobre o site. O aplicativo usa token assinado sem estado (ApiAuthMiddleware) e não aparece aqui.
 * Se a tabela não existir (migration 004 não rodada), tudo se desliga sem travar ninguém.
 */
class Sessao
{
    private const SEGUNDOS_ENTRE_ATUALIZACOES = 60;
    private const DIAS_PARA_LIMPAR = 30;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public static function hashAtual(): string
    {
        return hash('sha256', session_id());
    }

    /** Registra a sessão atual (chamar depois do session_regenerate_id() do login). */
    public function registrar(int $usuarioId): void
    {
        $agora = date('Y-m-d H:i:s');

        $this->db->prepare('DELETE FROM sessoes_usuario WHERE ultimo_acesso < ?')
            ->execute([date('Y-m-d H:i:s', time() - self::DIAS_PARA_LIMPAR * 86400)]);

        $this->db->prepare(
            'INSERT INTO sessoes_usuario (usuario_id, sid_hash, ip, navegador, criado_em, ultimo_acesso)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), ultimo_acesso = VALUES(ultimo_acesso)'
        )->execute([
            $usuarioId,
            self::hashAtual(),
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            $agora,
            $agora,
        ]);

        $_SESSION['sessao_registrada'] = true;
        $_SESSION['sessao_ping'] = time();
    }

    /**
     * A sessão atual continua valendo? false = foi encerrada na tela de Configurações.
     * Sessões abertas antes desta funcionalidade existir são registradas na primeira passagem.
     */
    public static function valida(): bool
    {
        try {
            $modelo = new self();

            if (empty($_SESSION['sessao_registrada'])) {
                $modelo->registrar((int) $_SESSION['usuario_id']);
                return true;
            }

            $stmt = $modelo->db->prepare('SELECT 1 FROM sessoes_usuario WHERE sid_hash = ? LIMIT 1');
            $stmt->execute([self::hashAtual()]);
            if (!$stmt->fetchColumn()) {
                return false;
            }

            if (time() - (int) ($_SESSION['sessao_ping'] ?? 0) >= self::SEGUNDOS_ENTRE_ATUALIZACOES) {
                $modelo->db->prepare('UPDATE sessoes_usuario SET ultimo_acesso = ? WHERE sid_hash = ?')
                    ->execute([date('Y-m-d H:i:s'), self::hashAtual()]);
                $_SESSION['sessao_ping'] = time();
            }
            return true;
        } catch (PDOException $e) {
            error_log('SICAPDA: sessoes_usuario indisponível (rode a migration 004): ' . $e->getMessage());
            return true;   // sem a tabela não dá para controlar sessões, mas ninguém fica trancado para fora
        }
    }

    /** Depois de session_regenerate_id(): a mesma sessão passa a ter outro ID. */
    public function trocarId(string $hashAntigo): void
    {
        $this->db->prepare('UPDATE sessoes_usuario SET sid_hash = ? WHERE sid_hash = ?')
            ->execute([self::hashAtual(), $hashAntigo]);
    }

    /** Logout: apaga o registro da sessão que está saindo. */
    public function remover(): void
    {
        $this->db->prepare('DELETE FROM sessoes_usuario WHERE sid_hash = ?')->execute([self::hashAtual()]);
    }

    /**
     * Sessões do usuário com atividade dentro do tempo que o PHP guarda uma sessão parada
     * (session.gc_maxlifetime): passado disso ela provavelmente já expirou.
     */
    public function listar(int $usuarioId): array
    {
        $janela = max((int) ini_get('session.gc_maxlifetime'), 1440);
        $stmt = $this->db->prepare(
            'SELECT id, sid_hash, ip, navegador, ultimo_acesso FROM sessoes_usuario
             WHERE usuario_id = ? AND ultimo_acesso >= ?
             ORDER BY ultimo_acesso DESC, id DESC'
        );
        $stmt->execute([$usuarioId, date('Y-m-d H:i:s', time() - $janela)]);

        $atual = self::hashAtual();
        return array_map(function ($s) use ($atual) {
            $ehAtual = hash_equals($s['sid_hash'], $atual);
            return [
                'id'            => (int) $s['id'],
                'dispositivo'   => self::descrever($s['navegador']),
                'icone'         => self::ehCelular($s['navegador']) ? 'bi-phone' : 'bi-laptop',
                'ip'            => $s['ip'] ?: '—',
                'ultimo_acesso' => $ehAtual ? 'agora' : Datas::haQuanto($s['ultimo_acesso']),
                'atual'         => $ehAtual,
            ];
        }, $stmt->fetchAll());
    }

    /** Encerra uma sessão do usuário (nunca a atual: para sair dela existe o logout). */
    public function encerrar(int $usuarioId, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sessoes_usuario WHERE id = ? AND usuario_id = ? AND sid_hash <> ?');
        $stmt->execute([$id, $usuarioId, self::hashAtual()]);
        return $stmt->rowCount() === 1;
    }

    /** Encerra todas as sessões do usuário, menos a atual. Devolve quantas foram. */
    public function encerrarOutras(int $usuarioId): int
    {
        $stmt = $this->db->prepare('DELETE FROM sessoes_usuario WHERE usuario_id = ? AND sid_hash <> ?');
        $stmt->execute([$usuarioId, self::hashAtual()]);
        return $stmt->rowCount();
    }

    // ─── Texto do navegador ──────────────────────────────────────────────────

    private static function ehCelular(?string $ua): bool
    {
        return (bool) preg_match('/Android|iPhone|iPad/i', (string) $ua);
    }

    /** "Chrome · Windows" a partir do User-Agent (a ordem das checagens importa: Edge e Opera também dizem "Chrome"). */
    public static function descrever(?string $ua): string
    {
        $ua = (string) $ua;

        $navegador = match (true) {
            str_contains($ua, 'Edg/'), str_contains($ua, 'EdgA/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/'), str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Navegador',
        };

        // Android também diz "Linux" e iPhone também diz "Mac OS X": os mais específicos vêm primeiro.
        $sistema = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh'), str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'dispositivo desconhecido',
        };

        return $navegador . ' · ' . $sistema;
    }
}
