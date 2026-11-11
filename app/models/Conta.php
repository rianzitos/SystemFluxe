<?php

/**
 * Regras da conta do usuário logado (perfil, senha e foto), num só lugar: a tela de Configurações do site
 * (ConfiguracaoController, com sessão e CSRF) e o aplicativo (ApiController, com Bearer token) validam e
 * gravam do mesmo jeito, com as mesmas mensagens.
 *
 * Não conhece HTTP nem sessão: recebe os dados como array (o $_POST do site ou o corpo JSON do app) e devolve
 * ['mensagem' => ..., ...extras]. Se algo estiver errado, lança CadastroException com a mensagem e o código
 * HTTP a responder (403 senha atual errada, 404 usuário sumiu, 409 e-mail de outra conta, 422 dado inválido,
 * 500 falha ao gravar).
 */
class Conta
{
    public const FOTO_TAMANHO_MAX = 5 * 1024 * 1024; // 5 MB
    private const FOTO_TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

    private Usuario $usuario;

    public function __construct(?Usuario $usuario = null)
    {
        $this->usuario = $usuario ?? new Usuario();
    }

    // ─── Perfil (nome, e-mail, cargo) ─────────────────────────────────────────

    /** Trocar o e-mail muda o login, então exige a senha atual ("senha_atual"). */
    public function atualizarPerfil(int $id, array $dados): array
    {
        $nome       = trim(self::texto($dados['nome'] ?? ''));
        $email      = mb_strtolower(trim(self::texto($dados['email'] ?? '')));
        $cargo      = trim(self::texto($dados['cargo'] ?? ''));
        $senhaAtual = self::texto($dados['senha_atual'] ?? '');

        if ($nome === '' || mb_strlen($nome) > 150) {
            throw new CadastroException('Informe um nome válido (até 150 caracteres).');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            throw new CadastroException('Informe um e-mail válido.');
        }
        if (mb_strlen($cargo) > 100) {
            throw new CadastroException('O cargo pode ter no máximo 100 caracteres.');
        }

        $atual = $this->usuario->buscarPerfilCompleto($id);
        if (!$atual) {
            throw new CadastroException('Usuário não encontrado.', 404);
        }

        if (mb_strtolower($atual['email']) !== $email) {
            if ($senhaAtual === '') {
                throw new CadastroException('Para alterar o e-mail, informe sua senha atual.');
            }
            $this->conferirSenha($id, $senhaAtual);

            if ($this->usuario->emailExisteParaOutro($email, $id)) {
                throw new CadastroException('Este e-mail já está em uso por outra conta.', 409);
            }
        }

        try {
            $this->usuario->atualizarPerfil($id, $nome, $email, $cargo);
        } catch (PDOException $e) {
            self::falha($e, 'atualizar perfil');
        }

        return ['mensagem' => 'Perfil atualizado com sucesso!', 'nome' => $nome, 'email' => $email, 'cargo' => $cargo];
    }

    // ─── Senha ────────────────────────────────────────────────────────────────

    public function atualizarSenha(int $id, array $dados): array
    {
        $atual     = self::texto($dados['senha_atual'] ?? '');
        $nova      = self::texto($dados['senha_nova'] ?? '');
        $confirmar = self::texto($dados['senha_confirmar'] ?? '');

        if ($atual === '' || $nova === '' || $confirmar === '') {
            throw new CadastroException('Preencha todos os campos de senha.');
        }
        if (strlen($nova) < 8) {
            throw new CadastroException('A nova senha deve ter pelo menos 8 caracteres.');
        }
        if (strlen($nova) > 72) {
            // bcrypt ignora tudo após 72 bytes.
            throw new CadastroException('A nova senha pode ter no máximo 72 caracteres.');
        }
        if ($nova !== $confirmar) {
            throw new CadastroException('As senhas não coincidem.');
        }

        $this->conferirSenha($id, $atual);

        if (hash_equals($atual, $nova)) {
            throw new CadastroException('A nova senha deve ser diferente da atual.');
        }

        try {
            $this->usuario->atualizarSenha($id, $nova);
        } catch (PDOException $e) {
            self::falha($e, 'atualizar senha');
        }

        return ['mensagem' => 'Senha atualizada com sucesso!'];
    }

    // ─── Foto ─────────────────────────────────────────────────────────────────

    /**
     * Guarda a imagem do arquivo $origem como foto do perfil. O tipo é detectado pelo conteúdo, nunca pelo nome.
     * $deUpload: true quando $origem veio de um formulário (move_uploaded_file confere que é mesmo um upload);
     * false quando é um arquivo temporário criado aqui no servidor (a foto enviada pelo aplicativo em base64).
     * Devolve ['mensagem', 'foto' => 'uploads/perfil/...'] (caminho dentro de public/).
     */
    public function salvarFoto(int $id, string $origem, int $tamanho, bool $deUpload = true): array
    {
        if ($tamanho <= 0) {
            throw new CadastroException('Selecione uma imagem.');
        }
        if ($tamanho > self::FOTO_TAMANHO_MAX) {
            throw new CadastroException('A imagem deve ter no máximo 5 MB.');
        }

        $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($origem);
        if (!isset(self::FOTO_TIPOS[$tipo]) || @getimagesize($origem) === false) {
            throw new CadastroException('Formato inválido. Envie uma imagem JPG ou PNG.');
        }

        $pasta = __DIR__ . '/../../public/uploads/perfil/';
        if (!is_dir($pasta) && !mkdir($pasta, 0755, true) && !is_dir($pasta)) {
            throw new CadastroException('Não foi possível salvar a imagem.', 500);
        }

        $nomeArquivo = 'perfil_' . bin2hex(random_bytes(12)) . '.' . self::FOTO_TIPOS[$tipo];
        $movido = $deUpload ? move_uploaded_file($origem, $pasta . $nomeArquivo) : rename($origem, $pasta . $nomeArquivo);
        if (!$movido) {
            throw new CadastroException('Não foi possível salvar a imagem.', 500);
        }
        @chmod($pasta . $nomeArquivo, 0644);

        $caminho = 'uploads/perfil/' . $nomeArquivo;
        $fotoAntiga = ($this->usuario->buscarPerfilCompleto($id) ?: [])['foto'] ?? null;

        try {
            $this->usuario->atualizarFoto($id, $caminho);
        } catch (PDOException $e) {
            @unlink($pasta . $nomeArquivo);
            self::falha($e, 'atualizar foto');
        }

        // Remove a foto anterior do disco (só se estiver dentro da pasta de perfis).
        if ($fotoAntiga && str_starts_with($fotoAntiga, 'uploads/perfil/') && basename($fotoAntiga) !== $nomeArquivo) {
            @unlink($pasta . basename($fotoAntiga));
        }

        return ['mensagem' => 'Foto atualizada com sucesso!', 'foto' => $caminho];
    }

    /**
     * Foto enviada pelo aplicativo: o conteúdo da imagem em base64 (com ou sem o prefixo "data:image/...;base64,").
     * Grava num arquivo temporário e segue pelo mesmo caminho da foto do site.
     */
    public function salvarFotoBase64(int $id, mixed $base64): array
    {
        $texto = is_string($base64) ? preg_replace('#^data:[^,]*,#', '', trim($base64)) : '';
        // base64 ocupa 4/3 do arquivo: corta antes de decodificar algo muito maior que o limite.
        if ($texto === '' || strlen($texto) > (int) ceil(self::FOTO_TAMANHO_MAX * 4 / 3) + 4) {
            throw new CadastroException($texto === '' ? 'Selecione uma imagem.' : 'A imagem deve ter no máximo 5 MB.');
        }
        $bytes = base64_decode($texto, true);
        if ($bytes === false || $bytes === '') {
            throw new CadastroException('Formato inválido. Envie uma imagem JPG ou PNG.');
        }

        $temporario = tempnam(sys_get_temp_dir(), 'sicapda_foto_');
        if ($temporario === false || file_put_contents($temporario, $bytes) === false) {
            throw new CadastroException('Não foi possível salvar a imagem.', 500);
        }

        try {
            return $this->salvarFoto($id, $temporario, strlen($bytes), false);
        } finally {
            if (is_file($temporario)) {
                @unlink($temporario);
            }
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Lança 403 se a senha informada não for a do usuário. */
    private function conferirSenha(int $id, string $senha): void
    {
        $hash = $this->usuario->buscarHashSenha($id);
        if (!$hash || !password_verify($senha, $hash)) {
            throw new CadastroException('Senha atual incorreta.', 403);
        }
    }

    /** Só aceita texto: um número ou lista no JSON vira '' (e cai na validação de campo vazio). */
    private static function texto(mixed $valor): string
    {
        return is_string($valor) ? $valor : (is_int($valor) || is_float($valor) ? (string) $valor : '');
    }

    private static function falha(PDOException $e, string $acao): never
    {
        error_log('SICAPDA: falha ao ' . $acao . ': ' . $e->getMessage());
        throw new CadastroException('Não foi possível salvar. Tente novamente.', 500);
    }
}
