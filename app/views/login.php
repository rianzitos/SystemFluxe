<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Controle de Acesso</title>
    <link rel="stylesheet" href="../../public/css/styleLogin.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="shortcut icon" href="../../public/img/logo_fluxe.png" type="image/png">
</head>
<!-- liga com outras paginas e mostra nosso icone -->

<body class="fundo">

    <div id="loading-screen">
        <div class="logo"><img class="logoImg" src="../../public/img/AnimacaoFluxe2.gif" alt="Animação da Logo"></div>
        <!-- alt="Animação da Logo": Texto de acessibilidade que descreve a imagem para leitores de tela ou substitui o elemento caso o arquivo não carregue. -->
        <p class="loading-text">CARREGANDO...</p>
        <!-- mensagem pro usuario que vai entrar ao site -->
    </div>

    <header class="headerLogin">
        <img id="logotipo" src="../../public/img/logotipo.png" alt="Logotipo da empresa">

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
                SISTEMA DE ACESSO EMPRESARIAL
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
                <i class="bi bi-shield-check"></i>
                <!-- olhinho para senha -->
                <p>Segurança, inteligência e eficiência<br>para o seu negócio.</p>
            </div>
        </aside>

        <section class="fundoContainer">
            <main class="form-container">
                <!-- <section class="fundoContainer">: 
    Abre a seção de plano de fundo que envelopa e posiciona 
    o conteúdo na tela inteira.<main class="form-container">: Abre 
        a área principal estruturada como a caixa ou card onde o formulário
        será montado. -->
                <div class="form-header">
                    <h1>Bem-vindo de volta!</h1>
                    <p>Acesse sua conta para continuar</p>
                </div>

                <?php if (!empty($_SESSION['flash_erro'])): ?>
                    <div class="alerta alerta-erro">
                        <?= htmlspecialchars($_SESSION['flash_erro']) ?>
                    </div>
                    <!-- ?php empty: Verifica se existe alguma mensagem de erro guardada 
na sessão; se houver, o código HTML abaixo é ativado.?= htmlspecialchars(...)
 ?>: Exibe o texto do erro com segurança, impedindo que códigos maliciosos injetados 
 por usuários quebrem o layout da página.-->


                    <?php unset($_SESSION['flash_erro']); ?>
                <?php endif; ?>

                <?php if (!empty($_SESSION['flash_sucesso'])): ?>
                    <div class="alerta alerta-sucesso">
                        <?= htmlspecialchars($_SESSION['flash_sucesso']) ?>
                    </div>
                    <!-- unset($_SESSION['flash_erro']);: Apaga a mensagem de erro da memória do servidor 
para que ela não reapareça quando a página for atualizada.if (!empty(...'flash_sucesso'])):
     Verifica se há um aviso de sucesso na sessão e renderiza a caixa verde (alerta-sucesso)
      com o texto protegido por htmlspecialchars. -->


                    <?php unset($_SESSION['flash_sucesso']); ?>
                <?php endif; ?>

                <form class="form" action="/login" method="POST">
                    <div class="inputs">
                        <!-- unset e endif: Apaga a mensagem de sucesso da memória e fecha a
  condição aberta anteriormente.<form>: Abre o formulário enviando os dados em
     segundo plano (POST) para a rota /login. -->
                        <label for="email">E-mail</label>
                        <div class="input-wrapper">
                            <i class="bi bi-envelope input-icon"></i>
                            <input type="email" id="email" name="email" required autocomplete="email"
                                placeholder="seuemail@empresa.com"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <!-- como deve ser digitado -->
                        <label for="senha">Senha</label>
                        <div class="input-wrapper">
                            <i class="bi bi-lock input-icon"></i>
                            <input type="password" id="senha" name="senha" required placeholder="Sua senha"
                                autocomplete="current-password">
                            <button type="button" class="toggle-senha" id="toggleSenha"
                                aria-label="Mostrar senha"><!-- Botão para mostrar ou ocultar senha -->
                                <i class="bi bi-eye" id="iconeSenha"></i><!-- Ícone do olho -->
                            </button>
                        </div>
                        <!-- tipo da senha e campo -->
                    </div>

                    <!-- LEMBRAR-ME + ESQUECEU A SENHA  -->
                    <div class="row-lembrar">
                        <label class="lembrar-label"> <!-- Checkbox lembrar usuário -->
                            <input type="checkbox" name="lembrar" id="lembrar">
                            <span class="checkmark"></span>
                            Lembrar-me
                        </label>
                        <a href="#" class="link-esqueceu">Esqueceu a senha?</a><!-- Link para recuperação de senha -->
                    </div>

                    <div class="container-but">
                        <button class="butEnviar" type="submit">ENTRAR NO SISTEMA <i
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
                        <!-- Cria o container do rodapé do formulário para alinhar os elementos finais da caixa. -->
                        <p>Ainda não possui uma conta?</p>
                        <a href="/cadastro">Criar conta</a>
                    </div>
                </div>

                <div class="containerCript"> <!-- Área informando segurança -->
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