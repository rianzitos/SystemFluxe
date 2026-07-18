<?php

class Refeicao {
    private PDO $db;

    public function __construct() {
        $this->db = Database::connect();
    }

    /**
     * Salva a média diária de refeições informada no cadastro da empresa.
     * $medias = ['cafe' => int, 'almoco' => int, 'jantar' => int, 'ceia' => int]
     */
    public function salvarMediasIniciais(int $empresaId, array $medias): void {
        $stmt = $this->db->prepare(
            'INSERT INTO refeicoes (empresa_id, tipo, media_diaria, criado_em)
             VALUES (?, ?, ?, NOW())'
        );

        foreach ($medias as $tipo => $quantidade) {
            $stmt->execute([$empresaId, $tipo, (int) $quantidade]);
        }
    }
}