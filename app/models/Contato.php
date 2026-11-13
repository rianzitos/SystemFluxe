<?php

/**
 * Mensagens do formulário "Contato" das páginas públicas (/ da Fluxe e /sicapda).
 *
 * Fluxo: valida → confere o limite de envios do IP → grava em `contatos` → responde na hora → em segundo
 * plano envia o e-mail para a equipe (com "Responder" indo direto para quem escreveu) e a confirmação
 * para o visitante. Se o e-mail falhar, a mensagem continua guardada e a tarefa agendada reenvia.
 * Sem a migration 007, o e-mail é enviado do mesmo jeito (só não fica guardado nem limitado por IP).
 */
class Contato
{
    public const ORIGENS = ['fluxe' => 'Site da Fluxe', 'sicapda' => 'Página do SICAPDA'];

    /** No máximo 3 mensagens a cada 10 minutos e 10 por dia do mesmo IP. */
    private const LIMITE_CURTO = 3;
    private const LIMITE_DIA = 10;
    private const MAX_TENTATIVAS = 5;

    /**
     * Valida e registra uma mensagem. Devolve a mensagem para mostrar ao visitante.
     * Lança CadastroException (422 = campo inválido, 429 = muitos envios) com o texto do erro.
     */
    public function receber(array $dados, string $ip, string $navegador): string
    {
        $nome = self::texto($dados['nome'] ?? '', 120);
        $email = self::texto($dados['email'] ?? '', 150);
        $telefone = self::texto($dados['telefone'] ?? '', 30);
        $mensagem = trim(str_replace("\r\n", "\n", (string) (is_string($dados['mensagem'] ?? null) ? $dados['mensagem'] : '')));
        $origem = is_string($dados['origem'] ?? null) && isset(self::ORIGENS[$dados['origem']]) ? $dados['origem'] : 'fluxe';

        if (mb_strlen($nome) < 2) {
            throw new CadastroException('Informe o seu nome.');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CadastroException('Informe um e-mail válido para podermos responder.');
        }
        if ($telefone !== '' && !preg_match('/^[0-9()+\-.\s]{8,30}$/', $telefone)) {
            throw new CadastroException('Informe um telefone válido (ou deixe em branco).');
        }
        if (mb_strlen($mensagem) < 10) {
            throw new CadastroException('Escreva uma mensagem com pelo menos 10 caracteres.');
        }
        if (mb_strlen($mensagem) > 5000) {
            throw new CadastroException('A mensagem pode ter no máximo 5.000 caracteres.');
        }

        $contato = [
            'id' => null, 'origem' => $origem, 'nome' => $nome, 'email' => $email,
            'telefone' => $telefone !== '' ? $telefone : null, 'mensagem' => $mensagem,
            'criado_em' => date('Y-m-d H:i:s'),
        ];

        try {
            $db = Database::connect();
            $this->conferirLimite($db, $ip);
            $db->prepare(
                'INSERT INTO contatos (origem, nome, email, telefone, mensagem, ip, navegador, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$origem, $nome, $email, $contato['telefone'], $mensagem, $ip, mb_substr($navegador, 0, 255), $contato['criado_em']]);
            $contato['id'] = (int) $db->lastInsertId();
        } catch (PDOException $e) {
            // Sem a tabela (migration 007 não rodada) ou banco fora do ar: não perde o contato, só envia direto.
            error_log('SICAPDA contato: não foi possível guardar a mensagem (rode a migration 007): ' . $e->getMessage());
        }

        Email::depoisDaResposta(fn() => $this->enviar($contato));

        return 'Mensagem enviada! Recebemos o seu contato e respondemos em até 1 dia útil. '
            . 'Enviamos uma confirmação para ' . $email . '.';
    }

    /** Reenvia as mensagens que ficaram sem e-mail para a equipe (tarefa agendada). Devolve quantas foram. */
    public function reenviarPendentes(): int
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, origem, nome, email, telefone, mensagem, criado_em FROM contatos
             WHERE enviado_em IS NULL AND tentativas < ? AND criado_em < ? AND criado_em > ?
             ORDER BY id LIMIT 20'
        );
        // Espera 5 minutos (o envio "em segundo plano" ainda pode estar acontecendo) e desiste depois de 3 dias.
        $stmt->execute([self::MAX_TENTATIVAS, date('Y-m-d H:i:s', time() - 300), date('Y-m-d H:i:s', time() - 3 * 86400)]);

        $enviados = 0;
        foreach ($stmt->fetchAll() as $contato) {
            $enviados += $this->enviar($contato, false) ? 1 : 0;
        }
        return $enviados;
    }

    /** E-mail para a equipe (+ confirmação ao visitante na primeira tentativa). Marca como enviado. */
    private function enviar(array $contato, bool $confirmar = true): bool
    {
        $ok = EmailsAutomaticos::contatoEquipe($contato);

        if (!empty($contato['id'])) {
            try {
                Database::connect()->prepare(
                    'UPDATE contatos SET tentativas = tentativas + 1, enviado_em = ? WHERE id = ?'
                )->execute([$ok ? date('Y-m-d H:i:s') : null, $contato['id']]);
            } catch (PDOException $e) {
                error_log('SICAPDA contato: ' . $e->getMessage());
            }
        }

        if ($confirmar) {
            EmailsAutomaticos::contatoConfirmacao($contato);
        }
        return $ok;
    }

    private function conferirLimite(PDO $db, string $ip): void
    {
        $stmt = $db->prepare(
            'SELECT SUM(criado_em >= ?) AS curto, COUNT(*) AS dia FROM contatos WHERE ip = ? AND criado_em >= ?'
        );
        $stmt->execute([date('Y-m-d H:i:s', time() - 600), $ip, date('Y-m-d H:i:s', time() - 86400)]);
        $uso = $stmt->fetch();

        if ((int) $uso['curto'] >= self::LIMITE_CURTO || (int) $uso['dia'] >= self::LIMITE_DIA) {
            throw new CadastroException('Você já enviou várias mensagens. Aguarde alguns minutos ou fale com a gente pelo WhatsApp.', 429);
        }
    }

    private static function texto(mixed $valor, int $max): string
    {
        $texto = is_string($valor) ? trim(preg_replace('/\s+/u', ' ', $valor) ?? '') : '';
        return mb_substr($texto, 0, $max);
    }
}
