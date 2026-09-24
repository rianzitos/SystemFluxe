<?php
// Bootstrap próprio: garante que esta página seja protegida mesmo que
// alguém tente acessá-la diretamente pela URL, sem passar pelo roteador
// em app/routes/web.php (ex: fluxeteam.com.br/app/views/configuracoes.php).
require_once __DIR__ . '/../../config/app.php';

// Bloqueia o acesso e redireciona para /login se não houver sessão ativa.
AuthMiddleware::autenticado();

// -----------------------------------------------------------------------
// DADOS DA PÁGINA DE CONFIGURAÇÕES
// -----------------------------------------------------------------------
// Mesma lógica do painel.php: os arrays abaixo são mock só pra página ter
// o que exibir enquanto o Model/Controller de configurações não existe.
// Quando estiver pronto, é só trocar cada array pela consulta real —
// a estrutura já está pensada pra receber esses valores sem mexer no HTML.
// -----------------------------------------------------------------------

$paginaAtual = 'config';

// Itens do menu lateral (idêntico ao painel.php p/ manter a navegação consistente)
$menu = [
    ['chave' => 'painel', 'rota' => '/painel', 'label' => 'Painel', 'icone' => 'bi-pie-chart-fill'],
    ['chave' => 'acessos', 'rota' => '/acessos', 'label' => 'Análise mensal', 'icone' => 'bi-calendar'],
    ['chave' => 'pessoas', 'rota' => '/pessoas', 'label' => 'Pessoas', 'icone' => ' bi-people-fill'],
    ['chave' => 'previsao', 'rota' => '/assistente', 'label' => 'Assistente IA', 'icone' => 'bi-chat-left'],
    ['chave' => 'relatorios', 'rota' => '/relatorios', 'label' => 'Relatórios', 'icone' => 'bi-clipboard-data'],
    ['chave' => 'config', 'rota' => '/configuracoes', 'label' => 'Configurações', 'icone' => 'bi-gear-fill'],
];

$notificacoesNaoLidas = 2;

$nomeUsuario = $_SESSION['usuario_nome'] ?? 'Usuário';
$perfilUsuario = $_SESSION['usuario_perfil'] ?? '—';
$fotoPerfil = $_SESSION['usuario_foto'] ?? null;
$emailUsuario = $_SESSION['usuario_email'] ?? '—';
$instituicaoUsuario = $_SESSION['usuario_instituicao'] ?? '—';

// Abas da página (equivalente ao menu vertical "Perfil / Notificações / Sistema..." do design de referência)
$abas = [
    ['chave' => 'perfil', 'label' => 'Perfil', 'icone' => 'bi-person'],
    ['chave' => 'aparencia', 'label' => 'Aparência', 'icone' => 'bi-palette'],
    ['chave' => 'notificacoes', 'label' => 'Notificações', 'icone' => 'bi-bell'],
    ['chave' => 'sistema', 'label' => 'Sistema', 'icone' => 'bi-sliders'],
    ['chave' => 'integracoes', 'label' => 'Integrações', 'icone' => 'bi-plug'],
    ['chave' => 'seguranca', 'label' => 'Segurança', 'icone' => 'bi-shield-lock'],
];

// Preferências de notificação
$notificacoesConfig = [
    ['chave' => 'previsao_demanda', 'label' => 'Alertas de previsão de demanda', 'descricao' => 'Avisos quando a previsão sair do padrão esperado', 'ativo' => true],
    ['chave' => 'acesso_negado', 'label' => 'Tentativas de acesso não autorizado', 'descricao' => 'Alerta imediato quando a catraca negar um acesso', 'ativo' => true],
    ['chave' => 'resumo_diario', 'label' => 'Resumo diário por e-mail', 'descricao' => 'Um e-mail às 08h com o resumo do dia anterior', 'ativo' => false],
    ['chave' => 'dispositivo_offline', 'label' => 'Dispositivo IoT offline', 'descricao' => 'Avisar quando um ESP32 parar de responder', 'ativo' => true],
];

// Dispositivos IoT vinculados (tabela dispositivos_iot)
$dispositivosIot = [
    ['nome' => 'Catraca · Entrada Principal', 'token' => 'sk_iot_8f2a1c9e...c91d', 'status' => 'online', 'ultimo_acesso' => 'há 2 min'],
    ['nome' => 'Catraca · Refeitório', 'token' => 'sk_iot_3b7ec204...44aa', 'status' => 'offline', 'ultimo_acesso' => 'há 6 horas'],
];

// Sessões ativas (mock — futuramente ligado a uma tabela de sessões)
$sessoesAtivas = [
    ['dispositivo' => 'Chrome · Windows', 'local' => 'Mococa, SP', 'ultimo_acesso' => 'agora', 'atual' => true],
    ['dispositivo' => 'App SICAPDA · Android', 'local' => 'Mococa, SP', 'ultimo_acesso' => 'há 1 dia', 'atual' => false],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações · SICAPDA</title>

    <!-- Aplica o tema salvo (claro/escuro/sistema) antes do CSS carregar, pra evitar
         o "flash" da tela clara antes de escurecer. Mesmo bloco deve estar em toda
         página que usa painel.css (painel.php, acessos.php, pessoas.php etc). -->
    <script>
        (function () {
            var tema = localStorage.getItem('sicapda_tema') || 'claro';
            if (tema === 'sistema') {
                tema = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
            }
            if (tema === 'escuro') {
                document.documentElement.setAttribute('data-tema', 'escuro');
            }
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="/css/painel.css">
    <link rel="stylesheet" href="/css/configuracoes.css">
    <link rel="shortcut icon" href="/img/logo_fluxe.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
</head>

<body>

    <div class="layout">

        <!-- ===================== SIDEBAR (idêntica ao painel.php) ===================== -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-topo">
                <div class="logo">
                    <span class="logo-sica">SICA<span class="logo-pda">PDA</span></span>
                    <span class="logo-by">by <strong>FLUXE</strong></span>
                </div>

                <nav class="menu">
                    <?php foreach ($menu as $item): ?>
                        <a href="<?= htmlspecialchars($item['rota']) ?>"
                            class="menu-item <?= $paginaAtual === $item['chave'] ? 'ativo' : '' ?>">
                            <div class="menu-icone menu-icone-<?= htmlspecialchars($item['chave']) ?>">
                                <i class="iconeMenu bi <?= htmlspecialchars($item['icone']) ?>"></i>
                            </div>
                            <span><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="sidebar-usuario">
                <?php if (!empty($fotoPerfil) && file_exists(__DIR__ . '/../../public/' . $fotoPerfil)): ?>
                    <div class="avatar avatar-foto">
                        <img src="/<?= htmlspecialchars($fotoPerfil) ?>"
                            alt="Foto de <?= htmlspecialchars($nomeUsuario) ?>">
                    </div>
                <?php else: ?>
                    <div class="avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($nomeUsuario, 0, 1))) ?></div>
                <?php endif; ?>
                <div class="sidebar-usuario-info">
                    <strong><?= htmlspecialchars($nomeUsuario) ?></strong>
                    <span><?= htmlspecialchars($perfilUsuario) ?> <i class="ponto-online"></i></span>
                </div>
                <a href="/logout" class="sair" title="Sair">
                    <i class="icone bi bi-box-arrow-right"></i>
                </a>
            </div>
        </aside>

        <!-- ===================== CONTEÚDO ===================== -->
        <div class="conteudo">

            <button class="btn-menu-mobile" id="btnMenuMobile" aria-label="Abrir menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>

            <!-- Cabeçalho -->
            <header class="cabecalho">
                <div>
                    <h1>Configurações</h1>
                    <p>Gerencie preferências, integrações e segurança do sistema</p>
                </div>

                <div class="cabecalho-acoes">
                    <button class="sino" id="btnNotificacoes" aria-label="Notificações">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M6 8a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 12 6 8Z" />
                            <path d="M10 18a2 2 0 0 0 4 0" />
                        </svg>
                        <?php if ($notificacoesNaoLidas > 0): ?>
                            <span class="badge-sino"><?= (int) $notificacoesNaoLidas ?></span>
                        <?php endif; ?>
                    </button>
                </div>
            </header>

            <!-- ===================== ÁREA DE CONFIGURAÇÕES ===================== -->
            <div class="config-layout">

                <!-- Abas verticais -->
                <nav class="config-abas" id="configAbas">
                    <?php foreach ($abas as $i => $aba): ?>
                        <button type="button" class="config-aba <?= $i === 0 ? 'ativa' : '' ?>"
                            data-aba="<?= htmlspecialchars($aba['chave']) ?>">
                            <i class="bi <?= htmlspecialchars($aba['icone']) ?>"></i>
                            <span><?= htmlspecialchars($aba['label']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </nav>

                <!-- Painéis de conteúdo -->
                <div class="config-conteudo">

                    <!-- ---------- PERFIL ---------- -->
                    <section class="config-painel ativa" data-aba-painel="perfil">
                        <h2>Informações Pessoais</h2>

                        <div class="config-perfil-topo">
                            <?php if (!empty($fotoPerfil) && file_exists(__DIR__ . '/../../public/' . $fotoPerfil)): ?>
                                <div class="avatar avatar-foto avatar-grande">
                                    <img src="/<?= htmlspecialchars($fotoPerfil) ?>"
                                        alt="Foto de <?= htmlspecialchars($nomeUsuario) ?>">
                                </div>
                            <?php else: ?>
                                <div class="avatar avatar-grande">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($nomeUsuario, 0, 1))) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <strong class="config-perfil-nome"><?= htmlspecialchars($nomeUsuario) ?></strong>
                                <span class="config-perfil-email"><?= htmlspecialchars($emailUsuario) ?></span>
                                <label class="link-acao" for="inputFotoPerfil">Alterar foto</label>
                                <input type="file" id="inputFotoPerfil" name="foto_perfil" accept="image/*" hidden>
                            </div>
                        </div>

                        <form class="config-form" id="formPerfil" autocomplete="off">
                            <div class="campo">
                                <label for="perfilNome">Nome completo</label>
                                <input type="text" id="perfilNome" name="nome"
                                    value="<?= htmlspecialchars($nomeUsuario) ?>">
                            </div>
                            <div class="campo">
                                <label for="perfilEmail">E-mail</label>
                                <input type="email" id="perfilEmail" name="email"
                                    value="<?= htmlspecialchars($emailUsuario) ?>">
                            </div>
                            <div class="campo">
                                <label for="perfilCargo">Cargo</label>
                                <input type="text" id="perfilCargo" name="cargo"
                                    value="<?= htmlspecialchars($perfilUsuario) ?>">
                            </div>
                            <div class="campo">
                                <label for="perfilInstituicao">Instituição</label>
                                <input type="text" id="perfilInstituicao" name="instituicao"
                                    value="<?= htmlspecialchars($instituicaoUsuario) ?>">
                            </div>

                            <div class="config-form-acoes">
                                <button type="submit" class="btn-primario">
                                    <i class="bi bi-save"></i> Salvar alterações
                                </button>
                            </div>
                        </form>
                    </section>

                    <!-- ---------- APARÊNCIA ---------- -->
                    <section class="config-painel" data-aba-painel="aparencia">
                        <h2>Aparência</h2>
                        <p class="config-painel-desc">Personalize como o SICAPDA aparece pra você neste navegador.</p>

                        <div class="config-bloco">
                            <h3>Tema</h3>
                            <div class="tema-opcoes" id="temaOpcoes">
                                <button type="button" class="tema-opcao ativa" data-tema="claro">
                                    <span class="tema-preview tema-preview-claro"></span>
                                    Claro
                                </button>
                                <button type="button" class="tema-opcao" data-tema="escuro">
                                    <span class="tema-preview tema-preview-escuro"></span>
                                    Escuro
                                </button>
                                <button type="button" class="tema-opcao" data-tema="sistema">
                                    <span class="tema-preview tema-preview-sistema"></span>
                                    Sistema
                                </button>
                            </div>
                        </div>

                    </section>

                    <!-- ---------- NOTIFICAÇÕES ---------- -->
                    <section class="config-painel" data-aba-painel="notificacoes">
                        <h2>Notificações</h2>
                        <p class="config-painel-desc">Escolha quais eventos devem gerar um alerta.</p>

                        <div class="lista-toggles">
                            <?php foreach ($notificacoesConfig as $item): ?>
                                <div class="item-toggle">
                                    <div>
                                        <strong><?= htmlspecialchars($item['label']) ?></strong>
                                        <p><?= htmlspecialchars($item['descricao']) ?></p>
                                    </div>
                                    <label class="switch">
                                        <input type="checkbox" data-notificacao="<?= htmlspecialchars($item['chave']) ?>"
                                            <?= $item['ativo'] ? 'checked' : '' ?>>
                                        <span class="switch-trilho"></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <!-- ---------- SISTEMA ---------- -->
                    <section class="config-painel" data-aba-painel="sistema">
                        <h2>Sistema</h2>
                        <p class="config-painel-desc">Preferências gerais de exibição do painel.</p>

                        <form class="config-form" id="formSistema" autocomplete="off">
                            <div class="campo">
                                <label for="periodoPadrao">Período padrão do dashboard</label>
                                <select id="periodoPadrao" name="periodo_padrao">
                                    <option value="7">Últimos 7 dias</option>
                                    <option value="30" selected>Últimos 30 dias</option>
                                    <option value="90">Últimos 90 dias</option>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="fusoHorario">Fuso horário</label>
                                <select id="fusoHorario" name="fuso_horario">
                                    <option value="America/Sao_Paulo" selected>América/São Paulo (GMT-3)</option>
                                    <option value="America/Manaus">América/Manaus (GMT-4)</option>
                                    <option value="UTC">UTC</option>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="formatoData">Formato de data</label>
                                <select id="formatoData" name="formato_data">
                                    <option value="dmy" selected>DD/MM/AAAA</option>
                                    <option value="mdy">MM/DD/AAAA</option>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="itensPagina">Itens por página nas listagens</label>
                                <select id="itensPagina" name="itens_pagina">
                                    <option value="10">10</option>
                                    <option value="25" selected>25</option>
                                    <option value="50">50</option>
                                </select>
                            </div>

                            <div class="config-form-acoes">
                                <button type="submit" class="btn-primario">
                                    <i class="bi bi-save"></i> Salvar alterações
                                </button>
                            </div>
                        </form>
                    </section>

                    <!-- ---------- INTEGRAÇÕES ---------- -->
                    <section class="config-painel" data-aba-painel="integracoes">
                        <h2>Dispositivos IoT</h2>
                        <p class="config-painel-desc">Catracas e leitores RFID vinculados a este sistema.</p>

                        <div class="lista-dispositivos" id="listaDispositivos">
                            <?php foreach ($dispositivosIot as $dispositivo): ?>
                                <div class="item-dispositivo">
                                    <div class="item-dispositivo-status status-<?= htmlspecialchars($dispositivo['status']) ?>"></div>
                                    <div class="item-dispositivo-info">
                                        <strong><?= htmlspecialchars($dispositivo['nome']) ?></strong>
                                        <span class="token-mascarado"><?= htmlspecialchars($dispositivo['token']) ?></span>
                                    </div>
                                    <div class="item-dispositivo-meta">
                                        <span class="status-texto status-texto-<?= htmlspecialchars($dispositivo['status']) ?>">
                                            <?= $dispositivo['status'] === 'online' ? 'Online' : 'Offline' ?>
                                        </span>
                                        <span class="item-dispositivo-tempo"><?= htmlspecialchars($dispositivo['ultimo_acesso']) ?></span>
                                    </div>
                                    <button type="button" class="btn-secundario btn-regenerar-token">
                                        <i class="bi bi-arrow-repeat"></i> Novo token
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" class="btn-secundario config-btn-adicionar">
                            <i class="bi bi-plus-lg"></i> Adicionar dispositivo
                        </button>
                    </section>

                    <!-- ---------- SEGURANÇA ---------- -->
                    <section class="config-painel" data-aba-painel="seguranca">
                        <h2>Segurança</h2>

                        <div class="config-bloco">
                            <h3>Alterar senha</h3>
                            <form class="config-form" id="formSenha" autocomplete="off">
                                <div class="campo">
                                    <label for="senhaAtual">Senha atual</label>
                                    <input type="password" id="senhaAtual" name="senha_atual">
                                </div>
                                <div class="campo">
                                    <label for="senhaNova">Nova senha</label>
                                    <input type="password" id="senhaNova" name="senha_nova">
                                </div>
                                <div class="campo">
                                    <label for="senhaConfirmar">Confirmar nova senha</label>
                                    <input type="password" id="senhaConfirmar" name="senha_confirmar">
                                </div>
                                <p class="campo-erro" id="erroSenha" hidden>As senhas não coincidem.</p>
                                <div class="config-form-acoes">
                                    <button type="submit" class="btn-primario">
                                        <i class="bi bi-shield-check"></i> Atualizar senha
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div class="config-bloco">
                            <div class="item-toggle item-toggle-bloco">
                                <div>
                                    <strong>Autenticação em duas etapas</strong>
                                    <p>Exige um código adicional ao entrar na conta.</p>
                                </div>
                                <label class="switch">
                                    <input type="checkbox" id="toggle2FA">
                                    <span class="switch-trilho"></span>
                                </label>
                            </div>
                        </div>

                        <div class="config-bloco">
                            <h3>Sessões ativas</h3>
                            <div class="lista-sessoes" id="listaSessoes">
                                <?php foreach ($sessoesAtivas as $sessao): ?>
                                    <div class="item-sessao">
                                        <i class="bi bi-laptop"></i>
                                        <div class="item-sessao-info">
                                            <strong><?= htmlspecialchars($sessao['dispositivo']) ?>
                                                <?php if ($sessao['atual']): ?><span class="tag-atual">Esta sessão</span><?php endif; ?>
                                            </strong>
                                            <span><?= htmlspecialchars($sessao['local']) ?> · <?= htmlspecialchars($sessao['ultimo_acesso']) ?></span>
                                        </div>
                                        <?php if (!$sessao['atual']): ?>
                                            <button type="button" class="btn-texto btn-encerrar-sessao">Encerrar</button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                </div>
            </div>

        </div>
    </div>

    <script src="/js/configuracoes.js"></script>
</body>

</html>