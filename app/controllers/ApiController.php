<?php

/**
 * API JSON consumida pelo aplicativo Flutter (fluxe_app).
 *
 * Usa os mesmos models e o mesmo banco MySQL da versão web, sempre filtrando
 * pela empresa do usuário autenticado. Autenticação por Bearer token
 * (ApiAuthMiddleware) em vez de sessão/cookie.
 *
 * Rotas (todas sob /api):
 *   POST /api/login            {email, senha}
 *   GET  /api/me
 *   GET  /api/painel
 *   GET  /api/analise-mensal   ?mes=YYYY-MM
 *   GET  /api/pessoas
 *   GET  /api/relatorios       ?de&ate&busca&categoria&pagina
 *   GET  /api/relatorios/exportar  (CSV, mesmos filtros)
 *   GET  /api/assistente
 *   POST /api/assistente       {mensagem}
 */
class ApiController
{
    public function despachar(string $uri, string $metodo): never
    {
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
                    $this->painel($empresaId);
                case '/analise-mensal':
                    $this->analiseMensal($empresaId);
                case '/pessoas':
                    $this->pessoas($empresaId);
                case '/relatorios':
                    $this->relatorios($empresaId);
                case '/relatorios/exportar':
                    (new RelatorioController())->enviarCsv($empresaId);
                case '/assistente':
                    $metodo === 'POST' ? $this->perguntar($empresaId) : $this->assistente($empresaId);
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

    private function painel(int $empresaId): never
    {
        $painel = new Painel($empresaId);
        $dados = $painel->painel();
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

    private function relatorios(int $empresaId): never
    {
        $acesso = new Acesso();
        $f = (new RelatorioController())->filtros();
        $porPagina = RelatorioController::POR_PAGINA;

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
                'duracao'   => $r['duracao_min'] !== null ? Datas::duracao($r['duracao_min']) : null,
            ], $registros),
        ]);
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
