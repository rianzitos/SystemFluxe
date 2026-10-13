<?php

/**
 * Erro de regra do cadastro de pessoas e cartões (dado inválido, pessoa ou cartão que não existe, cartão que já
 * tem dono, falha ao gravar). Carrega a mensagem para a pessoa e o código HTTP que quem chamou deve responder:
 * o site (PessoaController) e o aplicativo (ApiController) transformam o mesmo erro no formato de resposta de cada um.
 */
class CadastroException extends RuntimeException
{
    /** @param int $status 404 não encontrou, 409 conflito (cartão em uso), 422 dado inválido, 500 falha ao gravar */
    public function __construct(string $mensagem, private int $status = 422)
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return $this->status;
    }
}
