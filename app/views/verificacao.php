<?php
/**
 * Segunda etapa do login (AcessoController::telaVerificacao). Mesmo visual do login.
 * Variáveis: $metodo (app|email), $destino (e-mail mascarado), $erro, $aviso, $reenviadoEm (timestamp do último reenvio)
 */
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$porEmail = $metodo === 'email';
$subtitulo = $porEmail
    ? 'Enviamos um código de 6 dígitos para ' . $destino . '. Ele vale por ' . DoisFatores::MINUTOS_VALIDADE . ' minutos.'
    : 'Abra o aplicativo autenticador do seu celular (Google Authenticator, Microsoft Authenticator...) e digite o código de 6 dígitos do SICAPDA.';
// Reenviar: libera 60 s depois do último envio (o servidor confere de novo).
$esperaReenvio = $porEmail && $reenviadoEm > 0 ? max(0, $reenviadoEm + 60 - time()) : 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Verificação em duas etapas — SICAPDA</title>
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
                <p>Mesmo que alguém descubra a sua senha, sem este código ninguém entra na sua conta.</p>
            </div>
        </aside>

        <section class="fundoContainer">
            <main class="form-container">
                <div class="form-header">
                    <div class="verificacao-icone" aria-hidden="true">
                        <i class="bi <?= $porEmail ? 'bi-envelope-check' : 'bi-phone' ?>"></i>
                    </div>
                    <h1>Verificação em duas etapas</h1>
                    <p class="verificacao-sub"><?= $e($subtitulo) ?></p>
                </div>

                <?php if (!empty($erro)): ?>
                    <div class="alerta alerta-erro" role="alert"><?= $e($erro) ?></div>
                <?php elseif (!empty($aviso)): ?>
                    <div class="alerta alerta-sucesso" role="status"><?= $e($aviso) ?></div>
                <?php endif; ?>

                <form class="form" action="/login/verificacao" method="POST" id="formCodigo">
                    <?= CsrfMiddleware::campo() ?>
                    <div class="inputs">
                        <label for="codigo">Código de 6 dígitos</label>
                        <div class="input-wrapper">
                            <input type="text" id="codigo" name="codigo" class="input-codigo" required autofocus
                                inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7"
                                placeholder="000000" aria-describedby="dicaCodigo">
                        </div>
                        <p class="verificacao-dica" id="dicaCodigo">
                            <?= $porEmail ? 'Não achou? Confira a caixa de spam.' : 'O código muda a cada 30 segundos: digite o que está aparecendo agora.' ?>
                        </p>
                    </div>
                    <div class="container-but">
                        <button class="butEnviar" type="submit">VERIFICAR E ENTRAR <i class="bi bi-arrow-right-short"></i></button>
                    </div>
                </form>

                <?php if ($porEmail): ?>
                    <form action="/login/verificacao/reenviar" method="POST" class="verificacao-reenviar">
                        <?= CsrfMiddleware::campo() ?>
                        <span>Não recebeu?</span>
                        <button type="submit" class="link-botao" id="btnReenviar" data-espera="<?= (int) $esperaReenvio ?>"
                            <?= $esperaReenvio > 0 ? 'disabled' : '' ?>>
                            Reenviar código<?= $esperaReenvio > 0 ? ' em ' . (int) $esperaReenvio . ' s' : '' ?>
                        </button>
                    </form>
                <?php endif; ?>

                <details class="verificacao-recuperacao" <?= !empty($erro) && !empty($_POST['recuperacao']) ? 'open' : '' ?>>
                    <summary>
                        <i class="bi bi-life-preserver"></i>
                        <?= $porEmail ? 'Sem acesso ao e-mail?' : 'Sem acesso ao celular?' ?> Use um código de recuperação
                    </summary>
                    <form class="form" action="/login/verificacao" method="POST">
                        <?= CsrfMiddleware::campo() ?>
                        <input type="hidden" name="recuperacao" value="1">
                        <p class="texto-senha">Digite um dos 10 códigos de recuperação que você guardou ao ativar a verificação
                            (formato <strong>ABCDE-FGHIJ</strong>). Cada código só vale uma vez.</p>
                        <div class="inputs">
                            <label for="codigoRecuperacao">Código de recuperação</label>
                            <div class="input-wrapper">
                                <i class="bi bi-key input-icon"></i>
                                <input type="text" id="codigoRecuperacao" name="codigo" required autocomplete="off"
                                    autocapitalize="characters" spellcheck="false" maxlength="14" placeholder="ABCDE-FGHIJ">
                            </div>
                        </div>
                        <div class="container-but">
                            <button class="butEnviar butSecundario" type="submit">ENTRAR COM CÓDIGO DE RECUPERAÇÃO</button>
                        </div>
                    </form>
                </details>

                <div class="footerForm">
                    <div class="footerItem">
                        <p>Não é você?</p>
                        <a href="/login">Voltar para o login</a>
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

    <script src="/js/verificacao.js"></script>
</body>

</html>
