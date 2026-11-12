<?php

/**
 * API JSON consumida pelo aplicativo Flutter (fluxe_app).
 *
 * Usa os mesmos models e o mesmo banco MySQL da versão web, sempre filtrando
 * pela empresa do usuário autenticado. Autenticação por Bearer token
 * (ApiAuthMiddleware) em vez de sessão/cookie. Responde CORS (Api::cors) para o app web
 * funcionar também quando aberto em outro endereço.
 *
 * Rotas (todas sob /api):
 *   POST /api/login            {email, senha}
 *   GET  /api/me
 *   GET  /api/painel
 *   GET  /api/analise-mensal   ?mes=YYYY-MM
 *   GET  /api/pessoas                  quem está no local agora
 *   GET  /api/relatorios       ?de&ate&busca&categoria&pagina
 *   GET  /api/relatorios/exportar  (CSV, mesmos filtros)
 *   GET  /api/assistente
 *   POST /api/assistente       {mensagem}
 *
 * Cadastro de pessoas e cartões (aba Cadastro de Pessoas no app; as mesmas regras do site, em CadastroPessoas):
 *   GET  /api/pessoas/cadastro         pessoas da empresa com os cartões de cada uma (qualquer usuário; "podeEditar" = é administrador)
 * Só o administrador da empresa (perfil "admin") usa as demais; os outros recebem 403:
 *   GET  /api/pessoas/leituras         cartões lidos nas catracas e ainda sem dono (o app consulta de 3 em 3 s ao vincular)
 *   POST /api/pessoas/criar            {nome, cargo, categoria, matricula?, uid?}  (uid vincula o cartão junto)
 *   POST /api/pessoas/editar           {id, nome, cargo, categoria, matricula?}
 *   POST /api/pessoas/ativo            {id, ativo}
 *   POST /api/pessoas/cartao           {funcionario_id, uid}
 *   POST /api/pessoas/cartao/ativo     {id, ativo}
 *   POST /api/pessoas/cartao/remover   {id}
 *   POST /api/pessoas/importar         {csv}  planilha (texto do CSV; mesmas regras do site)
 *   GET  /api/pessoas/modelo           modelo da planilha (CSV, qualquer usuário)
 *
 * Configurações (mesmas regras do site, em Conta, Preferencia, Sessao e Integracoes):
 *   POST /api/perfil                   {nome, email, cargo, senha_atual?}  (trocar o e-mail exige a senha atual)
 *   POST /api/perfil/senha             {senha_atual, senha_nova, senha_confirmar}
 *   POST /api/perfil/foto              {imagem}  JPG ou PNG em base64, até 5 MB
 *   GET  /api/preferencias             notificações (catálogo + ligado/desligado) e sistema (período e itens dos Relatórios)
 *   POST /api/preferencias/notificacao {chave, ativo}
 *   POST /api/preferencias/sistema     {periodo_padrao, itens_pagina}
 *   GET  /api/sessoes                  sessões abertas no site (navegador) com esta conta
 *   POST /api/sessoes/encerrar         {id} ou {todas: true}
 *   GET  /api/notificacoes             alertas do sino (previsão + catracas, conforme as preferências), com o destino de cada um
 *   GET  /api/aovivo           ?ultimo=ID  o que mudou na empresa (para as telas se atualizarem sozinhas; ver AoVivo)
 *   GET  /api/dispositivos             dispositivos IoT (token mascarado, online/offline) e o desperdício de hoje (qualquer usuário)
 * Só o administrador:
 *   POST /api/dispositivos/criar       {nome, tipo, localizacao?}  devolve o token inteiro (única vez)
 *   POST /api/dispositivos/token       {id}  gera outro token
 *   POST /api/dispositivos/simular     {id, uid}  passa um cartão pela catraca (registra um acesso de verdade)
 *   POST /api/dispositivos/pesagem     {id, tipo, desperdicio_kg, data?}  total de desperdício da refeição
 *
 * As escritas respondem {sucesso, mensagem, ...}; erro de regra vem como {sucesso: false, mensagem} com 403, 404, 409 ou 422.
 * Sem CSRF de propósito: a API autentica por Bearer token, nunca por cookie (veja Api::cors()).
 */
class ApiController
{
    public function despachar(string $uri, string $metodo): never
    {
        Api::cors();
        if ($metodo === 'OPTIONS') {
            // Pré-verificação do navegador (CORS): só confirma que pode enviar a requisição de verdade.
            http_response_code(204);
            exit;
        }

        $rota = '/' . trim(substr($uri, strlen('/api')), '/');

        if ($rota === '/login') {
            $metodo === 'POST' ? $this->login() : Api::erro(405, 'Método não permitido.');
        }

        $usuario = ApiAuthMiddleware::autenticar();
        $empresaId = (int) $usuario['empresa_id'];

        try {
            switch ($rota) {
                case '/me':
                    $this->me($usuario);
                case '/painel':
                    $this->painel($usuario);
                case '/analise-mensal':
                    $this->analiseMensal($empresaId);
                case '/pessoas':
                    $this->pessoas($empresaId);
                case '/pessoas/cadastro':
                    $this->listarCadastro($usuario, $metodo);
                case '/pessoas/leituras':
                case '/pessoas/criar':
                case '/pessoas/editar':
                case '/pessoas/ativo':
                case '/pessoas/cartao':
                case '/pessoas/cartao/ativo':
                case '/pessoas/cartao/remover':
                case '/pessoas/importar':
                    $this->gerirCadastro($usuario, $rota, $metodo);
                case '/pessoas/modelo':
                    $this->modeloPlanilha($metodo);
                case '/relatorios':
                    $this->relatorios($usuario);
                case '/relatorios/exportar':
                    (new RelatorioController())->enviarCsv($empresaId);
                case '/assistente':
                    $metodo === 'POST' ? $this->perguntar($empresaId) : $this->assistente($empresaId);
                case '/perfil':
                case '/perfil/senha':
                case '/perfil/foto':
                    $this->conta($usuario, $rota, $metodo);
                case '/preferencias':
                    $this->preferencias($usuario, $metodo);
                case '/preferencias/notificacao':
                case '/preferencias/sistema':
                    $this->salvarPreferencia($usuario, $rota, $metodo);
                case '/sessoes':
                    $this->sessoes($usuario, $metodo);
                case '/sessoes/encerrar':
                    $this->encerrarSessao($usuario, $metodo);
                case '/notificacoes':
                    $this->notificacoes($usuario, $metodo);
                case '/aovivo':
                    $this->aoVivo($empresaId, $metodo);
                case '/dispositivos':
                    $this->dispositivos($usuario, $metodo);
                case '/dispositivos/criar':
                case '/dispositivos/token':
                case '/dispositivos/simular':
                case '/dispositivos/pesagem':
                    $this->gerirDispositivos($usuario, $rota, $metodo);
            }
        } catch (Throwable $e) {
            error_log('SICAPDA API ' . $rota . ': ' . $e->getMessage());
            Api::erro(500, 'Não foi possível processar a solicitação.');
        }

        Api::erro(404, 'Rota não encontrada.');
    }

    // ─── Autenticação ────────────────────────────────────────────────────────

    private function login(): never
    {
        $corpo = Api::corpo();
        $email = trim((string) ($corpo['email'] ?? ''));
        $senha = (string) ($corpo['senha'] ?? '');

        if ($email === '' || $senha === '') {
            Api::erro(422, 'Preencha todos os campos.');
        }

        $usuario = (new Usuario())->buscarPorEmail($email);

        // Mesmo hash falso do login web: tempo de resposta igual com e-mail inexistente.
        $hash = $usuario['senha'] ?? '$2y$10$usesomesillystringforeusesomesillystringfore.Ou3n7RQYhXJm';
        $senhaOk = password_verify($senha, $hash);

        if (!$usuario || !$senhaOk) {
            Api::erro(401, 'E-mail ou senha inválidos.');
        }
        if (empty($usuario['empresa_id'])) {
            Api::erro(403, 'Usuário sem empresa vinculada.');
        }

        $token = ApiAuthMiddleware::emitir((int) $usuario['id']);
        $completo = (new Usuario())->buscarPorId((int) $usuario['id']);

        Api::json($token + ['usuario' => $this->usuarioParaJson($completo)]);
    }

    private function usuarioParaJson(array $u): array
    {
        $empresa = (new Empresa())->buscarPorId((int) $u['empresa_id']) ?: [];

        return [
            'id'        => (int) $u['id'],
            'nome'      => $u['nome'],
            'email'     => $u['email'],
            'perfil'    => $u['perfil'],
            'cargo'     => $u['cargo'] ?? null,
            'telefone'  => $u['telefone'] ?? null,
            'matricula' => $u['matricula'] ?? null,
            'foto'      => Api::urlPublica($u['foto'] ?? null),
            'empresa'   => [
                'id'   => (int) $u['empresa_id'],
                'nome' => $empresa['nome_fantasia'] ?? ($empresa['razao_social'] ?? ''),
                'cnpj' => $empresa['cnpj'] ?? null,
                'cidade' => $empresa['cidade'] ?? null,
                'estado' => $empresa['estado'] ?? null,
                'horario_funcionamento' => $empresa['horario_funcionamento'] ?? null,
            ],
        ];
    }

    private function me(array $usuario): never
    {
        Api::json(['usuario' => $this->usuarioParaJson($usuario)]);
    }

    // ─── Telas ───────────────────────────────────────────────────────────────

    private function painel(array $usuario): never
    {
        $empresaId = (int) $usuario['empresa_id'];
        $painel = new Painel($empresaId);
        $dados = $painel->painel();
        // Alertas de previsão + alertas das catracas, conforme as preferências de notificação da conta (igual ao site).
        $dados['alertas'] = Notificacoes::aplicar($dados['alertas'], $empresaId, (int) $usuario['id']);
        $dados['mesReferencia'] = Datas::mesAno(new DateTimeImmutable('today'));
        Api::json($dados);
    }

    private function analiseMensal(int $empresaId): never
    {
        $painel = new Painel($empresaId);
        $meses = $painel->mesesDisponiveis();

        $mes = (string) ($_GET['mes'] ?? '');
        if (!isset($meses[$mes])) {
            $mes = array_key_first($meses); // mês atual
        }

        $dados = $painel->analiseMensal($mes);
        $dados['mes'] = $mes;
        $dados['mesRotulo'] = Datas::mesAno($dados['inicio']);
        $dados['inicio'] = $dados['inicio']->format('Y-m-d');
        $dados['meses'] = array_map(
            fn($valor, $rotulo) => ['valor' => $valor, 'rotulo' => $rotulo],
            array_keys($meses),
            array_values($meses)
        );

        Api::json($dados);
    }

    private function pessoas(int $empresaId): never
    {
        $hoje = new DateTimeImmutable('today');
        $dados = (new Painel($empresaId))->pessoas();
        $dados['dataExtenso'] = Datas::porExtenso($hoje);
        Api::json($dados);
    }

    // ─── Cadastro de pessoas e cartões ───────────────────────────────────────

    private function ehAdmin(array $usuario): bool
    {
        return ($usuario['perfil'] ?? '') === 'admin';
    }

    /**
     * Todas as pessoas da empresa com os cartões de cada uma (ativas primeiro). Qualquer usuário vê; "podeEditar"
     * diz ao app se mostra os botões de alterar (o servidor confere o perfil de novo a cada escrita).
     */
    private function listarCadastro(array $usuario, string $metodo): never
    {
        if ($metodo !== 'GET') {
            Api::erro(405, 'Método não permitido.');
        }

        $empresaId = (int) $usuario['empresa_id'];
        $presentes = array_flip(array_column((new Acesso())->presentesAgora($empresaId, date('Y-m-d')), 'id'));

        $ativas = 0;
        $semCartaoAtivo = 0;
        $pessoas = [];
        foreach ((new Pessoa())->listar($empresaId) as $p) {
            if ($p['ativo']) {
                $ativas++;
                if (!array_filter($p['cartoes'], fn($c) => $c['ativo'])) {
                    $semCartaoAtivo++;
                }
            }
            $pessoas[] = $p + [
                'categoriaRotulo' => Acesso::CATEGORIAS[$p['categoria']] ?? $p['categoria'],
                'presente'        => isset($presentes[$p['id']]),
            ];
        }

        Api::json([
            'podeEditar' => $this->ehAdmin($usuario),
            'resumo'     => ['total' => count($pessoas), 'ativas' => $ativas, 'semCartaoAtivo' => $semCartaoAtivo],
            'categorias' => array_map(
                fn($valor, $rotulo) => ['valor' => $valor, 'rotulo' => $rotulo],
                array_keys(Acesso::CATEGORIAS),
                array_values(Acesso::CATEGORIAS)
            ),
            'pessoas'    => $pessoas,
        ]);
    }

    /** Escritas do cadastro e a lista de cartões lidos: só o administrador da empresa, com as regras de CadastroPessoas. */
    private function gerirCadastro(array $usuario, string $rota, string $metodo): never
    {
        if ($metodo !== ($rota === '/pessoas/leituras' ? 'GET' : 'POST')) {
            Api::erro(405, 'Método não permitido.');
        }
        if (!$this->ehAdmin($usuario)) {
            Api::erro(403, 'Somente o administrador da empresa pode gerenciar pessoas e cartões.');
        }

        $corpo = $metodo === 'POST' ? Api::corpo() : [];

        // Um corpo sem "ativo" desativaria a pessoa (ou o cartão) sem ninguém ter pedido: aqui o campo é obrigatório.
        if (in_array($rota, ['/pessoas/ativo', '/pessoas/cartao/ativo'], true)
            && !in_array($corpo['ativo'] ?? null, [true, false, 1, 0, '1', '0'], true)) {
            Api::erro(422, 'Informe se deve ficar ativo (ativo: true ou false).');
        }

        $empresaId = (int) $usuario['empresa_id'];
        $cadastro = new CadastroPessoas();

        try {
            $resultado = match ($rota) {
                '/pessoas/leituras'       => $cadastro->leituras($empresaId),
                '/pessoas/criar'          => $cadastro->criar($empresaId, $corpo),
                '/pessoas/editar'         => $cadastro->editar($empresaId, $corpo),
                '/pessoas/ativo'          => $cadastro->definirAtivo($empresaId, $corpo),
                '/pessoas/cartao'         => $cadastro->vincularCartao($empresaId, $corpo),
                '/pessoas/cartao/ativo'   => $cadastro->definirCartaoAtivo($empresaId, $corpo),
                '/pessoas/cartao/remover' => $cadastro->removerCartao($empresaId, $corpo),
                '/pessoas/importar'       => $cadastro->importar($empresaId, is_string($corpo['csv'] ?? null) ? $corpo['csv'] : ''),
            };
        } catch (CadastroException $e) {
            Api::erro($e->status(), $e->getMessage());
        }

        Api::json($resultado);
    }

    private function relatorios(array $usuario): never
    {
        $empresaId = (int) $usuario['empresa_id'];
        $acesso = new Acesso();
        // Período padrão e itens por página de Configurações → Sistema (os mesmos do site).
        $sistema = (new Preferencia())->obter((int) $usuario['id'])['sistema'];
        $f = (new RelatorioController())->filtros($sistema['periodo_padrao']);
        $porPagina = $sistema['itens_pagina'];

        $total   = $acesso->contarRegistros($empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria']);
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina  = min($f['pagina'], $paginas);

        $registros = $acesso->registros(
            $empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria'],
            $porPagina, ($pagina - 1) * $porPagina
        );
        $resumo = $acesso->resumoRegistros($empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria']);
        $hoje = date('Y-m-d');
        $hojeTotal = $acesso->contarRegistros($empresaId, $hoje, $hoje, $f['busca'], $f['categoria']);

        Api::json([
            'filtros'   => $f,
            'total'     => $total,
            'pagina'    => $pagina,
            'paginas'   => $paginas,
            'porPagina' => $porPagina,
            'periodoPadrao' => $sistema['periodo_padrao'],
            'cards'     => [
                'total'   => $total,
                'hoje'    => $hojeTotal,
                'duracao' => Datas::duracao($resumo['duracao_media_min']),
                'pico'    => $resumo['pico_hora'] === null
                    ? '—'
                    : sprintf('%02d:00 - %02d:00', $resumo['pico_hora'], $resumo['pico_hora'] + 1),
            ],
            'registros' => array_map(fn($r) => [
                'id'        => (int) $r['id'],
                'nome'      => $r['nome'],
                'cargo'     => $r['cargo'],
                'categoria' => $r['categoria'],
                'categoriaRotulo' => Acesso::CATEGORIAS[$r['categoria']] ?? $r['categoria'],
                'dia'       => $r['dia'],
                'entrada'   => $r['entrada'] ? date('H:i', strtotime($r['entrada'])) : null,
                'saida'     => $r['saida'] ? date('H:i', strtotime($r['saida'])) : null,
                'emAndamento' => (bool) $r['em_andamento'],
                'duracao'   => $r['duracao_min'] !== null ? Datas::duracao($r['duracao_min']) : null,
            ], $registros),
        ]);
    }

    /** Modelo da planilha de importação (o mesmo arquivo do site), como texto CSV. */
    private function modeloPlanilha(string $metodo): never
    {
        $this->exigirMetodo($metodo, 'GET');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modelo-importacao-pessoas.csv"');
        header('Cache-Control: no-store');
        echo CsvPessoas::modelo();
        exit;
    }

    // ─── Configurações: conta (perfil, senha e foto) ─────────────────────────

    private function conta(array $usuario, string $rota, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'POST');
        $id = (int) $usuario['id'];
        $corpo = Api::corpo();
        $conta = new Conta();

        try {
            $resultado = match ($rota) {
                '/perfil'       => $conta->atualizarPerfil($id, $corpo),
                '/perfil/senha' => $conta->atualizarSenha($id, $corpo),
                '/perfil/foto'  => $conta->salvarFotoBase64($id, $corpo['imagem'] ?? null),
            };
        } catch (CadastroException $e) {
            Api::erro($e->status(), $e->getMessage());
        }

        $extra = [];
        if ($rota !== '/perfil/senha') {
            // Devolve o perfil inteiro já atualizado: o app troca o que mostra sem pedir /me de novo.
            $extra['usuario'] = $this->usuarioParaJson((new Usuario())->buscarPorId($id) ?: $usuario);
        }
        Api::json(['mensagem' => $resultado['mensagem']] + $extra);
    }

    // ─── Configurações: preferências (notificações e sistema) ────────────────

    private function preferencias(array $usuario, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'GET');
        $id = (int) $usuario['id'];
        $preferencia = new Preferencia();

        Api::json([
            'notificacoes' => $preferencia->notificacoesDoUsuario($id),
            'sistema'      => $preferencia->obter($id)['sistema'],
            'opcoes'       => [
                'periodos'    => array_map(
                    fn($valor, $rotulo) => ['valor' => (string) $valor, 'rotulo' => $rotulo],
                    array_keys(Preferencia::PERIODOS_ROTULOS),
                    array_values(Preferencia::PERIODOS_ROTULOS)
                ),
                'itensPagina' => Preferencia::ITENS_PAGINA,
                'fuso'        => Preferencia::FUSO_ROTULO,
            ],
        ]);
    }

    private function salvarPreferencia(array $usuario, string $rota, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'POST');
        $id = (int) $usuario['id'];
        $empresaId = (int) $usuario['empresa_id'];
        $corpo = Api::corpo();
        $preferencia = new Preferencia();

        if ($rota === '/preferencias/notificacao') {
            $chave = $corpo['chave'] ?? '';
            if (!is_string($chave) || !in_array($chave, Preferencia::NOTIFICACOES, true)) {
                Api::erro(422, 'Notificação desconhecida.');
            }
            $ativo = $corpo['ativo'] ?? null;
            if (!in_array($ativo, [true, false, 1, 0, '1', '0'], true)) {
                Api::erro(422, 'Informe se deve ficar ligada (ativo: true ou false).');
            }
            $ativo = in_array($ativo, [true, 1, '1'], true);

            $preferencia->salvarNotificacao($id, $chave, $ativo);
            $sino = Notificacoes::contar((new Painel($empresaId))->totalAlertas(), $empresaId, $id);
            Api::json(['mensagem' => 'Preferência salva.', 'ativo' => $ativo, 'sino' => $sino]);
        }

        $periodo = $corpo['periodo_padrao'] ?? '';
        $itens = $corpo['itens_pagina'] ?? 0;
        $periodo = is_int($periodo) ? (string) $periodo : $periodo;
        $itens = is_string($itens) && ctype_digit($itens) ? (int) $itens : $itens;
        if (!is_string($periodo) || !in_array($periodo, Preferencia::PERIODOS, true)
            || !is_int($itens) || !in_array($itens, Preferencia::ITENS_PAGINA, true)) {
            Api::erro(422, 'Valor inválido. Escolha uma das opções da lista.');
        }

        $preferencia->salvarSistema($id, $periodo, $itens);
        Api::json(['mensagem' => 'Preferências salvas.', 'sistema' => ['periodo_padrao' => $periodo, 'itens_pagina' => $itens]]);
    }

    // ─── Configurações: sessões abertas no site ──────────────────────────────

    /**
     * Onde a conta está aberta no site (navegador). O aplicativo entra com token e não aparece aqui; por isso,
     * do app, nenhuma sessão é "a atual" e "encerrar todas" derruba todas as do site.
     */
    private function sessoes(array $usuario, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'GET');
        try {
            $sessoes = (new Sessao())->listar((int) $usuario['id']);
        } catch (PDOException $e) {
            error_log('SICAPDA API: sessoes_usuario indisponível (rode a migration 004): ' . $e->getMessage());
            Api::json(['disponivel' => false, 'sessoes' => []]);
        }

        Api::json(['disponivel' => true, 'sessoes' => array_map(
            fn($s) => [
                'id'            => $s['id'],
                'dispositivo'   => $s['dispositivo'],
                'celular'       => $s['icone'] === 'bi-phone',
                'ip'            => $s['ip'],
                'ultimo_acesso' => $s['ultimo_acesso'],
            ],
            $sessoes
        )]);
    }

    private function encerrarSessao(array $usuario, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'POST');
        $id = (int) $usuario['id'];
        $corpo = Api::corpo();
        $sessao = new Sessao();

        try {
            if (in_array($corpo['todas'] ?? null, [true, 1, '1'], true)) {
                $n = $sessao->encerrarOutras($id);
                Api::json(['mensagem' => $n === 1 ? '1 sessão encerrada.' : $n . ' sessões encerradas.', 'encerradas' => $n]);
            }
            $alvo = $corpo['id'] ?? 0;
            if (!$sessao->encerrar($id, is_int($alvo) ? $alvo : (is_string($alvo) && ctype_digit($alvo) ? (int) $alvo : 0))) {
                Api::erro(404, 'Sessão não encontrada (talvez já tenha sido encerrada).');
            }
        } catch (PDOException $e) {
            error_log('SICAPDA API: falha ao encerrar sessão: ' . $e->getMessage());
            Api::erro(500, 'Não foi possível encerrar a sessão. Tente novamente.');
        }

        Api::json(['mensagem' => 'Sessão encerrada.', 'encerradas' => 1]);
    }

    // ─── Sino e tempo real ───────────────────────────────────────────────────

    /** Alertas do sino: os mesmos do site (previsão + catracas, conforme as preferências), com o destino de cada um. */
    private function notificacoes(array $usuario, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'GET');
        $alertas = Notificacoes::listar((int) $usuario['empresa_id'], (int) $usuario['id']);
        Api::json(['total' => count($alertas), 'alertas' => $alertas]);
    }

    /** O que mudou na empresa desde o último acesso que o app já conhece (?ultimo=ID). Ver AoVivo. */
    private function aoVivo(int $empresaId, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'GET');
        $ultimo = $_GET['ultimo'] ?? null;
        $ultimo = is_string($ultimo) && ctype_digit($ultimo) ? (int) $ultimo : null;

        // Consulta feita a cada poucos segundos: libera o arquivo da sessão do PHP já.
        session_write_close();
        Api::json((new AoVivo($empresaId))->estado($ultimo));
    }

    // ─── Configurações: dispositivos IoT ─────────────────────────────────────

    /** Qualquer usuário vê os dispositivos (token sempre mascarado); "podeEditar" = é o administrador. */
    private function dispositivos(array $usuario, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'GET');
        Api::json(['podeEditar' => $this->ehAdmin($usuario)] + (new Integracoes())->resumo((int) $usuario['empresa_id']));
    }

    private function gerirDispositivos(array $usuario, string $rota, string $metodo): never
    {
        $this->exigirMetodo($metodo, 'POST');
        if (!$this->ehAdmin($usuario)) {
            Api::erro(403, 'Somente o administrador da empresa pode gerenciar dispositivos.');
        }

        $empresaId = (int) $usuario['empresa_id'];
        $corpo = Api::corpo();
        $integracoes = new Integracoes();

        try {
            $resultado = match ($rota) {
                '/dispositivos/criar'    => $integracoes->criar($empresaId, $corpo),
                '/dispositivos/token'    => $integracoes->novoToken($empresaId, $corpo),
                '/dispositivos/simular'  => $integracoes->simularLeitura($empresaId, $corpo),
                '/dispositivos/pesagem'  => $integracoes->registrarPesagem($empresaId, $corpo),
            };
        } catch (CadastroException $e) {
            Api::erro($e->status(), $e->getMessage());
        }

        Api::json($resultado);
    }

    private function exigirMetodo(string $metodo, string $esperado): void
    {
        if ($metodo !== $esperado) {
            Api::erro(405, 'Método não permitido.');
        }
    }

    // ─── Assistente ──────────────────────────────────────────────────────────

    private function assistente(int $empresaId): never
    {
        $previsao = new Previsao($empresaId);

        Api::json([
            'boasVindas'       => (new Assistente($empresaId))->boasVindas(),
            'precisao'         => $previsao->precisao(),
            'eventos'          => $previsao->eventos(3),
            'perguntasRapidas' => ['Previsão para amanhã', 'Previsão para sexta', 'Previsão semanal'],
        ]);
    }

    private function perguntar(int $empresaId): never
    {
        $mensagem = Api::corpo()['mensagem'] ?? '';
        if (!is_string($mensagem)) {
            $mensagem = '';
        }

        $resposta = (new Assistente($empresaId))->responder($mensagem);
        Api::json(['resposta' => $resposta, 'hora' => date('H:i')]);
    }
}
