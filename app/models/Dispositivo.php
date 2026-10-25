<?php

/**
 * Catracas e leitores físicos (tabela dispositivos_iot): ESP32 + RFID ou biometria.
 *
 * Cada dispositivo tem um token próprio, que ele envia no cabeçalho X-Device-Token em toda
 * requisição para /api/iot/*. O token identifica o aparelho e, por ele, a empresa dona dele:
 * é assim que um leitor nunca registra acesso na empresa errada.
 */
class Dispositivo
{
    public const TIPOS = ['rfid' => 'Cartão RFID', 'biometria' => 'Biometria', 'hibrido' => 'RFID + biometria', 'balanca' => 'Balança de desperdício'];

    /** A ESP32 manda um ping por minuto: sem sinal há mais que isto, o dispositivo conta como offline. */
    public const SEGUNDOS_OFFLINE = 180;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** "8f2a1c…c91d": o token inteiro só aparece uma vez, ao ser criado ou regenerado. */
    public static function mascarar(string $token): string
    {
        return strlen($token) <= 12 ? str_repeat('•', strlen($token)) : substr($token, 0, 6) . '…' . substr($token, -4);
    }

    /** online/offline pelo último sinal. A coluna "status" só guarda o último valor gravado e nunca volta a offline sozinha. */
    private static function statusPeloPing(?string $ultimoPing): string
    {
        return $ultimoPing !== null && strtotime($ultimoPing) >= time() - self::SEGUNDOS_OFFLINE ? 'online' : 'offline';
    }

    /** Dispositivos da empresa para a tela de Configurações (token já mascarado). */
    public function listar(int $empresaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nome, tipo, localizacao, token, ultimo_ping FROM dispositivos_iot WHERE empresa_id = ? ORDER BY nome, id'
        );
        $stmt->execute([$empresaId]);

        return array_map(fn($d) => [
            'id'          => (int) $d['id'],
            'nome'        => $d['nome'],
            'tipo'        => $d['tipo'],
            'tipo_rotulo' => self::TIPOS[$d['tipo']] ?? $d['tipo'],
            'localizacao' => $d['localizacao'],
            'token'       => self::mascarar($d['token']),
            'status'      => self::statusPeloPing($d['ultimo_ping']),
            'ultimo_ping' => $d['ultimo_ping'],
            'ultimo_sinal' => $d['ultimo_ping'] !== null ? 'Último sinal ' . Datas::haQuanto($d['ultimo_ping']) : 'Nunca conectou',
        ], $stmt->fetchAll());
    }

    /** Dispositivos que já responderam alguma vez e pararam (alerta "dispositivo offline"). */
    public function semSinal(int $empresaId): array
    {
        return array_values(array_filter(
            $this->listar($empresaId),
            fn($d) => $d['ultimo_ping'] !== null && $d['status'] === 'offline'
        ));
    }

    private static function gerarToken(): string
    {
        return bin2hex(random_bytes(32));   // 64 caracteres, cabe em dispositivos_iot.token
    }

    /** Cadastra o dispositivo e devolve ['id' => ..., 'token' => ...] com o token INTEIRO (única vez). */
    public function criar(int $empresaId, string $nome, string $tipo, ?string $localizacao): array
    {
        $token = self::gerarToken();
        $stmt = $this->db->prepare(
            "INSERT INTO dispositivos_iot (empresa_id, nome, tipo, localizacao, token, status, criado_em)
             VALUES (?, ?, ?, ?, ?, 'offline', ?)"
        );
        $stmt->execute([$empresaId, $nome, $tipo, $localizacao, $token, date('Y-m-d H:i:s')]);

        return ['id' => (int) $this->db->lastInsertId(), 'token' => $token];
    }

    /**
     * Troca o token (o aparelho com o token antigo passa a receber 401). Devolve o novo token inteiro
     * ou false se o dispositivo não existe nesta empresa.
     */
    public function regenerarToken(int $id, int $empresaId): string|false
    {
        $token = self::gerarToken();
        $stmt = $this->db->prepare("UPDATE dispositivos_iot SET token = ?, status = 'offline' WHERE id = ? AND empresa_id = ?");
        $stmt->execute([$token, $id, $empresaId]);

        return $stmt->rowCount() === 1 ? $token : false;
    }

    /** Um dispositivo da empresa, no mesmo formato de listar() (ou false). */
    public function buscarDaEmpresa(int $id, int $empresaId): array|false
    {
        foreach ($this->listar($empresaId) as $d) {
            if ($d['id'] === $id) {
                return $d;
            }
        }
        return false;
    }

    /** Dispositivo pelo token, ou false se nenhum tiver esse token. */
    public function buscarPorToken(string $token): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT id, empresa_id, nome, tipo, localizacao FROM dispositivos_iot WHERE token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        return $stmt->fetch();
    }

    /**
     * Marca o dispositivo como online agora. A data vem do PHP (fuso America/Sao_Paulo de
     * config/app.php) e não do NOW() do MySQL, que no servidor compartilhado pode estar em outro fuso.
     */
    public function registrarPing(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE dispositivos_iot SET status = 'online', ultimo_ping = ? WHERE id = ?");
        $stmt->execute([date('Y-m-d H:i:s'), $id]);
    }
}
