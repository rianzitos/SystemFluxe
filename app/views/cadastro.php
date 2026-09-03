<?php
// Bootstrap próprio: esse arquivo pode ser acessado direto pelo navegador
// (ex: http://localhost/SystemFluxe/app/views/cadastro.php), sem passar
// pelo roteador em app/routes/web.php. Por isso ele carrega a aplicação
// sozinho aqui.
require_once __DIR__ . '/../../config/app.php';


// Se o wizard enviou o formulário (POST), processa e responde em JSON.
// Não renderiza HTML nesse caso — quem trata a resposta é o cadastro.js.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  (new AcessoController())->processarCadastro();
  exit;
}

// Mensagem de erro vinda de um fluxo antigo com redirect (fallback, normalmente não usado mais).
$erroFlash = $_SESSION['flash_erro'] ?? null;
unset($_SESSION['flash_erro']);
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cadastro — Controle de Acesso</title>
  <link rel="stylesheet" href="../../public/css/styleCadastro.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
  <link rel="shortcut icon" href="../../public/img/logo_fluxe.png" type="image/png">
</head>
<!-- logo fluxe, link de cadastro, icone -->
<body>

  <div class="page">
    <!-- ===================== LEFT SIDE ===================== -->
    <div class="side">
      <div class="deco-circle-bl"></div>
      <div class="cubos"><img src="../../public/img/cubos_cadastro.png" alt="Cubos amarelos transparentes"></div>
      <div class="side-content">
        <div class="logo">
          <img src="../../public/img/logotipo.svg" alt="Logo da Empresa">
        </div>
<!-- barra lateral de cadastro -->

        <h1 class="titulo">SICA<span class="accent">PDA</span></h1>
        <p class="subtitle">Sistema Inteligente de Controle de Acesso e Previsão de Demanda Alimentar</p>
        <p class="desc">Solução completa para gerenciamento de acesso de colaboradores, visitantes, estudantes e
          previsão
          inteligente da demanda alimentar empresarial.</p>
        <div class="divider"></div>

        <div class="features">
          <div class="feature">
            <div class="icon"><i class="bi bi-shield-check icone"></i></div>
            <div class="body"><strong>Segurança</strong><span>Controle de acesso com autenticação segura.</span></div>
          </div>
          <div class="feature">
            <div class="icon"><i class="bi bi-bar-chart-line icone"></i></div>
            <div class="body inteligencia"><strong>Inteligência</strong><span>Previsão automática da demanda alimentar
                utilizando
                dados históricos.</span></div>
          </div>
          <div class="feature">
            <div class="icon"><i class="bi bi-lightning-charge icone"></i></div>
            <div class="body"><strong>Eficiência</strong><span>Redução de desperdícios e otimização operacional.</span>
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- ===================== RIGHT SIDE / WIZARD ===================== -->
    <div class="right">

      <div class="deco-dots"></div>
      <div class="deco-ring"></div>

      <div class="wizard-wrap">
        <div class="card" id="card">
          <div class="autosave-pill" id="autosavePill"><span class="dot"></span>Progresso salvo</div>

          <div class="card-header" id="mainHeader">
            <h2>Criar nova conta</h2>
            <p>Cadastre sua empresa e seu usuário administrador para começar a utilizar o SICAPDA.</p>
          </div>

          <div class="form-erro" id="formErro"
            style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px;<?php echo $erroFlash ? '' : 'display:none;'; ?>">
            <?php echo htmlspecialchars($erroFlash ?? '', ENT_QUOTES, 'UTF-8'); ?>
          </div>

          <div class="progress-bar">
            <div class="fill" id="progressFill" style="width:16.6%"></div>
          </div>

          <div class="stepper" id="stepper">
            <div class="step-item active" data-step="1">
              <div class="line"></div>
              <div class="circle">1</div>
              <div class="label">Administrador</div>
            </div>
            <div class="step-item" data-step="2">
              <div class="line"></div>
              <div class="circle">2</div>
              <div class="label">Segurança</div>
            </div>
            <div class="step-item" data-step="3">
              <div class="line"></div>
              <div class="circle">3</div>
              <div class="label">Empresa</div>
            </div>
            <div class="step-item" data-step="4">
              <div class="line"></div>
              <div class="circle">4</div>
              <div class="label">Configuração</div>
            </div>
            <div class="step-item" data-step="5">
              <div class="line"></div>
              <div class="circle">5</div>
              <div class="label">Revisão</div>
            </div>
            <div class="step-item" data-step="6">
              <div class="line"></div>
              <div class="circle">6</div>
              <div class="label">Conclusão</div>
            </div>
          </div>

          <form id="wizardForm" method="POST" enctype="multipart/form-data" novalidate>
            <!-- STEP 1 -->
            <div class="step-panel active" data-panel="1">
              <h3>Dados do Administrador</h3>
              <p class="step-desc">Cadastre o primeiro usuário administrador da empresa.</p>

              <div class="field">
                <label for="admNome">Nome completo</label>
                <div class="input-wrap">
                  <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="8" r="4" />
                    <path d="M4 21c0-4 4-6 8-6s8 2 8 6" />
                  </svg>
                  <input type="text" id="admNome" name="admNome" placeholder="Digite seu nome completo" required>
                </div>
                <div class="error-msg">Informe seu nome completo.</div>
              </div>

              <div class="grid2">
                <div class="field">
                  <label for="admCpf">CPF</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <rect x="3" y="5" width="18" height="14" rx="2" />
                      <path d="M7 9h4M7 13h7" />
                    </svg>
                    <input type="text" id="admCpf" name="admCpf" placeholder="000.000.000-00" maxlength="14" required>
                  </div>
                  <div class="error-msg">CPF inválido.</div>
                </div>
                <div class="field">
                  <label for="admTelefone">Telefone</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path
                        d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.9.6 2.7a2 2 0 0 1-.4 2.1L8.1 9.7a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.4c.9.3 1.8.5 2.7.6a2 2 0 0 1 1.9 2.2z" />
                    </svg>
                    <input type="text" id="admTelefone" name="admTelefone" placeholder="(00) 00000-0000" maxlength="15"
                      required>
                  </div>
                  <div class="error-msg">Telefone inválido.</div>
                </div>
              </div>

              <div class="field">
                <label for="admEmail">E-mail corporativo</label>
                <div class="input-wrap">
                  <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="m3 7 9 6 9-6" />
                  </svg>
                  <input type="email" id="admEmail" name="admEmail" placeholder="seuemail@empresa.com" required>
                </div>
                <div class="error-msg">Informe um e-mail corporativo válido.</div>
              </div>

              <div class="grid2">
                <div class="field">
                  <label for="admCargo">Cargo</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <rect x="3" y="7" width="18" height="13" rx="2" />
                      <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" />
                    </svg>
                    <input type="text" id="admCargo" name="admCargo" placeholder="Ex: Gerente de RH" required>
                  </div>
                  <div class="error-msg">Informe o cargo.</div>
                </div>
                <div class="field">
                  <label for="admMatricula">Matrícula <span class="opt">(opcional)</span></label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <rect x="4" y="4" width="16" height="16" rx="2" />
                      <path d="M8 9h8M8 13h5" />
                    </svg>
                    <input type="text" id="admMatricula" name="admMatricula" placeholder="000000">
                  </div>
                </div>
              </div>

              <div class="field">
                <label>Foto de perfil <span class="opt">(opcional)</span></label>
                <label class="upload-box" for="admFoto">
                  <div class="icon-circle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6b7280"
                      stroke-width="2">
                      <path d="M12 16V4M6 10l6-6 6 6" />
                      <path d="M4 20h16" />
                    </svg></div>
                  <div class="txt"><strong id="fotoLabel">Clique para enviar uma foto</strong><span>PNG ou JPG · até
                      5MB</span></div>
                  <input type="file" id="admFoto" name="admFoto" accept="image/png,image/jpeg" style="display:none">
                </label>
              </div>

              <div class="checkbox-row">
                <input type="checkbox" id="admAutoriza" required>
                <label for="admAutoriza">Declaro que possuo autorização para cadastrar esta empresa.</label>
              </div>
              <div class="error-msg" id="errAutoriza">É necessário confirmar a autorização para continuar.</div>
            </div>

            <!-- STEP 2 -->
            <div class="step-panel" data-panel="2">
              <h3>Segurança da conta</h3>
              <p class="step-desc">Crie uma senha segura para proteger seus dados e o acesso ao sistema.</p>

              <div class="field">
                <label for="senha">Senha</label>
                <div class="input-wrap">
                  <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="4" y="10" width="16" height="10" rx="2" />
                    <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                  </svg>
                  <input type="password" id="senha" name="senha" placeholder="Crie uma senha" required>
                  <button type="button" class="toggle-eye" data-target="senha"><svg width="17" height="17"
                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" />
                      <circle cx="12" cy="12" r="3" />
                    </svg></button>
                </div>
                <div class="strength-wrap">
                  <div class="strength-bar"><span></span><span></span><span></span><span></span></div>
                  <span class="strength-label" id="strengthLabel">Força da senha</span>
                </div>
                <ul class="checklist" id="checklist">
                  <li data-rule="len"><span class="dot"></span>Mínimo de 8 caracteres</li>
                  <li data-rule="upper"><span class="dot"></span>Letra maiúscula</li>
                  <li data-rule="lower"><span class="dot"></span>Letra minúscula</li>
                  <li data-rule="num"><span class="dot"></span>Número</li>
                  <li data-rule="special"><span class="dot"></span>Caractere especial</li>
                </ul>
              </div>

              <div class="field">
                <label for="confirmaSenha">Confirmar senha</label>
                <div class="input-wrap">
                  <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="4" y="10" width="16" height="10" rx="2" />
                    <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                  </svg>
                  <input type="password" id="confirmaSenha" name="confirmaSenha" placeholder="Confirme sua senha"
                    required>
                  <button type="button" class="toggle-eye" data-target="confirmaSenha"><svg width="17" height="17"
                      viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z" />
                      <circle cx="12" cy="12" r="3" />
                    </svg></button>
                </div>
                <div class="error-msg">As senhas não coincidem.</div>
              </div>

              <div class="section-title">Deseja habilitar autenticação em dois fatores (2FA)?</div>
              <div class="radio-cards">
                <label class="radio-card" id="rc2faSim"><input type="radio" name="fa2"
                    value="sim"><span>Sim</span></label>
                <label class="radio-card selected" id="rc2faNao"><input type="radio" name="fa2" value="nao"
                    checked><span>Não</span></label>
              </div>
              <div class="sub-panel" id="fa2options">
                <div class="field no-icon" style="margin-bottom:0;">
                  <label for="fa2metodo">Método de verificação</label>
                  <div class="input-wrap">
                    <select id="fa2metodo" name="fa2metodo">
                      <option value="app">Aplicativo autenticador</option>
                      <option value="sms">SMS</option>
                      <option value="email">E-mail</option>
                    </select>
                    <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="m6 9 6 6 6-6" />
                    </svg>
                  </div>
                </div>
              </div>
            </div>

            <!-- STEP 3 -->
            <div class="step-panel" data-panel="3">
              <h3>Dados da empresa</h3>
              <p class="step-desc">Cadastre as informações da empresa cliente.</p>

              <div class="grid2">
                <div class="field">
                  <label for="razaoSocial">Razão Social</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-6h4v6" />
                    </svg>
                    <input type="text" id="razaoSocial" name="razaoSocial" placeholder="Razão social" required>
                  </div>
                  <div class="error-msg">Informe a razão social.</div>
                </div>
                <div class="field">
                  <label for="nomeFantasia">Nome Fantasia</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-6h4v6" />
                    </svg>
                    <input type="text" id="nomeFantasia" name="nomeFantasia" placeholder="Nome fantasia" required>
                  </div>
                  <div class="error-msg">Informe o nome fantasia.</div>
                </div>
              </div>

              <div class="grid2">
                <div class="field">
                  <label for="cnpj">CNPJ</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <rect x="3" y="5" width="18" height="14" rx="2" />
                      <path d="M7 9h4M7 13h7" />
                    </svg>
                    <input type="text" id="cnpj" name="cnpj" placeholder="00.000.000/0000-00" maxlength="18" required>
                  </div>
                  <div class="error-msg">CNPJ inválido.</div>
                </div>
                <div class="field">
                  <label for="inscEstadual">Inscrição Estadual <span class="opt">(opcional)</span></label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <rect x="4" y="4" width="16" height="16" rx="2" />
                      <path d="M8 9h8M8 13h5" />
                    </svg>
                    <input type="text" id="inscEstadual" name="inscEstadual" placeholder="000.000.000.000">
                  </div>
                </div>
              </div>

              <div class="grid2">
                <div class="field">
                  <label for="segmento">Segmento</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5" />
                    </svg>
                    <select id="segmento" name="segmento" required>
                      <option value="">Selecione</option>
                      <option>Indústria</option>
                      <option>Hospital</option>
                      <option>Universidade</option>
                      <option>Empresa privada</option>
                      <option>Órgão público</option>
                      <option>Centro logístico</option>
                      <option>Shopping</option>
                      <option>Outro</option>
                    </select>
                    <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="m6 9 6 6 6-6" />
                    </svg>
                  </div>
                  <div class="error-msg">Selecione o segmento.</div>
                </div>
                <div class="field">
                  <label for="qtdColab">Quantidade aproximada de colaboradores</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <circle cx="9" cy="8" r="3.5" />
                      <path d="M2 20c0-3.5 3-6 7-6s7 2.5 7 6" />
                      <circle cx="18" cy="9" r="2.6" />
                      <path d="M16.2 14.2c2.6.2 4.8 2.2 4.8 5.8" />
                    </svg>
                    <input type="number" min="1" id="qtdColab" name="qtdColab" placeholder="Ex: 120" required>
                  </div>
                  <div class="error-msg">Informe a quantidade de colaboradores.</div>
                </div>
              </div>

              <div class="field">
                <label for="endereco">Endereço completo</label>
                <div class="input-wrap">
                  <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 10c0 6-9 12-9 12S3 16 3 10a9 9 0 0 1 18 0z" />
                    <circle cx="12" cy="10" r="3" />
                  </svg>
                  <input type="text" id="endereco" name="endereco" placeholder="Rua, número, bairro" required>
                </div>
                <div class="error-msg">Informe o endereço.</div>
              </div>

              <div class="grid2">
                <div class="field">
                  <label for="cep">CEP <span class="opt">(preenche cidade/estado)</span></label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M21 10c0 6-9 12-9 12S3 16 3 10a9 9 0 0 1 18 0z" />
                      <circle cx="12" cy="10" r="3" />
                    </svg>
                    <input type="text" id="cep" name="cep" placeholder="00000-000" maxlength="9" required>
                  </div>
                  <div class="error-msg">CEP inválido.</div>
                  <div class="hint" id="cepStatus" style="display:none;"></div>
                </div>
                <div class="field">
                  <label for="cidade">Cidade</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-6h4v6" />
                    </svg>
                    <input type="text" id="cidade" name="cidade" placeholder="Cidade" required>
                  </div>
                  <div class="error-msg">Informe a cidade.</div>
                </div>
              </div>

              <div class="grid2">
                <div class="field">
                  <label for="estado">Estado</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="M3 21h18M6 21V8l6-4 6 4v13M10 21v-6h4v6" />
                    </svg>
                    <select id="estado" name="estado" required>
                      <option value="">UF</option>
                      <option>AC</option>
                      <option>AL</option>
                      <option>AP</option>
                      <option>AM</option>
                      <option>BA</option>
                      <option>CE</option>
                      <option>DF</option>
                      <option>ES</option>
                      <option>GO</option>
                      <option>MA</option>
                      <option>MT</option>
                      <option>MS</option>
                      <option>MG</option>
                      <option>PA</option>
                      <option>PB</option>
                      <option>PR</option>
                      <option>PE</option>
                      <option>PI</option>
                      <option>RJ</option>
                      <option>RN</option>
                      <option>RS</option>
                      <option>RO</option>
                      <option>RR</option>
                      <option>SC</option>
                      <option>SP</option>
                      <option>SE</option>
                      <option>TO</option>
                    </select>
                    <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path d="m6 9 6 6 6-6" />
                    </svg>
                  </div>
                  <div class="error-msg">Selecione o estado.</div>
                </div>
                <div class="field">
                  <label for="telEmpresa">Telefone da empresa</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path
                        d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.3 1.9.6 2.7a2 2 0 0 1-.4 2.1L8.1 9.7a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.4c.9.3 1.8.5 2.7.6a2 2 0 0 1 1.9 2.2z" />
                    </svg>
                    <input type="text" id="telEmpresa" name="telEmpresa" placeholder="(00) 00000-0000" maxlength="15"
                      required>
                  </div>
                  <div class="error-msg">Telefone inválido.</div>
                </div>
              </div>

              <div class="grid2">
                <div class="field">
                  <label for="siteEmpresa">Site <span class="opt">(opcional)</span></label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <circle cx="12" cy="12" r="9" />
                      <path d="M3 12h18M12 3a14 14 0 0 1 0 18 14 14 0 0 1 0-18z" />
                    </svg>
                    <input type="text" id="siteEmpresa" name="siteEmpresa" placeholder="www.suaempresa.com.br">
                  </div>
                </div>
                <div class="field">
                  <label for="horarioFunc">Horário padrão de funcionamento</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <circle cx="12" cy="12" r="9" />
                      <path d="M12 7v5l3 3" />
                    </svg>
                    <input type="text" id="horarioFunc" name="horarioFunc" placeholder="Ex: 07:00 às 18:00" required>
                  </div>
                  <div class="error-msg">Informe o horário de funcionamento.</div>
                </div>
              </div>

              <div class="grid2">
                <div class="field">
                  <label for="responsavel">Responsável pelo contrato</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <circle cx="12" cy="8" r="4" />
                      <path d="M4 21c0-4 4-6 8-6s8 2 8 6" />
                    </svg>
                    <input type="text" id="responsavel" name="responsavel" placeholder="Nome do responsável" required>
                  </div>
                  <div class="error-msg">Informe o responsável.</div>
                </div>
                <div class="field">
                  <label for="emailInst">E-mail institucional</label>
                  <div class="input-wrap">
                    <svg class="leading" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <rect x="3" y="5" width="18" height="14" rx="2" />
                      <path d="m3 7 9 6 9-6" />
                    </svg>
                    <input type="email" id="emailInst" name="emailInst" placeholder="contato@empresa.com" required>
                  </div>
                  <div class="error-msg">E-mail institucional inválido.</div>
                </div>
              </div>
            </div>

            <!-- STEP 4 -->
            <div class="step-panel" data-panel="4">
              <h3>Configuração inicial</h3>
              <p class="step-desc">Personalize o ambiente antes do primeiro acesso.</p>

              <div class="section-title"><span class="badge-num">1</span>Controle de acesso</div>
              <div class="check-grid">
                <label class="check-item"><input type="checkbox" name="recursosAcesso[]"
                    value="Catracas"><span>Catracas</span></label>
                <label class="check-item"><input type="checkbox" name="recursosAcesso[]"
                    value="Reconhecimento facial"><span>Reconhecimento facial</span></label>
                <label class="check-item"><input type="checkbox" name="recursosAcesso[]" value="QR Code"><span>QR
                    Code</span></label>
                <label class="check-item"><input type="checkbox" name="recursosAcesso[]"
                    value="Cartão RFID"><span>Cartão RFID</span></label>
                <label class="check-item"><input type="checkbox" name="recursosAcesso[]"
                    value="Biometria"><span>Biometria</span></label>
              </div>

              <div class="section-title"><span class="badge-num">2</span>Previsão de demanda alimentar</div>
              <div class="field no-icon" style="margin-bottom:10px;">
                <label>A empresa possui refeitório?</label>
                <div class="radio-cards">
                  <label class="radio-card" id="rcRefSim"><input type="radio" name="refeitorio"
                      value="sim"><span>Sim</span></label>
                  <label class="radio-card selected" id="rcRefNao"><input type="radio" name="refeitorio" value="nao"
                      checked><span>Não</span></label>
                </div>
              </div>
              <div class="sub-panel" id="refeitorioPanel">
                <label
                  style="font-size:11px; font-weight:700; color:var(--gray-600); display:block; margin-bottom:2px;">Número
                  médio de refeições por dia</label>
                <div class="meal-grid">
                  <div class="meal-item"><label for="mealCafe">Café</label><input type="number" min="0" id="mealCafe"
                      name="mealCafe" placeholder="0"></div>
                  <div class="meal-item"><label for="mealAlmoco">Almoço</label><input type="number" min="0"
                      id="mealAlmoco" name="mealAlmoco" placeholder="0"></div>
                  <div class="meal-item"><label for="mealJantar">Jantar</label><input type="number" min="0"
                      id="mealJantar" name="mealJantar" placeholder="0"></div>
                  <div class="meal-item"><label for="mealCeia">Ceia</label><input type="number" min="0" id="mealCeia"
                      name="mealCeia" placeholder="0"></div>
                </div>
              </div>

              <div class="field no-icon" style="margin-top:14px;">
                <label for="controleAtual">Existe controle atual de refeições?</label>
                <div class="input-wrap">
                  <select id="controleAtual" name="controleAtual">
                    <option value="Não">Não</option>
                    <option value="Sim">Sim</option>
                    <option value="Planilha">Planilha</option>
                    <option value="Outro sistema">Outro sistema</option>
                  </select>
                  <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="m6 9 6 6 6-6" />
                  </svg>
                </div>
              </div>

              <div class="section-title"><span class="badge-num">3</span>Integrações</div>
              <div class="check-grid">
                <label class="check-item"><input type="checkbox" name="integracoes[]"
                    value="ERP"><span>ERP</span></label>
                <label class="check-item"><input type="checkbox" name="integracoes[]" value="RH"><span>RH</span></label>
                <label class="check-item"><input type="checkbox" name="integracoes[]"
                    value="Folha de pagamento"><span>Folha de pagamento</span></label>
                <label class="check-item"><input type="checkbox" name="integracoes[]"
                    value="Active Directory"><span>Active Directory</span></label>
                <label class="check-item"><input type="checkbox" name="integracoes[]" value="Azure AD"><span>Azure
                    AD</span></label>
                <label class="check-item"><input type="checkbox" name="integracoes[]" value="API própria"><span>API
                    própria</span></label>
              </div>
            </div>

            <!-- STEP 5 -->
            <div class="step-panel" data-panel="5">
              <h3>Revisão</h3>
              <p class="step-desc">Confira todas as informações antes de concluir o cadastro.</p>

              <div class="review-card">
                <div class="rc-head"><strong>Administrador</strong><button type="button" class="edit-btn"
                    data-goto="1">✎ Editar</button></div>
                <div class="review-row"><span class="k">Nome</span><span class="v" id="rv-nome">—</span></div>
                <div class="review-row"><span class="k">E-mail</span><span class="v" id="rv-email">—</span></div>
                <div class="review-row"><span class="k">Telefone</span><span class="v" id="rv-tel">—</span></div>
                <div class="review-row"><span class="k">Cargo</span><span class="v" id="rv-cargo">—</span></div>
              </div>

              <div class="review-card">
                <div class="rc-head"><strong>Empresa</strong><button type="button" class="edit-btn" data-goto="3">✎
                    Editar</button></div>
                <div class="review-row"><span class="k">Razão Social</span><span class="v" id="rv-razao">—</span></div>
                <div class="review-row"><span class="k">CNPJ</span><span class="v" id="rv-cnpj">—</span></div>
                <div class="review-row"><span class="k">Segmento</span><span class="v" id="rv-segmento">—</span></div>
                <div class="review-row"><span class="k">Colaboradores</span><span class="v" id="rv-colab">—</span></div>
              </div>

              <div class="review-card">
                <div class="rc-head"><strong>Recursos habilitados</strong><button type="button" class="edit-btn"
                    data-goto="4">✎ Editar</button></div>
                <div class="review-row"><span class="k">Controle de acesso</span>
                  <div class="tag-list" id="rv-acesso">—</div>
                </div>
                <div class="review-row"><span class="k">Previsão alimentar</span><span class="v"
                    id="rv-refeitorio">—</span></div>
                <div class="review-row"><span class="k">Integrações</span>
                  <div class="tag-list" id="rv-integ">—</div>
                </div>
              </div>

              <div class="checkbox-row">
                <input type="checkbox" id="aceiteTermos" name="aceiteTermos" required>
                <label for="aceiteTermos">Li e concordo com os Termos de Uso e Política de Privacidade.</label>
              </div>
              <div class="error-msg" id="errTermos">É necessário aceitar os termos para continuar.</div>
            </div>

            <!-- STEP 6 -->
            <div class="step-panel" data-panel="6">
              <div class="done-wrap">
                <div class="done-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="m5 13 4 4L19 7" />
                  </svg></div>
                <h2>Conta criada com sucesso!</h2>
                <p>A empresa foi cadastrada com sucesso. Agora você já pode acessar o SICAPDA e iniciar as configurações
                  do sistema.</p>
                <div class="done-summary">
                  <div class="review-row"><span class="k">Empresa</span><span class="v" id="done-empresa">—</span></div>
                  <div class="review-row"><span class="k">Administrador</span><span class="v" id="done-admin">—</span>
                  </div>
                  <div class="review-row"><span class="k">E-mail</span><span class="v" id="done-email">—</span></div>
                  <div class="review-row"><span class="k">Plano contratado</span><span class="v">Empresarial</span>
                  </div>
                  <div class="review-row"><span class="k">Data de criação</span><span class="v" id="done-data">—</span>
                  </div>
                </div>
                <div class="done-btns">
                  <button type="button" class="btn btn-primary" id="btnEntrar">Entrar no sistema →</button>
                  <button type="button" class="btn btn-ghost" id="btnVoltarLogin">Voltar para login</button>
                </div>
              </div>
            </div>

            <div class="btn-row" id="btnRow">
              <button type="button" class="btn btn-ghost" id="btnBack">← Voltar</button>
              <button type="button" class="btn btn-primary" id="btnNext">Próximo →</button>
            </div>
          </form>

          <p class="kbd-hint" id="kbdHint">Pressione <kbd>Enter</kbd> para avançar</p>
          <div class="security-footer" id="secFooter">
            <i class="bi bi-lock cadeado"></i>
            Ambiente protegido e criptografado
          </div>
          <div>
            <a class="loginLink" href="/login">Já tem uma conta? Clique aqui</a>
          </div>
        </div>
      </div>
    </div>

  </div>

  <script src="../../public/js/scriptCadastro.js"></script>
</body></html>