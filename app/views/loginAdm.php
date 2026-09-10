<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Administrador — Controle de Acesso</title>
    <link rel="stylesheet" href="../../public/css/styleLogin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="shortcut icon" href="../../public/img/logo_fluxe.png" type="image/png">

    <!-- Estilo exclusivo desta página: apenas o aviso informativo do card.
         Não altera nada do styleLogin.css, para não afetar a tela de login. -->
    <style>
        .alerta-info {
            margin: 0 10% 1rem 10%;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.85rem;
            background-color: #fff8e1;
            color: #6b5a12;
            border: 1px solid #fbe7a1;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .alerta-info i {
            color: #b8860b;
            margin-top: 2px;
        }
    </style>

    <!-- estilo principal da página -->
</head>

<body class="fundoAdm">

    <header class="headerLogin">
        <img id="logotipo" src="../../public/img/logotipo.svg" alt="Logotipo da empresa">

        <a href="/">
            <button class="butHeader">
                <i class="bi bi-person-circle"></i>
                <span class="butHeader-texto">Saiba mais sobre a empresa</span>
            </button>
        </a>
    </header>

    <div class="circulo-amarelo"></div>

    <!-- LINHA DE CIRCUITO -->
    <div class="circuit-line">
        <img src="../../public/img/linhaCircuito.png" alt="Linha de circuito amarelo">
    </div>

    <!-- IMAGEM DO CIRCUITO -->
    <div class="circuit-decoration">
        <img src="../../public/img/circuito.png" alt="">
    </div>

    <div class="layout-principal">

        <aside class="container-titulo">
            <div class="container-miniTitu">
                ACESSO RESTRITO A ADMINISTRADORES
            </div>
            <h1>SICA<span>PDA</span></h1>
            <div class="descricao">
                <h3>Sistema Inteligente de Controle de Acesso e <span>P</span>revisão de <span>D</span>emanda
                    <span>A</span>limentar
                </h3>
            </div>
            <div class="containerTraco">
                <div class="traco"></div>
            </div>
            <!-- BADGE DE SEGURANÇA -->
            <div class="badge-seguranca">
                <i class="bi bi-shield-lock"></i>
                <p>Apenas administradores autorizados podem criar novas contas no sistema.</p>
            </div>
        </aside>

        <section class="fundoContainer">
            <main class="form-container">
                <div class="form-header">
                    <h1>Verificação de Administrador</h1>
                    <p>Confirme suas credenciais para continuar</p>
                </div>

                <?php if (!empty($_SESSION['flash_erro'])): ?>
                    <div class="alerta alerta-erro">
                        <?= htmlspecialchars($_SESSION['flash_erro']) ?>
                    </div>
                    <?php unset($_SESSION['flash_erro']); ?>
                <?php endif; ?>

                <?php if (!empty($_SESSION['flash_sucesso'])): ?>
                    <div class="alerta alerta-sucesso">
                        <?= htmlspecialchars($_SESSION['flash_sucesso']) ?>
                    </div>
                    <?php unset($_SESSION['flash_sucesso']); ?>
                <?php endif; ?>

                <div class="alerta-info">
                    <i class="bi bi-info-circle"></i>
                    <span>Esta etapa existe porque somente administradores podem cadastrar novos usuários no
                        SICAPDA.</span>
                </div>

                <form class="form" action="/admin/verificar" method="POST">
                    <div class="inputs">
                        <label for="email">E-mail de administrador</label>
                        <div class="input-wrapper">
                            <i class="bi bi-envelope input-icon"></i>
                            <input type="email" id="email" name="email" required autocomplete="email"
                                placeholder="admin@empresa.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>

                        <label for="senha">Senha de administrador</label>
                        <div class="input-wrapper">
                            <i class="bi bi-lock input-icon"></i>
                            <input type="password" id="senha" name="senha" required placeholder="Sua senha"
                                autocomplete="current-password">
                            <button type="button" class="toggle-senha" id="toggleSenha" aria-label="Mostrar senha">
                                <i class="bi bi-eye" id="iconeSenha"></i>
                            </button>
                        </div>
                    </div>

                    <!-- LEMBRAR-ME + ESQUECEU A SENHA -->
                    <div class="row-lembrar">
                        <label class="lembrar-label">
                            <input type="checkbox" name="lembrar" id="lembrar">
                            <span class="checkmark"></span>
                            Lembrar-me
                        </label>
                        <a href="#" class="link-esqueceu">Esqueceu a senha?</a>
                    </div>

                    <div class="container-but">
                        <button class="butEnviar" type="submit">VERIFICAR E CONTINUAR <i
                                class="bi bi-arrow-right-short"></i></button>
                    </div>
                </form>

                <!-- Divisor visual -->
                <div class="divisor-ou">
                    <span></span>
                    <p>ou</p>
                    <span></span>
                </div>

                <!-- Footer do formulário -->
                <div class="footerForm">
                    <div class="footerItem">
                        <p>Não é administrador?</p>
                        <a href="/login">Voltar para o login</a>
                    </div>
                </div>

                <div class="containerCript">
                    <p><i class="cadeado bi bi-lock-fill"></i> Ambiente protegido e criptografado</p>
                </div>

            </main>
        </section>

    </div>

    <!-- Footer da página -->
    <footer class="footerLogin">
        <h4>© 2026 <span>FLUXE</span> Soluções Inteligentes. Todos os direitos reservados.</h4>
    </footer>

    <!-- CÍRCULO DECORATIVO (inferior direito) -->
    <div class="circle-decoration">
        <img src="../../public/img/circuloAmarelo.png" alt="">
    </div>

    <!-- PADRÃO DE PONTOS -->
    <div class="dots-decoration"></div>

    <!-- PADRÃO DE PONTOS AMARELO -->
    <div class="dots-decoration-amarelo"></div>

    <script src="../../public/js/scriptLogin.js"></script>
</body>

</html>