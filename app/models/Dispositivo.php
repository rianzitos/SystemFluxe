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
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
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
