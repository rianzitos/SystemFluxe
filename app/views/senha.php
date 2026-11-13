<?php
/**
 * Recuperação de senha (SenhaController). Mesmo visual do login.
 * $modo: esqueci (pede o e-mail) | enviado (link enviado) | redefinir (senha nova) | invalido (link vencido/usado)
 * Variáveis: $erro, $email, $token, $mensagem
 */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$titulos = [
    'esqueci'   => ['Esqueceu a senha?', 'Informe o e-mail da sua conta e enviaremos um link para criar uma senha nova.'],
    'enviado'   => ['Confira o seu e-mail', ''],
    'redefinir' => ['Crie uma senha nova', 'Conta: ' . $email],
    'invalido'  => ['Link inválido ou vencido', 'Os links de redefinição valem por ' . RedefinicaoSenha::MINUTOS_VALIDADE . ' minutos e só podem ser usados uma vez.'],
];
[$titulo, $subtitulo] = $titulos[$modo] ?? $titulos['esqueci'];
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?= $e($titulo) ?> — SICAPDA</title>
    <link rel="stylesheet" href="/css/styleLogin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"
        media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"></noscript>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="shortcut icon" href="/img/logo_fluxe.png" type="image/png">
</head>

<body class="fundo">

    <header class="headerLogin">
        <img id="logotipo" src="/img/logotipo.svg" alt="Logotipo da empresa">
        <a href="/login">
            <button class="butHeader" type="button">
                <i class="bi bi-arrow-left"></i>
                <span class="butHeader-texto">Voltar para o login</span>
            </button>
        </a>
    </header>

    <div class="circulo-amarelo"></div>
    <div class="circuit-line"><img src="/img/linhaCircuito.png" alt=""></div>
    <div class="circuit-decoration"><img src="/img/circuito.png" alt=""></div>

    <div class="layout-principal">

        <aside class="container-titulo">
            <div class="container-miniTitu">SISTEMA DE ACESSO EMPRESARIAL</div>
            <h1>SICA<span>PDA</span></h1>
            <div class="descricao">
                <h3>Sistema Inteligente de Controle de Acesso e <span>P</span>revisão de <span>D</span>emanda
                    <span>A</span>limentar</h3>
            </div>
            <div class="containerTraco"><div class="traco"></div></div>
            <div class="badge-seguranca">
                <i class="bi bi-shield-check"></i>
                <p>O link de redefinição é de uso único e vale por <?= RedefinicaoSenha::MINUTOS_VALIDADE ?> minutos.</p>
            </div>
        </aside>

        <section class="fundoContainer">
            <main class="form-container">
                <div class="form-header">
                    <h1><?= $e($titulo) ?></h1>
                    <?php if ($subtitulo !== ''): ?><p><?= $e($subtitulo) ?></p><?php endif; ?>
                </div>

                <?php if (!empty($erro)): ?>
                    <div class="alerta alerta-erro" role="alert"><?= $e($erro) ?></div>
                <?php endif; ?>

                <?php if ($modo === 'esqueci'): ?>
                    <form class="form" action="/senha/esqueci" method="POST">
                        <?= CsrfMiddleware::campo() ?>
                        <div class="inputs">
                            <label for="email">E-mail</label>
                            <div class="input-wrapper">
                                <i class="bi bi-envelope input-icon"></i>
                                <input type="email" id="email" name="email" required autocomplete="email" autofocus
                                    placeholder="seuemail@empresa.com" maxlength="150" value="<?= $e($email) ?>">
                            </div>
                        </div>
                        <div class="container-but">
                            <button class="butEnviar" type="submit">ENVIAR LINK <i class="bi bi-arrow-right-short"></i></button>
                        </div>
                    </form>

                <?php elseif ($modo === 'enviado'): ?>
                    <div class="alerta alerta-sucesso" role="status"><?= $e($mensagem) ?></div>
                    <p class="texto-senha">Não chegou em alguns minutos? Confira o endereço digitado
                        (<strong><?= $e($email) ?></strong>) e a caixa de spam, ou
                        <a href="/senha/esqueci">peça outro link</a>.</p>

                <?php elseif ($modo === 'redefinir'): ?>
                    <form class="form" action="/senha/redefinir" method="POST">
                        <?= CsrfMiddleware::campo() ?>
                        <input type="hidden" name="token" value="<?= $e($token) ?>">
                        <!-- Para o gerenciador de senhas do navegador salvar a senha nova na conta certa -->
                        <input type="email" name="usuario" value="<?= $e($email) ?>" autocomplete="username" hidden>
                        <div class="inputs">
                            <label for="senha">Nova senha</label>
                            <div class="input-wrapper">
                                <i class="bi bi-lock input-icon"></i>
                                <input type="password" id="senha" name="senha" required minlength="8" maxlength="72"
                                    autocomplete="new-password" placeholder="Pelo menos 8 caracteres" autofocus>
                                <button type="button" class="toggle-senha" id="toggleSenha" aria-label="Mostrar senha">
                                    <i class="bi bi-eye" id="iconeSenha"></i>
                                </button>
                            </div>
                            <label for="confirmar">Confirme a nova senha</label>
                            <div class="input-wrapper">
                                <i class="bi bi-lock input-icon"></i>
                                <input type="password" id="confirmar" name="confirmar" required minlength="8" maxlength="72"
                                    autocomplete="new-password" placeholder="Repita a senha">
                            </div>
                        </div>
                        <div class="container-but">
                            <button class="butEnviar" type="submit">SALVAR SENHA NOVA <i class="bi bi-arrow-right-short"></i></button>
                        </div>
                    </form>

                <?php else: ?>
                    <div class="container-but">
                        <a class="butEnviar butLink" href="/senha/esqueci">PEDIR UM LINK NOVO <i class="bi bi-arrow-right-short"></i></a>
                    </div>
                <?php endif; ?>

                <div class="footerForm">
                    <div class="footerItem">
                        <p>Lembrou a senha?</p>
                        <a href="/login">Entrar</a>
                    </div>
                </div>

                <div class="containerCript">
                    <p><i class="cadeado bi bi-lock-fill"></i> Ambiente protegido e criptografado</p>
                </div>
            </main>
        </section>
    </div>

    <footer class="footerLogin">
        <h4>© 2026 <span>FLUXE</span> Soluções Inteligentes. Todos os direitos reservados.</h4>
    </footer>

    <div class="circle-decoration"><img src="/img/circuloAmarelo.png" alt=""></div>
    <div class="dots-decoration"></div>
    <div class="dots-decoration-amarelo"></div>

    <script src="/js/scriptLogin.js"></script>
</body>

</html>
