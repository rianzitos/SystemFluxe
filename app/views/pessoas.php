<?php
// Bootstrap próprio: garante que esta página seja protegida mesmo que
// alguém tente acessá-la diretamente pela URL, sem passar pelo roteador
// em app/routes/web.php (ex: fluxeteam.com.br/app/views/pessoas.php).
require_once __DIR__ . '/../../config/app.php';

// Bloqueia o acesso e redireciona para /login se não houver sessão ativa.
AuthMiddleware::autenticado();

// -----------------------------------------------------------------------
// DADOS DA PÁGINA DE PESSOAS — quem está no local agora, vindo do banco
// (último registro autorizado de hoje é uma entrada). Veja Acesso::presentesAgora().
// -----------------------------------------------------------------------

$paginaAtual = 'pessoas';

$painel = new Painel(Pagina::empresaId());
$dadosPessoas = $painel->pessoas();

$cardsResumo = $dadosPessoas['cardsResumo'];
$grupos = $dadosPessoas['grupos'];
$notificacoesNaoLidas = Notificacoes::contar($painel->totalAlertas(), Pagina::empresaId());

// Itens do menu lateral: rota, ícone (chave) e rótulo.
$menu = Pagina::menu();

// Badge do sino: quantidade de alertas ativos (calculados com dados reais)
$notificacoesNaoLidas = $notificacoesNaoLidas ?? 0;

// Aba Cadastro: todas as pessoas da empresa com os cartões delas. Só o administrador altera (o servidor confere de novo).
$empresaId = Pagina::empresaId();
$ehAdmin = ($_SESSION['usuario_perfil'] ?? '') === 'admin' && $empresaId > 0;
$cadastro = (new Pessoa())->listar($empresaId);
$presentesIds = array_flip(array_column((new Acesso())->presentesAgora($empresaId, date('Y-m-d')), 'id'));
$semCartaoAtivo = count(array_filter($cadastro, fn($p) => $p['ativo'] && !array_filter($p['cartoes'], fn($c) => $c['ativo'])));
$totalAtivas = count(array_filter($cadastro, fn($p) => $p['ativo']));

$nomeUsuario = $_SESSION['usuario_nome'] ?? 'Usuário';
$perfilUsuario = $_SESSION['usuario_perfil'] ?? '—';
$fotoPerfil = $_SESSION['usuario_foto'] ?? null;

// Cores dos avatares da lista de pessoas, alternadas por índice pra dar
// variedade visual sem sair da paleta da marca (roxo, preto, âmbar).
$coresAvatar = ['avatar-cor-1', 'avatar-cor-2', 'avatar-cor-3'];

// Função pequena só pra deixar o HTML dos ícones mais limpo lá embaixo.
function icone(string $nome): string
{
    $icones = [
        'grid' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
        'link' => '<path d="M9 12h6M8 7h1a4 4 0 0 1 0 8H8M16 17h-1a4 4 0 0 1 0-8h1"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M2 20c0-3.3 3-6 7-6s7 2.7 7 6M16 8a3 3 0 1 1 0-6M22 20c0-2.7-2-5-5-5.5"/>',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="2"/><path d="M9 4V3a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v1M9 12l2 2 4-4"/>',
        'meal' => '<path d="M6 2v8a2 2 0 0 0 4 0V2M8 10v12M17 2c-1.7 0-3 2.2-3 5s1.3 5 3 5v10"/>',
        'file' => '<path d="M7 2h7l5 5v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/><path d="M14 2v5h5"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'bell' => '<path d="M6 8a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 12 6 8Z"/><path d="M10 18a2 2 0 0 0 4 0"/>',
        'badge' => '<circle cx="12" cy="9" r="3.5"/><path d="M6 21c0-3 2.7-5 6-5s6 2 6 5"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
    ];
    return $icones[$nome] ?? '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pessoas · SICAPDA</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(CsrfMiddleware::token()) ?>">

    <!-- Aplica o tema salvo (claro/escuro/sistema) antes do CSS carregar, pra evitar
         o "flash" da tela clara antes de escurecer. Mesmo bloco de painel.php e
         configuracoes.php — é o que faz a preferência escolhida em Configurações
         valer aqui também. -->
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
    <link rel="stylesheet" href="/css/pessoas.css">
    <link rel="shortcut icon" href="/img/logo_fluxe.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
</head>

<body>

    <div class="layout">

        <!-- ===================== SIDEBAR ===================== -->
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
                    <h1>Pessoas</h1>
                    <p id="dataHoje">Carregando data…</p>
                </div>

                <div class="cabecalho-acoes">
                    <button class="sino" id="btnNotificacoes" aria-label="Notificações">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round"><?= icone('bell') ?></svg>
                        <?php if ($notificacoesNaoLidas > 0): ?>
                            <span class="badge-sino"><?= (int) $notificacoesNaoLidas ?></span>
                        <?php endif; ?>
                    </button>
                </div>
            </header>

            <!-- Abas -->
            <nav class="pessoas-abas" id="pessoasAbas">
                <button type="button" class="pessoas-aba ativa" data-aba="presentes">
                    <i class="bi bi-person-check"></i> Presentes agora
                </button>
                <button type="button" class="pessoas-aba" data-aba="cadastro">
                    <i class="bi bi-person-vcard"></i> Cadastro <span class="pessoas-aba-contagem"><?= count($cadastro) ?></span>
                </button>
            </nav>

            <!-- ===================== ABA PRESENTES AGORA ===================== -->
            <div id="painelPresentes" data-aba-painel="presentes">

                <!-- Cards de resumo -->
                <section class="cards-resumo cards-resumo-4">
                    <?php foreach ($cardsResumo as $card): ?>
                        <div class="card">
                            <div class="card-icone card-icone-<?= htmlspecialchars($card['chave']) ?>">
                                <i class="iconeCard bi <?= htmlspecialchars($card['icone']) ?>"></i>
                            </div>
                            <p class="card-label"><?= htmlspecialchars($card['label']) ?></p>
                            <p class="card-valor"><?= number_format($card['valor'], 0, ',', '.') ?></p>
                            <p class="card-extra"><?= htmlspecialchars($card['extra']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </section>

                <!-- Busca -->
                <div class="busca-pessoas">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round"><?= icone('search') ?></svg>
                    <input type="text" id="buscaPessoas" placeholder="Buscar por nome ou função...">
                </div>

                <!-- Grupos de pessoas -->
                <?php foreach ($grupos as $grupo): ?>
                    <section class="painel-card grupo-pessoas">
                        <div class="grupo-cabecalho">
                            <div class="grupo-titulo">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"><?= icone($grupo['icone']) ?></svg>
                                <?= htmlspecialchars($grupo['titulo']) ?>
                            </div>
                            <span class="badge-presentes"><?= (int) $grupo['presentes'] ?> presentes</span>
                        </div>

                        <div class="lista-pessoas">
                            <?php foreach ($grupo['pessoas'] as $i => $pessoa): ?>
                                <div class="pessoa-item" data-nome="<?= htmlspecialchars(mb_strtolower($pessoa['nome'])) ?>"
                                    data-funcao="<?= htmlspecialchars(mb_strtolower($pessoa['funcao'])) ?>">
                                    <div class="pessoa-avatar <?= $coresAvatar[$i % count($coresAvatar)] ?>">
                                        <?= htmlspecialchars(mb_strtoupper(mb_substr($pessoa['nome'], 0, 1))) ?>
                                    </div>
                                    <div class="pessoa-info">
                                        <span class="pessoa-nome"><?= htmlspecialchars($pessoa['nome']) ?></span>
                                        <span class="pessoa-funcao">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round"><?= icone('clock') ?></svg>
                                            Entrada: <?= htmlspecialchars($pessoa['entrada']) ?>
                                        </span>
                                    </div>
                                    <span class="pessoa-status" title="Presente"></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($grupo['restantes'] > 0): ?>
                            <p class="grupo-rodape">+ <?= (int) $grupo['restantes'] ?> outros colaboradores presentes</p>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>

            </div>

            <!-- ===================== ABA CADASTRO ===================== -->
            <div id="painelCadastro" data-aba-painel="cadastro" hidden>

                <div class="cad-barra">
                    <div class="busca-pessoas cad-busca">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round"><?= icone('search') ?></svg>
                        <input type="text" id="buscaCadastro" placeholder="Buscar por nome, função, matrícula ou cartão...">
                    </div>
                    <?php if ($ehAdmin): ?>
                        <div class="cad-acoes">
                            <button type="button" class="btn-cad btn-cad-secundario" id="btnImportar">
                                <i class="bi bi-upload"></i> Importar planilha
                            </button>
                            <button type="button" class="btn-cad btn-cad-primario" id="btnNovaPessoa">
                                <i class="bi bi-plus-lg"></i> Adicionar pessoa
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <p class="cad-resumo">
                    <strong><?= (int) $totalAtivas ?></strong> pessoas ativas
                    · <strong><?= (int) $semCartaoAtivo ?></strong> sem cartão ativo
                    <?php if (!$ehAdmin): ?> · Somente o administrador da empresa altera o cadastro.<?php endif; ?>
                </p>

                <section class="painel-card">
                    <div class="cad-lista" id="listaCadastro">
                        <?php foreach ($cadastro as $i => $pessoa): ?>
                            <?php
                            $uids = array_column($pessoa['cartoes'], 'uid');
                            $busca = mb_strtolower($pessoa['nome'] . ' ' . $pessoa['cargo'] . ' ' . ($pessoa['matricula'] ?? '') . ' ' . implode(' ', $uids));
                            ?>
                            <div class="cad-item <?= $pessoa['ativo'] ? '' : 'cad-item-inativo' ?>"
                                data-id="<?= (int) $pessoa['id'] ?>"
                                data-nome="<?= htmlspecialchars($pessoa['nome']) ?>"
                                data-cargo="<?= htmlspecialchars($pessoa['cargo']) ?>"
                                data-categoria="<?= htmlspecialchars($pessoa['categoria']) ?>"
                                data-matricula="<?= htmlspecialchars($pessoa['matricula'] ?? '') ?>"
                                data-ativo="<?= $pessoa['ativo'] ? '1' : '0' ?>"
                                data-busca="<?= htmlspecialchars($busca) ?>">
                                <div class="pessoa-avatar <?= $coresAvatar[$i % count($coresAvatar)] ?>">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($pessoa['nome'], 0, 1))) ?>
                                </div>

                                <div class="cad-info">
                                    <div class="cad-nome">
                                        <?= htmlspecialchars($pessoa['nome']) ?>
                                        <?php if (!$pessoa['ativo']): ?><span class="tag-status tag-inativa">Desativada</span>
                                        <?php elseif (isset($presentesIds[$pessoa['id']])): ?><span class="tag-status tag-presente">Presente agora</span><?php endif; ?>
                                    </div>
                                    <div class="cad-detalhe">
                                        <?= htmlspecialchars($pessoa['cargo']) ?>
                                        · <?= htmlspecialchars(Acesso::CATEGORIAS[$pessoa['categoria']] ?? $pessoa['categoria']) ?>
                                        <?php if ($pessoa['matricula']): ?>· Matrícula <?= htmlspecialchars($pessoa['matricula']) ?><?php endif; ?>
                                    </div>
                                    <div class="cad-cartoes">
                                        <?php foreach ($pessoa['cartoes'] as $cartao): ?>
                                            <span class="cartao-chip <?= $cartao['ativo'] ? '' : 'cartao-chip-inativo' ?>"
                                                data-cartao="<?= (int) $cartao['id'] ?>" data-ativo="<?= $cartao['ativo'] ? '1' : '0' ?>"
                                                title="<?= $cartao['ativo'] ? 'Cartão ativo' : 'Cartão desativado' ?>">
                                                <i class="bi bi-credit-card-2-front"></i>
                                                <code><?= htmlspecialchars($cartao['uid']) ?></code>
                                                <?php if ($ehAdmin): ?>
                                                    <button type="button" class="chip-acao chip-ativo" title="<?= $cartao['ativo'] ? 'Desativar cartão' : 'Ativar cartão' ?>">
                                                        <i class="bi <?= $cartao['ativo'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i>
                                                    </button>
                                                    <button type="button" class="chip-acao chip-remover" title="Remover cartão">
                                                        <i class="bi bi-x-lg"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (!$pessoa['cartoes']): ?><span class="sem-cartao">Sem cartão</span><?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($ehAdmin): ?>
                                    <div class="cad-botoes">
                                        <button type="button" class="btn-cad btn-cad-secundario btn-vincular"><i class="bi bi-credit-card-2-front"></i> Vincular cartão</button>
                                        <button type="button" class="btn-cad btn-cad-texto btn-editar">Editar</button>
                                        <button type="button" class="btn-cad btn-cad-texto <?= $pessoa['ativo'] ? 'btn-perigo' : '' ?> btn-ativo">
                                            <?= $pessoa['ativo'] ? 'Desativar' : 'Reativar' ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <p class="cad-vazio" id="cadVazio" <?= $cadastro ? 'hidden' : '' ?>>
                            <?= $cadastro ? 'Nenhuma pessoa encontrada.' : 'Nenhuma pessoa cadastrada ainda. Use “Adicionar pessoa” ou “Importar planilha”.' ?>
                        </p>
                    </div>
                </section>
            </div>

        </div>
    </div>


    <div class="aviso-pessoas" id="avisoPessoas" role="status" hidden></div>

    <?php if ($ehAdmin): ?>
        <!-- Adicionar / editar pessoa -->
        <dialog class="dlg" id="dlgPessoa" aria-labelledby="dlgPessoaTitulo">
            <form id="formPessoa" autocomplete="off">
                <h2 id="dlgPessoaTitulo">Adicionar pessoa</h2>
                <input type="hidden" name="id" id="pessoaId">
                <div class="dlg-campos">
                    <label class="dlg-campo dlg-largo">Nome
                        <input type="text" name="nome" id="pessoaNome" maxlength="150" required>
                    </label>
                    <label class="dlg-campo">Cargo ou função
                        <input type="text" name="cargo" id="pessoaCargo" maxlength="100" required>
                    </label>
                    <label class="dlg-campo">Categoria
                        <select name="categoria" id="pessoaCategoria">
                            <?php foreach (Acesso::CATEGORIAS as $valor => $rotulo): ?>
                                <option value="<?= htmlspecialchars($valor) ?>"><?= htmlspecialchars($rotulo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="dlg-campo dlg-largo">Matrícula <span class="dlg-opcional">(opcional)</span>
                        <input type="text" name="matricula" id="pessoaMatricula" maxlength="30">
                    </label>

                    <div class="dlg-campo dlg-largo" id="blocoCartaoNovo">
                        <label for="pessoaUid">Cartão <span class="dlg-opcional">(opcional: também dá para vincular depois)</span></label>
                        <input type="text" name="uid" id="pessoaUid" maxlength="100" data-uid-input placeholder="UID do cartão, ex.: A1B2C3D4">
                        <div class="leituras" data-leituras>
                            <p class="leituras-titulo">Cartões lidos nas catracas e ainda sem dono <span class="leituras-status"></span></p>
                            <div class="leituras-lista"></div>
                        </div>
                    </div>
                </div>
                <p class="dlg-erro" id="msgPessoa" hidden></p>
                <div class="dlg-acoes">
                    <button type="button" class="btn-cad btn-cad-secundario" data-fechar>Cancelar</button>
                    <button type="submit" class="btn-cad btn-cad-primario">Salvar</button>
                </div>
            </form>
        </dialog>

        <!-- Vincular cartão -->
        <dialog class="dlg" id="dlgCartao" aria-labelledby="dlgCartaoTitulo">
            <form id="formCartao" autocomplete="off">
                <h2 id="dlgCartaoTitulo">Vincular cartão</h2>
                <p class="dlg-texto">Para <strong id="dlgCartaoPessoa"></strong></p>
                <input type="hidden" name="funcionario_id" id="cartaoPessoaId">
                <div class="dlg-campos">
                    <div class="dlg-campo dlg-largo">
                        <div class="leituras" data-leituras>
                            <p class="leituras-titulo">Passe o cartão numa catraca e ele aparece aqui <span class="leituras-status"></span></p>
                            <div class="leituras-lista"></div>
                        </div>
                    </div>
                    <label class="dlg-campo dlg-largo">UID do cartão
                        <input type="text" name="uid" id="cartaoUid" maxlength="100" required data-uid-input
                            placeholder="Escolha acima ou digite, ex.: A1B2C3D4">
                    </label>
                </div>
                <p class="dlg-erro" id="msgCartao" hidden></p>
                <div class="dlg-acoes">
                    <button type="button" class="btn-cad btn-cad-secundario" data-fechar>Cancelar</button>
                    <button type="submit" class="btn-cad btn-cad-primario">Vincular</button>
                </div>
            </form>
        </dialog>

        <!-- Importar planilha -->
        <dialog class="dlg" id="dlgImportar" aria-labelledby="dlgImportarTitulo">
            <form id="formImportar">
                <h2 id="dlgImportarTitulo">Importar planilha</h2>
                <p class="dlg-texto">Envie um arquivo <strong>.csv</strong> (do Excel: <em>Salvar como → CSV</em>) com uma pessoa por linha.
                    Colunas: <code>nome</code> e <code>cargo</code> (obrigatórias), <code>categoria</code>
                    (operador, supervisor ou prestador), <code>matricula</code> e <code>cartao</code> (o UID).
                    <a href="/pessoas/modelo.csv" download>Baixar o modelo</a>.</p>
                <p class="dlg-texto">Linhas com problema são ignoradas e explicadas no final; o resto é importado. Importar o mesmo
                    arquivo duas vezes não duplica ninguém.</p>
                <label class="dlg-campo dlg-largo">Arquivo
                    <input type="file" name="arquivo" id="arquivoImportar" accept=".csv,text/csv,text/plain" required>
                </label>
                <p class="dlg-erro" id="msgImportar" hidden></p>
                <div class="importacao-resultado" id="resultadoImportacao" role="status" hidden></div>
                <div class="dlg-acoes">
                    <button type="button" class="btn-cad btn-cad-secundario" data-fechar id="btnFecharImportar">Cancelar</button>
                    <button type="submit" class="btn-cad btn-cad-primario" id="btnEnviarImportar">Importar</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>

    <script src="/js/pessoas.js"></script>
</body>

</html>