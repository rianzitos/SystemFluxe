<?php

/**
 * Cliente SMTP mínimo (RFC 5321) usado por Email: conexão SSL (porta 465) ou STARTTLS (porta 587),
 * AUTH LOGIN/PLAIN e um envio por conexão. O certificado do servidor é sempre verificado.
 * Erros viram RuntimeException com a resposta do servidor (Email registra no error_log).
 */
class Smtp
{
    private const TIMEOUT = 20;

    /** @var resource|null */
    private $conexao = null;

    public function __construct(
        private string $host,
        private int $porta,
        private string $seguranca,   // tls | ssl | none
        private string $usuario,
        private string $senha
    ) {
        if ($this->host === '') {
            throw new RuntimeException('MAIL_HOST não configurado no config/.env.');
        }
    }

    /** Envia a mensagem já montada (cabeçalhos + corpo, com CRLF). */
    public function enviar(string $de, array $para, string $mensagem): bool
    {
        try {
            $this->conectar();
            $this->comando('MAIL FROM:<' . $de . '>', [250]);
            foreach ($para as $destino) {
                $this->comando('RCPT TO:<' . $destino . '>', [250, 251]);
            }
            $this->comando('DATA', [354]);

            // Linhas que começam com "." ganham outro "." (dot-stuffing); o "." sozinho encerra a mensagem.
            $mensagem = preg_replace('/(?<!\r)\n/', "\r\n", $mensagem);
            $mensagem = preg_replace('/^\./m', '..', $mensagem);
            $this->escrever($mensagem . "\r\n.");
            $this->esperar([250]);

            $this->comando('QUIT', [221]);
            return true;
        } finally {
            if (is_resource($this->conexao)) {
                fclose($this->conexao);
            }
            $this->conexao = null;
        }
    }

    private function conectar(): void
    {
        $contexto = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $this->host,
            'SNI_enabled'      => true,
        ]]);
        $esquema = $this->seguranca === 'ssl' ? 'ssl://' : 'tcp://';

        $conexao = @stream_socket_client($esquema . $this->host . ':' . $this->porta, $erro, $mensagem, self::TIMEOUT, STREAM_CLIENT_CONNECT, $contexto);
        if ($conexao === false) {
            throw new RuntimeException("não conectou em {$this->host}:{$this->porta} ({$mensagem})");
        }
        $this->conexao = $conexao;
        stream_set_timeout($this->conexao, self::TIMEOUT);

        $this->esperar([220]);
        $recursos = $this->ehlo();

        if ($this->seguranca === 'tls') {
            if (!str_contains($recursos, 'STARTTLS')) {
                throw new RuntimeException('o servidor não oferece STARTTLS (tente MAIL_SECURE=ssl e MAIL_PORT=465)');
            }
            $this->comando('STARTTLS', [220]);
            $metodo = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (@stream_socket_enable_crypto($this->conexao, true, $metodo) !== true) {
                throw new RuntimeException('falha ao iniciar TLS (certificado inválido?)');
            }
            $recursos = $this->ehlo();   // depois do TLS o servidor pode anunciar outros recursos (ex.: AUTH)
        }

        if ($this->usuario !== '') {
            $this->autenticar($recursos);
        }
    }

    private function ehlo(): string
    {
        $nome = preg_replace('/[^a-z0-9.\-]/i', '', (string) (gethostname() ?: 'localhost')) ?: 'localhost';
        return $this->comando('EHLO ' . $nome, [250]);
    }

    private function autenticar(string $recursos): void
    {
        if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $recursos) && !preg_match('/AUTH[ =][^\r\n]*LOGIN/i', $recursos)) {
            $this->comando('AUTH PLAIN ' . base64_encode("\0" . $this->usuario . "\0" . $this->senha), [235], true);
            return;
        }
        $this->comando('AUTH LOGIN', [334]);
        $this->comando(base64_encode($this->usuario), [334], true);
        $this->comando(base64_encode($this->senha), [235], true);
    }

    /** Envia uma linha e confere o código da resposta. $sigiloso: não repete o comando na mensagem de erro. */
    private function comando(string $linha, array $codigos, bool $sigiloso = false): string
    {
        $this->escrever($linha);
        return $this->esperar($codigos, $sigiloso ? 'autenticação' : strtok($linha, ' '));
    }

    private function escrever(string $dados): void
    {
        $dados .= "\r\n";
        $total = strlen($dados);
        for ($enviado = 0; $enviado < $total; $enviado += $n) {
            $n = @fwrite($this->conexao, substr($dados, $enviado));
            if ($n === false || $n === 0) {
                throw new RuntimeException('conexão SMTP interrompida');
            }
        }
    }

    /** Lê a resposta (pode ter várias linhas "250-...") e confere o código. */
    private function esperar(array $codigos, string $etapa = 'resposta'): string
    {
        $resposta = '';
        while (($linha = fgets($this->conexao, 1024)) !== false) {
            $resposta .= $linha;
            if (strlen($linha) < 4 || $linha[3] === ' ') {
                break;
            }
        }
        if ($resposta === '') {
            $info = stream_get_meta_data($this->conexao);
            throw new RuntimeException($info['timed_out'] ? 'o servidor SMTP não respondeu a tempo' : 'o servidor SMTP fechou a conexão');
        }
        $codigo = (int) substr($resposta, 0, 3);
        if (!in_array($codigo, $codigos, true)) {
            throw new RuntimeException("SMTP recusou {$etapa}: " . trim($resposta));
        }
        return $resposta;
    }
}
