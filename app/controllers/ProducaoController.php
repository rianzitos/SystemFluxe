<?php

/**
 * Tela Produção do site (/producao): registrar quantos quilos foram produzidos e desperdiçados em cada
 * refeição do dia. Qualquer usuário da empresa registra (é o dia a dia da cozinha).
 *
 * Rotas: GET /producao (a tela), GET /producao/dados?data=Y-m-d (JSON do dia) e POST /producao/salvar
 * (JSON {data, itens: [{tipo, produzido, desperdicado?}]}, com o token CSRF no cabeçalho X-CSRF-Token).
 * As regras ficam em RegistroProducao, que o aplicativo também usa (GET/POST /api/producao).
 */
class ProducaoController
{
    use RespondeJson;

    public function exibir(): void
    {
        AuthMiddleware::autenticado();
        $empresaId = Pagina::empresaId();
        $notificacoesNaoLidas = Notificacoes::contar((new Painel($empresaId))->totalAlertas(), $empresaId);
        // ?data=Y-m-d abre direto naquele dia (ex.: vindo de um alerta); data inválida ou futura volta para hoje.
        try {
            $diaInicial = (new RegistroProducao())->dia($empresaId, (string) ($_GET['data'] ?? date('Y-m-d')));
        } catch (CadastroException) {
            $diaInicial = (new RegistroProducao())->dia($empresaId, date('Y-m-d'));
        }
        require __DIR__ . '/../views/producao.php';
    }

    public function dados(): void
    {
        $this->exigirSessaoGet();
        $this->executar(fn() => (new RegistroProducao())->dia(Pagina::empresaId(), (string) ($_GET['data'] ?? date('Y-m-d'))));
    }

    public function salvar(): void
    {
        $this->exigirSessao();
        $corpo = json_decode((string) file_get_contents('php://input'), true);
        $this->executar(fn() => (new RegistroProducao())->salvar(Pagina::empresaId(), is_array($corpo) ? $corpo : []), 'Produção salva.');
    }

    private function executar(callable $acao, string $mensagem = ''): never
    {
        try {
            $resultado = $acao();
        } catch (CadastroException $e) {
            $this->responder(false, $e->getMessage(), $e->status());
        }
        $this->responder(true, $mensagem, 200, ['dia' => $resultado]);
    }
}
