<?php

/**
 * Erro de regra das ações compartilhadas entre o site e o aplicativo: cadastro de pessoas e cartões
 * (CadastroPessoas), conta do usuário (Conta) e dispositivos IoT (Integracoes). Ex.: dado inválido, pessoa,
 * cartão ou dispositivo que não existe, cartão que já tem dono, senha atual errada, falha ao gravar.
 * Carrega a mensagem para a pessoa e o código HTTP que quem chamou deve responder: o site (PessoaController,
 * ConfiguracaoController) e o aplicativo (ApiController) transformam o mesmo erro no formato de resposta de cada um.
 */
class CadastroException extends RuntimeException
{
    /** @param int $status 403 senha atual errada, 404 não encontrou, 409 conflito (cartão ou e-mail em uso), 422 dado inválido, 500 falha ao gravar */
    public function __construct(string $mensagem, private int $status = 422)
    {
        parent::__construct($mensagem);
    }

    public function status(): int
    {
        return $this->status;
    }
}
