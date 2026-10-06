<?php

class RelatorioController
{
    private const POR_PAGINA = 20;
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
        $f = $this->filtros();

        $total   = $this->acesso->contarRegistros($empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria']);
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina  = min($f['pagina'], $paginas);

        $registros = $this->acesso->registros(
            $empresaId, $f['de'], $f['ate'], $f['busca'], $f['categoria'],
            self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA
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
            'porPagina' => self::POR_PAGINA,
            'cards'     => [
                'total'   => $total,
                'hoje'    => $hojeTotal,
                'duracao' => Datas::duracao($resumo['duracao_media_min']),
                'pico'    => $resumo['pico_hora'] === null
                    ? '—'
                    : sprintf('%02d:00 - %02d:00', $resumo['pico_hora'], $resumo['pico_hora'] + 1),
            ],
            'notificacoesNaoLidas' => count((new Previsao($empresaId))->alertas()),
        ];

        require __DIR__ . '/../views/relatorios.php';
    }

    /** GET /relatorios/exportar — baixa os registros filtrados em CSV (Excel pt-BR). */
    public function exportar(): void
    {
        AuthMiddleware::autenticado();

        $f = $this->filtros();
        $registros = $this->acesso->registrosParaExportar(
            Pagina::empresaId(), $f['de'], $f['ate'], $f['busca'], $f['categoria']
        );

        $nomeArquivo = 'relatorio-acessos_' . $f['de'] . '_a_' . $f['ate'] . '.csv';
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
                date('d/m/Y', strtotime($r['dia'])),
                $r['entrada'] ? date('H:i', strtotime($r['entrada'])) : '',
                $r['saida'] ? date('H:i', strtotime($r['saida'])) : '',
                $r['duracao_min'] !== null ? Datas::duracao($r['duracao_min']) : '',
            ], ';');
        }
        fclose($out);
        exit;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** Lê e valida os filtros da query string (nunca confia no que veio da URL). */
    private function filtros(): array
    {
        $hoje = new DateTimeImmutable('today');

        $de  = Datas::parse($_GET['de'] ?? null)  ?? $hoje->modify('first day of this month');
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

    /**
     * Impede "injeção de fórmula" no Excel: textos que começam com = + - @ (ou
     * tab/CR) são tratados como fórmula ao abrir o CSV. O apóstrofo força texto.
     */
    private static function neutralizarFormula(string $valor): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
    }
}
