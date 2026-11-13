<?php

/**
 * Envio de e-mails do SICAPDA, sem biblioteca externa (o site roda em hospedagem compartilhada, sem Composer).
 *
 * Configuração no config/.env (veja config/.env.example):
 *   MAIL_DRIVER    smtp (recomendado) | mail (função mail() do PHP) | log (não envia: grava em storage/emails/, para testes)
 *   MAIL_HOST, MAIL_PORT, MAIL_SECURE (tls = STARTTLS na 587 | ssl = 465 | none), MAIL_USER, MAIL_PASS
 *   MAIL_FROM, MAIL_FROM_NAME   remetente (no Gmail, o mesmo endereço do MAIL_USER)
 *
 * Email::enviar() nunca lança exceção: devolve true/false e registra o motivo no error_log, para que um
 * problema no servidor de e-mail nunca derrube o login, o cadastro ou a leitura de uma balança.
 */
class Email
{
    /** Envia uma mensagem com versão HTML e texto puro. $para: um e-mail ou uma lista. $opcoes: reply_to, reply_to_nome. */
    public static function enviar(string|array $para, string $assunto, string $html, string $texto, array $opcoes = []): bool
    {
        $destinos = array_values(array_unique(array_filter(
            array_map('trim', (array) $para),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
        )));
        if ($destinos === []) {
            error_log('SICAPDA e-mail: nenhum destinatário válido para "' . $assunto . '".');
            return false;
        }

        $driver = strtolower(Env::get('MAIL_DRIVER', 'smtp'));
        $remetente = self::remetente();
        if ($remetente === null && $driver !== 'log') {
            error_log('SICAPDA e-mail: defina MAIL_FROM (ou MAIL_USER) no config/.env para enviar e-mails.');
            return false;
        }
        $remetente ??= 'nao-responda@localhost';

        $replyTo = $opcoes['reply_to'] ?? null;
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            $replyTo = null;
        }

        [$cabecalhos, $corpo] = self::montar($destinos, $assunto, $html, $texto, $remetente, $replyTo, $opcoes['reply_to_nome'] ?? '');

        try {
            return match ($driver) {
                'log'  => self::gravarArquivo($destinos, $assunto, $cabecalhos, $corpo),
                'mail' => self::viaMail($destinos, $assunto, $cabecalhos, $corpo, $remetente),
                default => (new Smtp(
                    (string) Env::get('MAIL_HOST', ''),
                    (int) Env::get('MAIL_PORT', '587'),
                    strtolower((string) Env::get('MAIL_SECURE', 'tls')),
                    (string) Env::get('MAIL_USER', ''),
                    (string) Env::get('MAIL_PASS', '')
                ))->enviar($remetente, $destinos, $cabecalhos . "\r\n\r\n" . $corpo),
            };
        } catch (Throwable $e) {
            error_log('SICAPDA e-mail: falha ao enviar "' . $assunto . '": ' . $e->getMessage());
            return false;
        }
    }

    /** true quando há o mínimo para enviar (no driver smtp: servidor e remetente). */
    public static function configurado(): bool
    {
        $driver = strtolower(Env::get('MAIL_DRIVER', 'smtp'));
        if ($driver === 'log') {
            return true;
        }
        return self::remetente() !== null && ($driver === 'mail' || Env::get('MAIL_HOST') !== null);
    }

    /** E-mail que recebe as mensagens do "Fale conosco" das páginas públicas. */
    public static function destinoContato(): string
    {
        $destino = Env::get('MAIL_CONTATO', 'fluxeteam@gmail.com');
        return filter_var($destino, FILTER_VALIDATE_EMAIL) ? $destino : 'fluxeteam@gmail.com';
    }

    /**
     * Executa $tarefa depois que a resposta já foi entregue a quem pediu (o envio por SMTP leva alguns
     * segundos e não pode atrasar o login, o formulário nem a ESP32 da balança, que espera a resposta).
     * No PHP-FPM e no LiteSpeed (cPanel) a conexão é liberada antes; em outros servidores a resposta só
     * termina depois do envio, mas continua funcionando.
     */
    public static function depoisDaResposta(callable $tarefa): void
    {
        if (PHP_SAPI === 'cli') {
            self::executarTarefa($tarefa);
            return;
        }
        register_shutdown_function(function () use ($tarefa): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();   // libera a sessão: outras abas da pessoa não ficam esperando o e-mail
            }
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            self::executarTarefa($tarefa);
        });
    }

    private static function executarTarefa(callable $tarefa): void
    {
        try {
            $tarefa();
        } catch (Throwable $e) {
            error_log('SICAPDA e-mail (em segundo plano): ' . $e->getMessage());
        }
    }

    // ─── Montagem da mensagem (MIME) ─────────────────────────────────────────

    private static function remetente(): ?string
    {
        $from = Env::get('MAIL_FROM') ?? Env::get('MAIL_USER');
        return $from !== null && filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : null;
    }

    /** Cabeçalho codificado em UTF-8 (assunto e nomes com acento) e sem quebras de linha (evita injeção de cabeçalhos). */
    public static function cabecalhoUtf8(string $texto): string
    {
        $texto = trim(preg_replace('/[\r\n\t]+/', ' ', $texto));
        return preg_match('/[^\x20-\x7E]/', $texto) ? '=?UTF-8?B?' . base64_encode($texto) . '?=' : $texto;
    }

    private static function endereco(string $email, string $nome = ''): string
    {
        $nome = trim(str_replace(['"', '\\'], '', preg_replace('/[\r\n]+/', ' ', $nome)));
        return $nome === '' ? '<' . $email . '>' : self::cabecalhoUtf8($nome) . ' <' . $email . '>';
    }

    /** [cabeçalhos, corpo] de uma mensagem multipart/alternative (texto + HTML). */
    private static function montar(array $para, string $assunto, string $html, string $texto, string $de, ?string $replyTo, string $replyNome): array
    {
        $fronteira = '=_sicapda_' . bin2hex(random_bytes(12));
        $dominio = substr(strrchr($de, '@'), 1) ?: 'localhost';

        $cab = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . self::endereco($de, Env::get('MAIL_FROM_NAME', 'SICAPDA by Fluxe')),
            'To: ' . implode(', ', array_map(fn($e) => '<' . $e . '>', $para)),
            'Subject: ' . self::cabecalhoUtf8($assunto),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $dominio . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $fronteira . '"',
            'X-Mailer: SICAPDA',
        ];
        if ($replyTo !== null) {
            $cab[] = 'Reply-To: ' . self::endereco($replyTo, $replyNome);
        }

        $parte = fn(string $tipo, string $conteudo) => '--' . $fronteira . "\r\n"
            . 'Content-Type: ' . $tipo . '; charset=UTF-8' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
            . rtrim(chunk_split(base64_encode($conteudo), 76, "\r\n")) . "\r\n";

        $corpo = 'Esta mensagem está em formato MIME.' . "\r\n\r\n"
            . $parte('text/plain', $texto)
            . $parte('text/html', $html)
            . '--' . $fronteira . '--';

        return [implode("\r\n", $cab), $corpo];
    }

    // ─── Drivers simples ─────────────────────────────────────────────────────

    /** MAIL_DRIVER=log: grava a mensagem em storage/emails/ (.eml abre em qualquer leitor de e-mail). */
    private static function gravarArquivo(array $para, string $assunto, string $cabecalhos, string $corpo): bool
    {
        $pasta = __DIR__ . '/../../storage/emails';
        if (!is_dir($pasta) && !@mkdir($pasta, 0750, true)) {
            error_log('SICAPDA e-mail: não foi possível criar storage/emails/.');
            return false;
        }
        $nome = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '-' . preg_replace('/[^a-z0-9]+/i', '-', $para[0]) . '.eml';
        return @file_put_contents($pasta . '/' . $nome, $cabecalhos . "\r\n\r\n" . $corpo, LOCK_EX) !== false;
    }

    /** MAIL_DRIVER=mail: a função mail() do servidor (sem autenticação; costuma cair no spam). */
    private static function viaMail(array $para, string $assunto, string $cabecalhos, string $corpo, string $de): bool
    {
        // mail() recebe "To" e "Subject" à parte; os demais cabeçalhos vão no 4º parâmetro.
        $extras = implode("\r\n", array_filter(
            explode("\r\n", $cabecalhos),
            fn($l) => !str_starts_with($l, 'To: ') && !str_starts_with($l, 'Subject: ')
        ));
        return mail(implode(', ', $para), self::cabecalhoUtf8($assunto), $corpo, $extras, '-f' . $de);
    }
}
