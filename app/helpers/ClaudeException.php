<?php

/**
 * Falha ao falar com a API do Claude (Anthropic). Quem chama decide o que mostrar: o assistente
 * responde com o motor local quando isso acontece, e a mensagem técnica vai só para o error_log.
 */
class ClaudeException extends RuntimeException
{
    public function __construct(
        string $mensagem,
        private int $status = 0,
        private string $tipo = '',
        private bool $retentavel = false,
    ) {
        parent::__construct($mensagem);
    }

    /** Código HTTP da resposta (0 = não houve resposta: rede, tempo esgotado, configuração). */
    public function status(): int
    {
        return $this->status;
    }

    /** Tipo do erro da API (rate_limit_error, overloaded_error, authentication_error...). */
    public function tipo(): string
    {
        return $this->tipo;
    }

    /** Vale tentar de novo daqui a pouco (limite de uso, sobrecarga, erro do servidor ou da rede). */
    public function retentavel(): bool
    {
        return $this->retentavel;
    }
}
