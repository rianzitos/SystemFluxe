<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Painel</title>
    <link rel="stylesheet" href="/css/styleLoginCadastro.css">
</head>
<body class="fundoPainel"><!-- Corpo da página com uma classe para estilização do fundo -->
    <h1>Bem-vindo, <?= htmlspecialchars($_SESSION['usuario_nome']) ?>!</h1> <!-- Exibe o nome do usuário logado com segurança -->
    <p>Perfil: <strong><?= htmlspecialchars($_SESSION['usuario_perfil']) ?></strong></p>
    <a href="/logout">Sair</a><!-- Link responsável por realizar o logout do sistema -->
</body>
</html>