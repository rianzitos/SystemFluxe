<?php

/**
 * Preferências de cada usuário da tela de Configurações (tabela preferencias_usuario):
 *   - notificações: quais alertas aparecem no sino e no painel;
 *   - sistema: período padrão e itens por página dos Relatórios (só na versão web).
 *
 * Quem nunca salvou nada recebe os padrões abaixo. Se a tabela ainda não existir no banco
 * (migration 004 não rodada), a leitura também devolve os padrões em vez de derrubar a tela.
 * O tema (claro/escuro) não passa por aqui: ele é por navegador e fica no localStorage.
 */
class Preferencia
{
    /** Alertas que o usuário pode ligar/desligar. */
    public const NOTIFICACOES = ['previsao_demanda', 'acesso_negado', 'dispositivo_offline'];
    public const PERIODOS = ['mes', '7', '30', '90'];
    public const ITENS_PAGINA = [10, 20, 50];

    private const PADRAO_NOTIFICACOES = ['previsao_demanda' => true, 'acesso_negado' => true, 'dispositivo_offline' => true];
    private const PADRAO_SISTEMA = ['periodo_padrao' => 'mes', 'itens_pagina' => 20];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** ['notificacoes' => [chave => bool], 'sistema' => ['periodo_padrao' => string, 'itens_pagina' => int]] */
    public function obter(int $usuarioId): array
    {
        $linha = false;
        try {
            $stmt = $this->db->prepare('SELECT notificacoes, sistema FROM preferencias_usuario WHERE usuario_id = ?');
            $stmt->execute([$usuarioId]);
            $linha = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('SICAPDA: preferencias_usuario indisponível (rode a migration 004): ' . $e->getMessage());
        }

        $notificacoes = self::PADRAO_NOTIFICACOES;
        $sistema = self::PADRAO_SISTEMA;

        if ($linha) {
            $salvasNotif = json_decode((string) $linha['notificacoes'], true) ?: [];
            foreach (self::NOTIFICACOES as $chave) {
                if (isset($salvasNotif[$chave])) {
                    $notificacoes[$chave] = (bool) $salvasNotif[$chave];
                }
            }

            $salvasSis = json_decode((string) $linha['sistema'], true) ?: [];
            if (in_array((string) ($salvasSis['periodo_padrao'] ?? ''), self::PERIODOS, true)) {
                $sistema['periodo_padrao'] = (string) $salvasSis['periodo_padrao'];
            }
            if (in_array((int) ($salvasSis['itens_pagina'] ?? 0), self::ITENS_PAGINA, true)) {
                $sistema['itens_pagina'] = (int) $salvasSis['itens_pagina'];
            }
        }

        return ['notificacoes' => $notificacoes, 'sistema' => $sistema];
    }

    public function salvarNotificacao(int $usuarioId, string $chave, bool $ativo): void
    {
        $atual = $this->obter($usuarioId);
        $atual['notificacoes'][$chave] = $ativo;
        $this->gravar($usuarioId, $atual['notificacoes'], $atual['sistema']);
    }

    public function salvarSistema(int $usuarioId, string $periodo, int $itensPagina): void
    {
        $atual = $this->obter($usuarioId);
        $this->gravar($usuarioId, $atual['notificacoes'], ['periodo_padrao' => $periodo, 'itens_pagina' => $itensPagina]);
    }

    private function gravar(int $usuarioId, array $notificacoes, array $sistema): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO preferencias_usuario (usuario_id, notificacoes, sistema, atualizado_em)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE notificacoes = VALUES(notificacoes), sistema = VALUES(sistema),
                                     atualizado_em = VALUES(atualizado_em)'
        );
        $stmt->execute([
            $usuarioId,
            json_encode($notificacoes),
            json_encode($sistema),
            date('Y-m-d H:i:s'),
        ]);
    }
}
