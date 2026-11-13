<?php

class RelatorioController
{
    public const POR_PAGINA = 20;
    private const MAX_DIAS_INTERVALO = 366;

    private Acesso $acesso;

    public function __construct()
    {
        $this->acesso = new Acesso();
    }

    /** GET /relatorios — tela com filtros, indicadores e tabela paginada. */
    public function exibir(): void
    {
        AuthMiddleware::autenticado();

        $empresaId = Pagina::empresaId();

        // Período padrão e itens por página escolhidos em Configurações → Sistema (só no site; a API do app usa os padrões).
        $sistema   = (new Preferencia())->obter((int) $_SESSION['usuario_id'])['sistema'];
        $porPagina = $sistema['itens_pagina'];
        $f = $this->filtros($sistema['periodo_padrao']);

        $total   = $this->acesso->contarRegistros($empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria']);
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina  = min($f['pagina'], $paginas);

        $registros = $this->acesso->registros(
            $empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria'],
            $porPagina, ($pagina - 1) * $porPagina
        );

        $resumo = $this->acesso->resumoRegistros($empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria']);
        $hoje   = date('Y-m-d');
        $hojeTotal = $this->acesso->contarRegistros($empresaId, $hoje, $hoje, $f['busca'], $f['categoria']);

        $dados = [
            'filtros'   => $f,
            'registros' => $registros,
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
            'notificacoesNaoLidas' => Notificacoes::contar(count((new Previsao($empresaId))->alertas()), $empresaId),
        ];

        require __DIR__ . '/../views/relatorios.php';
    }

    /** GET /relatorios/exportar?formato=xlsx|csv — baixa os registros filtrados (Excel ou CSV, os dois legíveis no Excel). */
    public function exportar(): void
    {
        AuthMiddleware::autenticado();
        $this->enviarExportacao(Pagina::empresaId());
    }

    /**
     * Exporta os filtros atuais ($_GET) da empresa — usado pela web (sessão) e pela API do app (token).
     * ?formato=xlsx: planilha do Excel com colunas na largura certa e datas/horas de verdade (no CSV o Excel
     * mostra "########" nas datas, porque o CSV não guarda largura de coluna). Sem o parâmetro: CSV, como
     * sempre foi (o aplicativo 1.2 e outros sistemas que leem o CSV continuam funcionando).
     */
    public function enviarExportacao(int $empresaId): never
    {
        $f = $this->filtros();
        $registros = $this->acesso->registrosParaExportar(
            $empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria']
        );
        $nomeBase = 'relatorio-acessos_' . $f['de'] . '_a_' . $f['ate'];

        if (($_GET['formato'] ?? '') === 'xlsx' && Xlsx::disponivel()) {
            $this->enviarXlsx($registros, $nomeBase . '.xlsx');
        }
        $this->enviarCsv($registros, $nomeBase . '.csv');
    }

    private function enviarXlsx(array $registros, string $nomeArquivo): never
    {
        $planilha = (new Xlsx('Acessos'))->colunas([
            ['Nome', 32], ['Função', 24], ['Categoria', 16], ['Data', 12],
            ['Entrada', 10], ['Saída', 17], ['Duração (h:min)', 15], ['Situação', 14],
        ]);

        foreach ($registros as $r) {
            // Saída no mesmo dia: só a hora. Turno que vira a noite: data e hora (ex.: 14/11/2026 02:10).
            $saida = null;
            if ($r['saida']) {
                $saida = date('Y-m-d', strtotime($r['saida'])) === $r['dia'] ? Xlsx::hora($r['saida']) : Xlsx::dataHora($r['saida']);
            }
            $planilha->linha([
                (string) $r['nome'],
                (string) ($r['cargo'] ?? ''),
                Acesso::CATEGORIAS[$r['categoria']] ?? (string) $r['categoria'],
                Xlsx::data($r['dia']),
                Xlsx::hora($r['entrada']),
                $saida,
                Xlsx::duracao($r['duracao_min']),
                $r['saida'] ? 'Concluído' : ($r['em_andamento'] ? 'No local' : 'Sem saída'),
            ]);
        }
        $planilha->enviar($nomeArquivo);
    }

    private function enviarCsv(array $registros, string $nomeArquivo): never
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: o Excel abre com acentos corretos
        fputcsv($out, ['Nome', 'Função', 'Categoria', 'Data', 'Entrada', 'Saída', 'Duração'], ';');

        foreach ($registros as $r) {
            fputcsv($out, [
                self::neutralizarFormula($r['nome']),
                self::neutralizarFormula($r['cargo']),
                Acesso::CATEGORIAS[$r['categoria']] ?? $r['categoria'],
                self::textoExcel(date('d/m/y', strtotime($r['dia']))),
                $r['entrada'] ? date('H:i', strtotime($r['entrada'])) : '',
                self::saidaParaCsv($r),
                $r['duracao_min'] !== null ? Datas::duracao($r['duracao_min']) : '',
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Lê e valida os filtros da query string (nunca confia no que veio da URL).
     * $periodoPadrao ('mes', '7', '30' ou '90') vale só quando a URL não traz a data inicial;
     * sem ele (API do app) o padrão é o mês atual.
     */
    public function filtros(?string $periodoPadrao = null): array
    {
        $hoje = new DateTimeImmutable('today');

        $inicioPadrao = in_array($periodoPadrao, ['7', '30', '90'], true)
            ? $hoje->modify('-' . ((int) $periodoPadrao - 1) . ' days')
            : $hoje->modify('first day of this month');

        $de  = Datas::parse($_GET['de'] ?? null)  ?? $inicioPadrao;
        $ate = Datas::parse($_GET['ate'] ?? null) ?? $hoje;

        if ($de > $ate) {
            [$de, $ate] = [$ate, $de];
        }
        if ($de->diff($ate)->days > self::MAX_DIAS_INTERVALO) {
            $de = $ate->modify('-' . self::MAX_DIAS_INTERVALO . ' days');
        }

        $categoria = (string) ($_GET['categoria'] ?? '');
        if (!isset(Acesso::CATEGORIAS[$categoria])) {
            $categoria = '';
        }

        $busca = is_string($_GET['busca'] ?? null) ? trim(mb_substr($_GET['busca'], 0, 100)) : '';

        return [
            'de'        => $de->format('Y-m-d'),
            'ate'       => $ate->format('Y-m-d'),
            'busca'     => $busca,
            'categoria' => $categoria,
            'pagina'    => max(1, (int) ($_GET['pagina'] ?? 1)),
        ];
    }

    /** Saída no CSV: "H:i"; com a data se foi em outro dia que a entrada; "No local" se ainda está dentro. */
    /** Saída no CSV: "07:20", ou "02:10 +1d" quando o turno vira a noite (como "14/11 02:10" o Excel virava data, com o ano errado). */
    private static function saidaParaCsv(array $r): string
    {
        if ($r['saida']) {
            $dias = (int) round((strtotime(date('Y-m-d', strtotime($r['saida']))) - strtotime($r['dia'])) / 86400);
            return date('H:i', strtotime($r['saida'])) . ($dias > 0 ? ' +' . $dias . 'd' : '');
        }
        return $r['em_andamento'] ? 'No local' : '';
    }

    /**
     * Data como texto do Excel: ="13/11/26". O CSV não guarda largura de coluna; escrita como 13/11/2026 o Excel a
     * transforma em data e, na coluna estreita padrão, mostra "########". Como texto curto (8 caracteres) ela cabe e
     * aparece igual em qualquer Excel. Valor fixo gerado aqui (só dígitos e barras), nunca vem do usuário.
     */
    private static function textoExcel(string $valor): string
    {
        return '="' . $valor . '"';
    }

    /**
     * Impede "injeção de fórmula" no Excel: textos que começam com = + - @ (ou
     * tab/CR) são tratados como fórmula ao abrir o CSV. O apóstrofo força texto.
     */
    private static function neutralizarFormula(string $valor): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
    }
}
