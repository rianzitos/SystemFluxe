<?php
// Bootstrap próprio: garante que esta página seja protegida mesmo que
// alguém tente acessá-la diretamente pela URL, sem passar pelo roteador
// em app/routes/web.php (ex: fluxeteam.com.br/app/views/configuracoes.php).
require_once __DIR__ . '/../../config/app.php';

// Bloqueia o acesso e redireciona para /login se não houver sessão ativa.
AuthMiddleware::autenticado();

// -----------------------------------------------------------------------
// DADOS DA PÁGINA DE CONFIGURAÇÕES — tudo vem do banco: perfil (Usuario),
// preferências (Preferencia), dispositivos IoT (Dispositivo) e sessões (Sessao).
// As ações dessa tela ficam em ConfiguracaoController (rotas /configuracoes/*).
// -----------------------------------------------------------------------

$paginaAtual = 'config';

// Dados reais do usuário logado (lidos do banco, não só da sessão)
$dadosUsuario = (new Usuario())->buscarPerfilCompleto((int) $_SESSION['usuario_id']);
if (!$dadosUsuario) {
    // Usuário removido do banco com a sessão ainda aberta
    header('Location: /logout');
    exit;
}

$nomeUsuario = $dadosUsuario['nome'];
$perfilUsuario = $_SESSION['usuario_perfil'] ?? '—';
$fotoPerfil = $dadosUsuario['foto'] ?? null;
$emailUsuario = $dadosUsuario['email'];
$cargoUsuario = $dadosUsuario['cargo'] ?? '';
$instituicaoUsuario = $dadosUsuario['empresa_nome'] ?? '—';

$usuarioId = (int) $_SESSION['usuario_id'];
$empresaId = Pagina::empresaId();
// Só o administrador da empresa cria dispositivos e gera tokens (o servidor confere de novo).
$ehAdmin = ($_SESSION['usuario_perfil'] ?? '') === 'admin' && $empresaId > 0;

// Badge do sino: o mesmo cálculo das outras telas (alertas de previsão + catracas, conforme as preferências).
$notificacoesNaoLidas = Notificacoes::contar((new Painel($empresaId))->totalAlertas(), $empresaId);

$preferencias = (new Preferencia())->obter($usuarioId);

// Abas da página (equivalente ao menu vertical "Perfil / Notificações / Sistema..." do design de referência)
$abas = [
    ['chave' => 'perfil', 'label' => 'Perfil', 'icone' => 'bi-person'],
    ['chave' => 'aparencia', 'label' => 'Aparência', 'icone' => 'bi-palette'],
    ['chave' => 'notificacoes', 'label' => 'Notificações', 'icone' => 'bi-bell'],
    ['chave' => 'sistema', 'label' => 'Sistema', 'icone' => 'bi-sliders'],
    ['chave' => 'integracoes', 'label' => 'Integrações', 'icone' => 'bi-plug'],
    ['chave' => 'seguranca', 'label' => 'Segurança', 'icone' => 'bi-shield-lock'],
];

// Preferências de notificação (o mesmo catálogo que o aplicativo mostra). "disponivel" false = ainda não funciona.
$notificacoesConfig = (new Preferencia())->notificacoesDoUsuario($usuarioId);

// Opções da aba Sistema (valor => rótulo)
$periodosRelatorio = Preferencia::PERIODOS_ROTULOS;

// Dispositivos IoT da empresa (tabela dispositivos_iot): status vem do último sinal (ping) da ESP32.
$dispositivosIot = $empresaId > 0 ? (new Dispositivo())->listar($empresaId) : [];
$balancas = array_filter($dispositivosIot, fn($d) => $d['tipo'] === 'balanca');
$catracas = array_filter($dispositivosIot, fn($d) => $d['tipo'] !== 'balanca');
$pesagensHoje = $empresaId > 0 ? Balanca::hoje($empresaId) : [];

// Verificação em duas etapas (DoisFatores): ativa ou não, método, códigos de recuperação que restam.
// "disponivel" false = a migration 008 ainda não rodou neste banco.
$estado2fa = (new DoisFatores())->estado($usuarioId);

// Sessões ativas do site (tabela sessoes_usuario). null = a tabela ainda não existe neste banco.
try {
    $sessoesAtivas = (new Sessao())->listar($usuarioId);
} catch (PDOException $e) {
    error_log('SICAPDA: sessoes_usuario indisponível (rode a migration 004): ' . $e->getMessage());
    $sessoesAtivas = null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações · SICAPDA</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(CsrfMiddleware::token()) ?>">

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
<?php require __DIR__ . '/partials/sidebar.php'; ?>

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
                    <?php require __DIR__ . '/partials/acoes-topo.php'; ?>
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

                        <div class="config-perfil-topo" id="perfilTopo">
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
                                <strong class="config-perfil-nome" id="perfilNomeTopo"><?= htmlspecialchars($nomeUsuario) ?></strong>
                                <span class="config-perfil-email" id="perfilEmailTopo"><?= htmlspecialchars($emailUsuario) ?></span>
                                <label class="link-acao" for="inputFotoPerfil">Alterar foto</label>
                                <input type="file" id="inputFotoPerfil" name="foto_perfil" accept="image/jpeg,image/png" hidden>
                            </div>
                        </div>

                        <form class="config-form" id="formPerfil" autocomplete="off">
                            <div class="campo">
                                <label for="perfilNome">Nome completo</label>
                                <input type="text" id="perfilNome" name="nome" maxlength="150" required
                                    value="<?= htmlspecialchars($nomeUsuario) ?>">
                            </div>
                            <div class="campo">
                                <label for="perfilEmail">E-mail</label>
                                <input type="email" id="perfilEmail" name="email" maxlength="150" required
                                    value="<?= htmlspecialchars($emailUsuario) ?>">
                            </div>
                            <div class="campo">
                                <label for="perfilCargo">Cargo</label>
                                <input type="text" id="perfilCargo" name="cargo" maxlength="100"
                                    value="<?= htmlspecialchars($cargoUsuario) ?>">
                            </div>
                            <div class="campo">
                                <label for="perfilInstituicao">Instituição</label>
                                <input type="text" id="perfilInstituicao" name="instituicao" readonly
                                    value="<?= htmlspecialchars($instituicaoUsuario) ?>">
                            </div>
                            <div class="campo" id="campoSenhaEmail" hidden>
                                <label for="perfilSenhaAtual">Senha atual (necessária para alterar o e-mail)</label>
                                <input type="password" id="perfilSenhaAtual" name="senha_atual"
                                    autocomplete="current-password">
                            </div>
                            <p class="campo-erro" id="msgPerfil" hidden></p>

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
                        <p class="config-painel-desc">Escolha quais eventos aparecem no sino e na lista de alertas do painel e quais e-mails automáticos você recebe em <strong><?= htmlspecialchars($emailUsuario) ?></strong>. Vale só para a sua conta.</p>

                        <div class="lista-toggles">
                            <?php foreach ($notificacoesConfig as $item): ?>
                                <div class="item-toggle">
                                    <div>
                                        <strong><?= htmlspecialchars($item['label']) ?>
                                            <?php if (!$item['disponivel']): ?><span class="tag-breve">Em breve</span><?php endif; ?>
                                        </strong>
                                        <p><?= htmlspecialchars($item['descricao']) ?></p>
                                    </div>
                                    <label class="switch <?= $item['disponivel'] ? '' : 'switch-desativado' ?>">
                                        <input type="checkbox" data-notificacao="<?= htmlspecialchars($item['chave']) ?>"
                                            <?= $item['ativo'] ? 'checked' : '' ?> <?= $item['disponivel'] ? '' : 'disabled' ?>>
                                        <span class="switch-trilho"></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="campo-erro" id="msgNotificacoes" hidden></p>
                    </section>

                    <!-- ---------- SISTEMA ---------- -->
                    <section class="config-painel" data-aba-painel="sistema">
                        <h2>Sistema</h2>
                        <p class="config-painel-desc">Como os Relatórios aparecem para você no site.</p>

                        <form class="config-form" id="formSistema" autocomplete="off">
                            <div class="campo">
                                <label for="periodoPadrao">Período padrão dos relatórios</label>
                                <select id="periodoPadrao" name="periodo_padrao">
                                    <?php foreach ($periodosRelatorio as $valor => $rotulo): ?>
                                        <option value="<?= htmlspecialchars((string) $valor) ?>"
                                            <?= (string) $preferencias['sistema']['periodo_padrao'] === (string) $valor ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($rotulo) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="itensPagina">Itens por página nos relatórios</label>
                                <select id="itensPagina" name="itens_pagina">
                                    <?php foreach (Preferencia::ITENS_PAGINA as $qtd): ?>
                                        <option value="<?= (int) $qtd ?>"
                                            <?= (int) $preferencias['sistema']['itens_pagina'] === $qtd ? 'selected' : '' ?>>
                                            <?= (int) $qtd ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="campo">
                                <label for="fusoHorario">Fuso horário</label>
                                <input type="text" id="fusoHorario" value="<?= htmlspecialchars(Preferencia::FUSO_ROTULO) ?>" readonly>
                            </div>
                            <p class="campo-erro" id="msgSistema" hidden></p>

                            <div class="config-form-acoes">
                                <button type="submit" class="btn-primario">
                                    <i class="bi bi-save"></i> Salvar alterações
                                </button>
                            </div>
                        </form>
                        <p class="config-nota">O fuso é fixo: as catracas gravam os horários dos acessos no horário de Brasília, e os relatórios e a previsão leem no mesmo horário.</p>
                    </section>

                    <!-- ---------- INTEGRAÇÕES ---------- -->
                    <section class="config-painel" data-aba-painel="integracoes">
                        <h2 id="secaoIot">Dispositivos IoT</h2>
                        <p class="config-painel-desc">Catracas, leitores RFID e balanças vinculados à empresa. Cada dispositivo usa seu próprio token para enviar acessos ou pesagens.</p>

                        <div class="lista-dispositivos" id="listaDispositivos">
                            <?php foreach ($dispositivosIot as $dispositivo): ?>
                                <div class="item-dispositivo" data-id="<?= (int) $dispositivo['id'] ?>">
                                    <div class="item-dispositivo-status status-<?= htmlspecialchars($dispositivo['status']) ?>"></div>
                                    <div class="item-dispositivo-info">
                                        <strong><?= htmlspecialchars($dispositivo['nome']) ?></strong>
                                        <span class="item-dispositivo-detalhe"><?= htmlspecialchars($dispositivo['tipo_rotulo']) ?><?= $dispositivo['localizacao'] ? ' · ' . htmlspecialchars($dispositivo['localizacao']) : '' ?></span>
                                        <span class="token-mascarado"><?= htmlspecialchars($dispositivo['token']) ?></span>
                                    </div>
                                    <div class="item-dispositivo-meta">
                                        <span class="status-texto status-texto-<?= htmlspecialchars($dispositivo['status']) ?>">
                                            <?= $dispositivo['status'] === 'online' ? 'Online' : 'Offline' ?>
                                        </span>
                                        <span class="item-dispositivo-tempo"><?= htmlspecialchars($dispositivo['ultimo_sinal']) ?></span>
                                    </div>
                                    <?php if ($ehAdmin): ?>
                                        <button type="button" class="btn-secundario btn-regenerar-token">
                                            <i class="bi bi-arrow-repeat"></i> Novo token
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="config-nota" id="dispositivosVazio" <?= $dispositivosIot ? 'hidden' : '' ?>>Nenhum dispositivo cadastrado ainda.</p>

                        <div class="config-bloco">
                            <h3><i class="bi bi-speedometer2"></i> Desperdício de hoje</h3>
                            <p class="config-bloco-desc">Totais em kg por refeição, em <?= date('d/m/Y') ?>. Atualize a página após o envio da balança para consultar. Os valores alimentam a análise de desperdício.</p>
                            <div class="lista-dispositivos">
                                <?php foreach (Producao::TIPOS as $tipo => $rotulo): ?>
                                    <div class="item-dispositivo">
                                        <strong><?= htmlspecialchars($rotulo) ?></strong>
                                        <span data-peso-refeicao="<?= $tipo ?>"><?= isset($pesagensHoje[$tipo]) ? number_format((float) $pesagensHoje[$tipo], 2, ',', '.') . ' kg' : 'Sem registro' ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Token inteiro: aparece só ao criar ou gerar de novo, e some ao recarregar a página -->
                        <div class="token-revelado" id="tokenRevelado" role="status" hidden>
                            <strong id="tokenReveladoTitulo">Token do dispositivo</strong>
                            <p>Copie agora e cole no firmware da ESP32 (<code>TOKEN_DISPOSITIVO</code>). Por segurança ele <b>não será mostrado de novo</b>.</p>
                            <div class="token-revelado-linha">
                                <code id="tokenValor"></code>
                                <button type="button" class="btn-secundario" id="btnCopiarToken"><i class="bi bi-clipboard"></i> Copiar</button>
                            </div>
                        </div>

                        <?php if ($ehAdmin): ?>
                            <button type="button" class="btn-secundario config-btn-adicionar" id="btnAdicionarDispositivo">
                                <i class="bi bi-plus-lg"></i> Adicionar dispositivo
                            </button>

                            <!-- Simular leitura: passa um UID por uma catraca sem precisar da ESP32 -->
                            <div class="config-bloco simulador" id="blocoSimulador" <?= $catracas ? '' : 'hidden' ?>>
                                <h3>Simular leitura</h3>
                                <p class="config-bloco-desc">Passa um cartão por uma catraca sem precisar do aparelho, pela mesma regra de um acesso
                                    de verdade. <strong>Registra um acesso real</strong>: ele aparece no painel e nos relatórios.</p>
                                <form class="config-form" id="formSimulacao" autocomplete="off">
                                    <div class="campo">
                                        <label for="simDispositivo">Catraca</label>
                                        <select id="simDispositivo" name="id">
                                            <?php foreach ($catracas as $dispositivo): ?>
                                                <option value="<?= (int) $dispositivo['id'] ?>"><?= htmlspecialchars($dispositivo['nome']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="campo">
                                        <label for="simUid">UID do cartão</label>
                                        <input type="text" id="simUid" name="uid" maxlength="100" required placeholder="Ex.: 01020304">
                                    </div>
                                    <p class="campo-erro" id="msgSimulacao" hidden></p>
                                    <div class="config-form-acoes">
                                        <button type="submit" class="btn-secundario"><i class="bi bi-credit-card-2-front"></i> Simular passagem</button>
                                    </div>
                                </form>
                                <div class="resultado-simulacao" id="resultadoSimulacao" role="status" hidden></div>
                            </div>

                            <div class="config-bloco simulador" id="blocoBalanca" <?= $balancas ? '' : 'hidden' ?>>
                                <h3>Testar pesagem</h3>
                                <p class="config-bloco-desc">Registra o total real de desperdício de hoje, como no botão Encerrar turno do Wokwi. <strong>Substitui o total da refeição para toda a empresa</strong>, inclusive se houver mais de uma balança. Não soma pesagens individuais nem altera o status de conexão.</p>
                                <form class="config-form" id="formPesagem">
                                    <div class="campo"><label for="pesagemDispositivo">Balança</label><select id="pesagemDispositivo" name="id" required>
                                        <?php foreach ($balancas as $d): ?><option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['nome']) ?></option><?php endforeach; ?>
                                    </select></div>
                                    <div class="campo"><label for="pesagemTipo">Refeição</label><select id="pesagemTipo" name="tipo">
                                        <?php foreach (Producao::TIPOS as $tipo => $rotulo): ?><option value="<?= $tipo ?>"><?= htmlspecialchars($rotulo) ?></option><?php endforeach; ?>
                                    </select></div>
                                    <div class="campo"><label for="pesagemKg">Total de desperdício (kg)</label><input id="pesagemKg" name="desperdicio_kg" type="number" min="0" max="100000" step="0.01" required placeholder="Ex.: 2,50"></div>
                                    <p class="campo-erro" id="msgPesagem" role="status" hidden></p>
                                    <div class="config-form-acoes"><button type="submit" class="btn-secundario"><i class="bi bi-check-lg"></i> Registrar total</button></div>
                                </form>
                                <p class="config-nota">No Wokwi: use o token de uma balança, escolha a refeição no firmware, faça a tara com o recipiente vazio e pressione Encerrar turno após pesar.</p>
                            </div>
                            <form class="config-form form-dispositivo" id="formDispositivo" autocomplete="off" hidden>
                                <div class="campo">
                                    <label for="dispNome">Nome</label>
                                    <input type="text" id="dispNome" name="nome" maxlength="150" required placeholder="Ex.: Catraca · Entrada Principal">
                                </div>
                                <div class="campo">
                                    <label for="dispTipo">Tipo</label>
                                    <select id="dispTipo" name="tipo">
                                        <?php foreach (Dispositivo::TIPOS as $valor => $rotulo): ?>
                                            <option value="<?= htmlspecialchars($valor) ?>"><?= htmlspecialchars($rotulo) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="campo campo-largo">
                                    <label for="dispLocal">Localização (opcional)</label>
                                    <input type="text" id="dispLocal" name="localizacao" maxlength="150" placeholder="Ex.: Portaria principal">
                                </div>
                                <p class="campo-erro" id="msgDispositivo" hidden></p>
                                <div class="config-form-acoes">
                                    <button type="button" class="btn-secundario" id="btnCancelarDispositivo">Cancelar</button>
                                    <button type="submit" class="btn-primario"><i class="bi bi-plus-lg"></i> Criar dispositivo</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p class="config-nota">Somente o administrador da empresa pode adicionar dispositivos e gerar tokens.</p>
                        <?php endif; ?>
                    </section>

                    <!-- ---------- SEGURANÇA ---------- -->
                    <section class="config-painel" data-aba-painel="seguranca">
                        <h2>Segurança</h2>

                        <div class="config-bloco">
                            <h3>Alterar senha</h3>
                            <form class="config-form" id="formSenha" autocomplete="off">
                                <div class="campo">
                                    <label for="senhaAtual">Senha atual</label>
                                    <input type="password" id="senhaAtual" name="senha_atual" autocomplete="current-password" required>
                                </div>
                                <div class="campo">
                                    <label for="senhaNova">Nova senha</label>
                                    <input type="password" id="senhaNova" name="senha_nova" autocomplete="new-password" minlength="8" maxlength="72" required>
                                </div>
                                <div class="campo">
                                    <label for="senhaConfirmar">Confirmar nova senha</label>
                                    <input type="password" id="senhaConfirmar" name="senha_confirmar" autocomplete="new-password" minlength="8" maxlength="72" required>
                                </div>
                                <p class="campo-erro" id="erroSenha" hidden></p>
                                <div class="config-form-acoes">
                                    <button type="submit" class="btn-primario">
                                        <i class="bi bi-shield-check"></i> Atualizar senha
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Verificação em duas etapas: o JS (configuracoes.js) troca as partes conforme o "estado" que o servidor devolve -->
                        <div class="config-bloco dfa" id="bloco2fa" data-estado="<?= htmlspecialchars(json_encode($estado2fa, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>">
                            <div class="dfa-topo">
                                <div class="dfa-escudo" id="dfaEscudo"><i class="bi bi-shield-lock"></i></div>
                                <div>
                                    <h3>Verificação em duas etapas
                                        <span class="dfa-status" id="dfaStatus"><?= $estado2fa['ativa'] ? 'Ativada' : 'Desativada' ?></span>
                                    </h3>
                                    <p class="config-bloco-desc">Além da senha, o login no site e no aplicativo pede um código de 6 dígitos. Mesmo que alguém descubra a sua senha, sem o código não entra.</p>
                                </div>
                            </div>

                            <?php if (!$estado2fa['disponivel']): ?>
                                <p class="config-nota">A verificação em duas etapas ainda não está disponível neste servidor.</p>
                            <?php else: ?>

                            <!-- Escolhido no cadastro da empresa e ainda não configurado -->
                            <div class="dfa-convite" id="dfaPendente" hidden>
                                <i class="bi bi-stars"></i>
                                <div>
                                    <strong>Falta só um passo</strong>
                                    <p>No cadastro você pediu a verificação em duas etapas <span id="dfaPendenteMetodo"></span>. Conclua abaixo: leva 1 minuto.</p>
                                </div>
                                <button type="button" class="btn-texto dfa-agora-nao" id="btnDfaDispensar">Agora não</button>
                            </div>

                            <!-- Desativada: escolher o método -->
                            <div class="dfa-metodos" id="dfaInativa" hidden>
                                <div class="dfa-metodo" data-metodo="app">
                                    <i class="bi bi-phone"></i>
                                    <div>
                                        <strong>Aplicativo autenticador <span class="tag-atual">Recomendado</span></strong>
                                        <p>Google Authenticator, Microsoft Authenticator, Authy ou outro. O código aparece no celular, mesmo sem internet.</p>
                                    </div>
                                    <button type="button" class="btn-primario" data-dfa-iniciar="app"><i class="bi bi-qr-code"></i> Configurar</button>
                                </div>
                                <div class="dfa-metodo" data-metodo="email">
                                    <i class="bi bi-envelope"></i>
                                    <div>
                                        <strong>Código por e-mail</strong>
                                        <p>A cada login enviamos um código para <b id="dfaEmail"><?= htmlspecialchars($estado2fa['email']) ?></b>.</p>
                                        <p class="dfa-indisponivel" id="dfaEmailIndisponivel" hidden>O envio de e-mails não está configurado neste servidor.</p>
                                    </div>
                                    <button type="button" class="btn-secundario" data-dfa-iniciar="email"><i class="bi bi-send"></i> Configurar</button>
                                </div>
                            </div>

                            <!-- Configurar o aplicativo: QR Code + código + senha -->
                            <div class="dfa-config" id="dfaConfigApp" hidden>
                                <ol class="dfa-passos">
                                    <li>
                                        <strong>Abra o aplicativo autenticador</strong> no celular (se não tiver, instale o Google Authenticator ou o Microsoft Authenticator na loja do celular).
                                    </li>
                                    <li>
                                        <strong>Escaneie o QR Code</strong> (no aplicativo: "+" ou "Adicionar conta").
                                        <div class="dfa-qr-linha">
                                            <div class="dfa-qr" id="dfaQr"></div>
                                            <div class="dfa-chave">
                                                <span>Não consegue escanear? Digite esta chave no aplicativo:</span>
                                                <code id="dfaChave"></code>
                                                <small>Só tem letras de A a Z e números de 2 a 7 (não existe zero nem um: o "O" é letra).</small>
                                                <div class="dfa-chave-acoes">
                                                    <button type="button" class="btn-secundario" id="btnDfaCopiarChave"><i class="bi bi-clipboard"></i> Copiar chave</button>
                                                    <a class="btn-secundario dfa-abrir-app" id="dfaAbrirApp" href="#"><i class="bi bi-box-arrow-up-right"></i> Abrir no aplicativo</a>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                    <li><strong>Digite o código de 6 dígitos</strong> que aparecer no aplicativo e confirme com a sua senha.</li>
                                </ol>
                                <form class="config-form dfa-form" data-metodo="app" autocomplete="off">
                                    <div class="campo">
                                        <label for="dfaCodigoApp">Código do aplicativo</label>
                                        <input type="text" id="dfaCodigoApp" name="codigo" class="dfa-codigo" inputmode="numeric"
                                            autocomplete="one-time-code" maxlength="7" placeholder="000000" required>
                                    </div>
                                    <div class="campo">
                                        <label for="dfaSenhaApp">Senha atual</label>
                                        <input type="password" id="dfaSenhaApp" name="senha_atual" autocomplete="current-password" required>
                                    </div>
                                    <p class="campo-erro" hidden></p>
                                    <div class="config-form-acoes">
                                        <button type="button" class="btn-secundario" data-dfa-cancelar>Cancelar</button>
                                        <button type="submit" class="btn-primario"><i class="bi bi-shield-check"></i> Ativar</button>
                                    </div>
                                </form>
                            </div>

                            <!-- Configurar por e-mail: código enviado + senha -->
                            <div class="dfa-config" id="dfaConfigEmail" hidden>
                                <p class="dfa-enviado" id="dfaEnviado"></p>
                                <form class="config-form dfa-form" data-metodo="email" autocomplete="off">
                                    <div class="campo">
                                        <label for="dfaCodigoEmail">Código recebido por e-mail</label>
                                        <input type="text" id="dfaCodigoEmail" name="codigo" class="dfa-codigo" inputmode="numeric"
                                            autocomplete="one-time-code" maxlength="7" placeholder="000000" required>
                                    </div>
                                    <div class="campo">
                                        <label for="dfaSenhaEmail">Senha atual</label>
                                        <input type="password" id="dfaSenhaEmail" name="senha_atual" autocomplete="current-password" required>
                                    </div>
                                    <p class="campo-erro" hidden></p>
                                    <div class="config-form-acoes">
                                        <button type="button" class="btn-texto dfa-reenviar" data-dfa-iniciar="email">Reenviar código</button>
                                        <button type="button" class="btn-secundario" data-dfa-cancelar>Cancelar</button>
                                        <button type="submit" class="btn-primario"><i class="bi bi-shield-check"></i> Ativar</button>
                                    </div>
                                </form>
                            </div>

                            <!-- Ativada -->
                            <div class="dfa-ativa" id="dfaAtiva" hidden>
                                <div class="dfa-resumo">
                                    <div><span>Método</span><strong id="dfaMetodo"></strong></div>
                                    <div><span>Ativada em</span><strong id="dfaAtivadaEm"></strong></div>
                                    <div><span>Códigos de recuperação</span><strong id="dfaRestantes"></strong></div>
                                </div>
                                <p class="dfa-alerta-codigos" id="dfaPoucosCodigos" hidden><i class="bi bi-exclamation-triangle"></i>
                                    Restam poucos códigos de recuperação. Gere novos para não ficar sem saída se perder o celular.</p>
                                <div class="dfa-acoes">
                                    <button type="button" class="btn-secundario" data-dfa-senha="codigos"><i class="bi bi-arrow-repeat"></i> Gerar novos códigos de recuperação</button>
                                    <button type="button" class="btn-texto" data-dfa-senha="desativar">Desativar</button>
                                </div>
                                <form class="config-form dfa-form-senha" id="dfaFormSenha" autocomplete="off" hidden>
                                    <div class="campo campo-largo">
                                        <label for="dfaSenhaConfirmar" id="dfaSenhaRotulo">Confirme com a sua senha atual</label>
                                        <input type="password" id="dfaSenhaConfirmar" name="senha_atual" autocomplete="current-password" required>
                                    </div>
                                    <p class="campo-erro" hidden></p>
                                    <div class="config-form-acoes">
                                        <button type="button" class="btn-secundario" data-dfa-cancelar>Cancelar</button>
                                        <button type="submit" class="btn-primario" id="dfaSenhaBotao">Confirmar</button>
                                    </div>
                                </form>
                            </div>

                            <!-- Códigos de recuperação: aparecem uma única vez -->
                            <div class="dfa-codigos" id="dfaCodigos" role="status" hidden>
                                <strong><i class="bi bi-key"></i> Guarde os seus códigos de recuperação</strong>
                                <p>Se perder o celular (ou o acesso ao e-mail), entre com um destes códigos no lugar do código de 6 dígitos.
                                    Cada um vale <b>uma vez</b>. Por segurança, eles <b>não serão mostrados de novo</b>: copie, baixe ou imprima agora.</p>
                                <ol class="dfa-codigos-lista" id="dfaCodigosLista"></ol>
                                <div class="dfa-chave-acoes">
                                    <button type="button" class="btn-secundario" id="btnDfaCopiarCodigos"><i class="bi bi-clipboard"></i> Copiar</button>
                                    <button type="button" class="btn-secundario" id="btnDfaBaixarCodigos"><i class="bi bi-download"></i> Baixar .txt</button>
                                    <button type="button" class="btn-primario" id="btnDfaGuardei"><i class="bi bi-check-lg"></i> Já guardei</button>
                                </div>
                            </div>

                            <p class="campo-erro dfa-msg" id="msg2fa" hidden></p>
                            <?php endif; ?>
                        </div>

                        <div class="config-bloco">
                            <h3>Sessões ativas</h3>
                            <?php if ($sessoesAtivas === null): ?>
                                <p class="config-nota">O controle de sessões ainda não está disponível neste servidor.</p>
                            <?php else: ?>
                                <div class="lista-sessoes" id="listaSessoes">
                                    <?php foreach ($sessoesAtivas as $sessao): ?>
                                        <div class="item-sessao" data-id="<?= (int) $sessao['id'] ?>">
                                            <i class="bi <?= htmlspecialchars($sessao['icone']) ?>"></i>
                                            <div class="item-sessao-info">
                                                <strong><?= htmlspecialchars($sessao['dispositivo']) ?>
                                                    <?php if ($sessao['atual']): ?><span class="tag-atual">Esta sessão</span><?php endif; ?>
                                                </strong>
                                                <span>IP <?= htmlspecialchars($sessao['ip']) ?> · <?= htmlspecialchars($sessao['ultimo_acesso']) ?></span>
                                            </div>
                                            <?php if (!$sessao['atual']): ?>
                                                <button type="button" class="btn-texto btn-encerrar-sessao">Encerrar</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn-secundario config-btn-adicionar" id="btnEncerrarOutras"
                                    <?= count($sessoesAtivas) > 1 ? '' : 'hidden' ?>>
                                    <i class="bi bi-box-arrow-right"></i> Encerrar todas as outras sessões
                                </button>
                                <p class="campo-erro" id="msgSessoes" hidden></p>
                                <p class="config-nota">Mostra os acessos pelo site (navegador). Quem sai e entra de novo aparece como uma sessão nova; sessões paradas há muito tempo somem sozinhas.</p>
                            <?php endif; ?>
                        </div>
                    </section>

                </div>
            </div>

        </div>
    </div>

    <script src="/js/configuracoes.js"></script>
</body>

</html>