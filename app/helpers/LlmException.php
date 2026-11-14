<?php

/**
 * Falha ao falar com o serviço de IA. Quem chama decide o que mostrar: o assistente responde com o
 * motor local quando isso acontece, e a mensagem técnica vai só para o error_log.
 */
class LlmException extends RuntimeException
{
    public function __construct(
        string $mensagem,
        private int $status = 0,
        private bool $retentavel = false,
    ) {
        parent::__construct($mensagem);
    }

    /** Código HTTP da resposta (0 = não houve resposta: rede, tempo esgotado, configuração). */
    public function status(): int
    {
        return $this->status;
    }

    /** Vale tentar de novo daqui a pouco (erro do servidor, rede ou limite que libera em segundos). */
    public function retentavel(): bool
    {
        return $this->retentavel;
    }
}
