<?php

/**
 * Planilha do Excel (.xlsx) simples, sem biblioteca externa (só a extensão zip do PHP, padrão no cPanel).
 *
 * Por que não só CSV: o CSV não guarda largura de coluna nem tipo. O Excel transforma "13/11/2026" em data e,
 * na coluna estreita padrão, mostra "########". Aqui cada coluna tem a sua largura, as datas e horas são
 * datas e horas de verdade (dá para ordenar, filtrar e somar), o cabeçalho fica fixo e com filtro.
 *
 * Uso:
 *   $x = new Xlsx('Acessos');
 *   $x->colunas([['Nome', 32], ['Data', 12], ['Entrada', 9]]);
 *   $x->linha(['Maria', Xlsx::data('2026-11-13'), Xlsx::hora('2026-11-13 08:05:00')]);
 *   $x->enviar('relatorio.xlsx');
 *
 * Valores: string (texto; nunca vira fórmula), int/float (número), null (vazio) ou os marcadores
 * Xlsx::data(), ::hora(), ::dataHora(), ::duracao().
 */
class Xlsx
{
    // Índices dos estilos (cellXfs) definidos em estilos()
    private const S_TEXTO = 0;
    private const S_CABECALHO = 1;
    private const S_DATA = 2;
    private const S_HORA = 3;
    private const S_DATA_HORA = 4;
    private const S_DURACAO = 5;

    private array $colunas = [];
    private array $linhas = [];

    public function __construct(private string $nomeAba = 'Planilha')
    {
        // Nome de aba: até 31 caracteres, sem : \ / ? * [ ]
        $this->nomeAba = mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', '', $nomeAba) ?: 'Planilha', 0, 31);
    }

    public static function disponivel(): bool
    {
        return class_exists('ZipArchive');
    }

    /** Data ('Y-m-d' ou 'Y-m-d H:i:s'), mostrada como 13/11/2026. */
    public static function data(?string $valor): ?array
    {
        return $valor ? ['serial' => self::dias($valor), 's' => self::S_DATA] : null;
    }

    /** Hora do dia, mostrada como 08:05. */
    public static function hora(?string $dataHora): ?array
    {
        return $dataHora ? ['serial' => self::fracaoDoDia($dataHora), 's' => self::S_HORA] : null;
    }

    /** Data e hora, mostrada como 14/11/2026 02:10. */
    public static function dataHora(?string $dataHora): ?array
    {
        return $dataHora ? ['serial' => self::dias($dataHora) + self::fracaoDoDia($dataHora), 's' => self::S_DATA_HORA] : null;
    }

    /** Duração em minutos, mostrada como 8:30 (horas:minutos; soma corretamente no Excel, mesmo acima de 24h). */
    public static function duracao(?int $minutos): ?array
    {
        return $minutos === null || $minutos < 0 ? null : ['serial' => $minutos / 1440, 's' => self::S_DURACAO];
    }

    /**
     * [[título, largura mínima em caracteres], ...]. Colunas de texto se alargam sozinhas até caber o texto mais
     * longo (limite de 60), para nada ficar cortado; datas e horas usam a largura informada.
     */
    public function colunas(array $colunas): static
    {
        $this->colunas = $colunas;
        return $this;
    }

    public function linha(array $valores): static
    {
        $this->linhas[] = array_values($valores);
        return $this;
    }

    /** Envia o arquivo para download e encerra. */
    public function enviar(string $nomeArquivo): never
    {
        $arquivo = $this->gerar();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nomeArquivo) . '"');
        header('Content-Length: ' . filesize($arquivo));
        header('Cache-Control: no-store');
        readfile($arquivo);
        @unlink($arquivo);
        exit;
    }

    /** Gera o .xlsx num arquivo temporário e devolve o caminho. */
    public function gerar(): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($caminho === false || $zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar a planilha.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($this->nomeAba) . '" sheetId="1" r:id="rId1"/></sheets>'
            . ($this->colunas ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''
                . self::xml(str_replace("'", "''", $this->nomeAba)) . '\'!' . $this->intervaloFiltro(true) . '</definedName></definedNames>' : '')
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/styles.xml', self::estilos());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->aba());
        $zip->close();

        return $caminho;
    }

    // ─── Partes do arquivo ───────────────────────────────────────────────────

    private function aba(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';   // impressão: todas as colunas na largura da folha

        // Cabeçalho fixo ao rolar
        $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>';

        if ($this->colunas) {
            $xml .= '<cols>';
            foreach ($this->colunas as $i => [$titulo, $largura]) {
                $largura = max((float) $largura, mb_strlen((string) $titulo) + 3);
                foreach ($this->linhas as $linha) {
                    if (is_string($linha[$i] ?? null)) {
                        $largura = max($largura, min(60, mb_strlen($linha[$i]) + 2));
                    }
                }
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $largura . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        $numero = 1;
        if ($this->colunas) {
            $xml .= '<row r="1">';
            foreach ($this->colunas as $i => [$titulo]) {
                $xml .= self::celula($i, 1, (string) $titulo, self::S_CABECALHO);
            }
            $xml .= '</row>';
            $numero = 2;
        }
        foreach ($this->linhas as $linha) {
            $xml .= '<row r="' . $numero . '">';
            foreach ($linha as $i => $valor) {
                $xml .= self::celula($i, $numero, $valor);
            }
            $xml .= '</row>';
            $numero++;
        }
        $xml .= '</sheetData>';

        if ($this->colunas) {
            $xml .= '<autoFilter ref="' . $this->intervaloFiltro(false) . '"/>';
        }
        return $xml . '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddFooter>&amp;CPágina &amp;P de &amp;N</oddFooter></headerFooter></worksheet>';
    }

    private function intervaloFiltro(bool $absoluto): string
    {
        $fim = self::coluna(count($this->colunas) - 1);
        $ultima = max(1, count($this->linhas) + 1);
        return $absoluto ? '$A$1:$' . $fim . '$' . $ultima : 'A1:' . $fim . $ultima;
    }

    private static function celula(int $coluna, int $linha, mixed $valor, ?int $estilo = null): string
    {
        $ref = self::coluna($coluna) . $linha;
        if ($valor === null || $valor === '') {
            return '';
        }
        if (is_array($valor)) {   // data / hora / duração
            // json_encode grava o número com precisão total: com menos casas, 00:01 vira 00:00:59,99 e aparece 00:00.
            return '<c r="' . $ref . '" s="' . $valor['s'] . '"><v>' . json_encode((float) $valor['serial']) . '</v></c>';
        }
        if (is_int($valor) || is_float($valor)) {
            return '<c r="' . $ref . '"' . ($estilo ? ' s="' . $estilo . '"' : '') . '><v>' . $valor . '</v></c>';
        }
        // Texto "inline": nunca é interpretado como fórmula (sem risco de injeção de fórmula, ao contrário do CSV)
        return '<c r="' . $ref . '" t="inlineStr"' . ($estilo ? ' s="' . $estilo . '"' : '') . '><is><t xml:space="preserve">'
            . self::xml((string) $valor) . '</t></is></c>';
    }

    private static function estilos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="4">'
            . '<numFmt numFmtId="164" formatCode="dd/mm/yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="hh:mm"/>'
            . '<numFmt numFmtId="166" formatCode="dd/mm/yyyy hh:mm"/>'
            . '<numFmt numFmtId="167" formatCode="[h]:mm"/>'
            . '</numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF171717"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFC400"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '<xf numFmtId="167" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    // ─── Apoio ───────────────────────────────────────────────────────────────

    /** Dias desde 30/12/1899 (a "data" do Excel). Sem fuso: lê a data como está escrita. */
    private static function dias(string $dataHora): int
    {
        $d = new DateTimeImmutable(substr($dataHora, 0, 10), new DateTimeZone('UTC'));
        return intdiv($d->getTimestamp(), 86400) + 25569;
    }

    /**
     * Hora como fração do dia (08:05 → 0,3368...). Calculada direto das horas, minutos e segundos: tirar a parte
     * inteira de um número grande (dias + hora) perdia precisão e 00:01 aparecia como 00:00.
     */
    private static function fracaoDoDia(string $dataHora): float
    {
        $d = new DateTimeImmutable($dataHora, new DateTimeZone('UTC'));
        return ((int) $d->format('G') * 3600 + (int) $d->format('i') * 60 + (int) $d->format('s')) / 86400;
    }

    /** 0 → A, 25 → Z, 26 → AA */
    private static function coluna(int $indice): string
    {
        $letras = '';
        for ($n = $indice + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letras = chr(65 + ($n - 1) % 26) . $letras;
        }
        return $letras;
    }

    /** Escapa para XML e remove caracteres de controle que o Excel recusa. */
    private static function xml(string $texto): string
    {
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto) ?? '';
        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
