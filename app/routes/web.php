<?php

require_once __DIR__ . '/../../config/app.php';

$uri    = strtok($_SERVER['REQUEST_URI'], '?'); // remove query string
$metodo = $_SERVER['REQUEST_METHOD'];
//require_once: Carrega o arquivo de configuração global da aplicação apenas uma
//vez.strtok: Limpa a URL do navegador removendo os parâmetros após a interrogação (?).$_SERVER['REQUEST_METHOD']:
// Descobre se o usuário está visualizando a página (GET) ou enviando dados (POST).

// ─── Arquivos estáticos (só necessário ao rodar com o servidor embutido do PHP) ───
// PARA RODAR O PROJETO, EXECUTE O COMANDO ABAIXO DENTRO DA PASTA DO PROJETO:

// php.exe -S localhost:8000 -t public app/routes/web.php

// por este roteador, inclusive CSS, JS e imagens. O "DocumentRoot" real em
// produção é a pasta public/, então é lá que verificamos se o caminho pedido
// existe de fato — se existir, devolvemos `false` para o servidor embutido
// servir o arquivo normalmente.
if (PHP_SAPI === 'cli-server') {
    $caminhoReal = __DIR__ . '/../../public/' . ltrim($uri, '/');
    if ($uri !== '/' && is_file($caminhoReal)) {
        return false;
    }
}

// ─── Rotas publicas ───────────────────────────────────────────────────────────

if ($uri === '/') {
    require_once __DIR__ . '/../../index.html';
// raiz do domínio (fluxeteam.com.br) exibe a landing institucional da Fluxe.

} elseif ($uri === '/sicapda') {
    require_once __DIR__ . '/../views/indexSys.html';
// /sicapda exibe a landing de apresentação do sistema SICAPDA.

} elseif ($uri === '/login') {
    if ($metodo === 'POST') {
        (new AcessoController())->processarLogin();
    } else {
        (new AcessoController())->exibirLogin();
    }
// Enviar o formulário (POST) aciona a função para validar e processar o
// login. Apenas acessar a página (GET ou outro método) aciona a função
// para exibir a tela de login.

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

} elseif ($uri === '/acessos') {
    AuthMiddleware::autenticado();
    require_once __DIR__ . '/../views/acessos.php';
//cria a rota de acessos, seguindo o mesmo padrão do painel: bloqueia
// invasores através do AuthMiddleware e só exibe a tela protegida
// (acessos.php) se o usuário estiver logado.

} elseif ($uri === '/pessoas') {
    AuthMiddleware::autenticado();
    require_once __DIR__ . '/../views/pessoas.php';
//cria a rota de pessoas, seguindo o mesmo padrão das anteriores: bloqueia
// invasores através do AuthMiddleware e só exibe a tela protegida
// (pessoas.php) se o usuário estiver logado.

// ─── 404 ─────────────────────────────────────────────────────────────────────

} else {
    http_response_code(404);
    echo '<h1>404 — Página não encontrada</h1>';
}
//  define a página de erro 404, enviando um código de erro oficial ao
//  navegador e exibindo um aviso de "Página não encontrada" caso o usuário
// digite uma URL inválida.