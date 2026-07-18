<?php

require_once __DIR__ . '/../../config/app.php';

$uri    = strtok($_SERVER['REQUEST_URI'], '?'); // remove query string
$metodo = $_SERVER['REQUEST_METHOD'];
//require_once: Carrega o arquivo de configuração global da aplicação apenas uma
//vez.strtok: Limpa a URL do navegador removendo os parâmetros após a interrogação (?).$_SERVER['REQUEST_METHOD']:
// Descobre se o usuário está visualizando a página (GET) ou enviando dados (POST).

// ─── Arquivos estáticos (só necessário ao rodar com o servidor embutido do PHP) ───
// Quando você roda `php -S localhost:8000 app/routes/web.php`, TODA requisição passa
// por este arquivo, inclusive CSS, JS e imagens. Se o caminho pedido existir de fato
// em disco, devolvemos `false` para o servidor embutido servir o arquivo normalmente.
if (PHP_SAPI === 'cli-server') {
    $caminhoReal = __DIR__ . '/../../' . ltrim($uri, '/');
    if ($uri !== '/' && is_file($caminhoReal)) {
        return false;
    }
}

// ─── Rotas publicas ───────────────────────────────────────────────────────────

if ($uri === '/' || $uri === '/login') {
    if ($metodo === 'POST') {
        (new AcessoController())->processarLogin();
    } else {
        (new AcessoController())->exibirLogin();
    }
//l (/) ou o /login, o sistema verifica o método.Enviar o formulário (POST) aciona
// a função para validar e processar o login.Apenas acessar a página (GET ou outro método) aciona a função para exibir a tela de login.

} elseif ($uri === '/cadastro') {
    if ($metodo === 'POST') {
        (new AcessoController())->processarCadastro();
    } else {
        (new AcessoController())->exibirCadastro();
    }
//  rota de cadastro, direcionando o usuário com base na ação
//  realizada na URL /cadastro.Se o formulário for enviado (POST), aciona
//  a função para validar e salvar o novo usuário.Se a página for apenas acessada,
// aciona a função para exibir a tela de cadastro.


} elseif ($uri === '/logout') {
    (new AcessoController())->logout();
//  cria a rota de logout, acionando imediatamente a função que
// limpa a sessão e desloga o usuário sempre que a URL /logout for acessada.

// ─── Rotas protegidas ────────────────────────────────────────────────────────

} elseif ($uri === '/painel') {
    AuthMiddleware::autenticado();
    require_once __DIR__ . '/../views/painel.php';
//cria a rota do painel, que bloqueia invasores através do AuthMiddleware
// e só exibe a tela protegida (painel.php) se o usuário estiver logado.

// ─── 404 ─────────────────────────────────────────────────────────────────────

} else {
    http_response_code(404);
    echo '<h1>404 — Página não encontrada</h1>';
}
//  define a página de erro 404, enviando um código de erro oficial ao
//  navegador e exibindo um aviso de "Página não encontrada" caso o usuário
// digite uma URL inválida.