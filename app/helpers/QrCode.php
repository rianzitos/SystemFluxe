<?php

/**
 * Gerador de QR Code (ISO/IEC 18004) em PHP puro, sem biblioteca (o site roda sem Composer, como o Smtp.php e
 * o Xlsx.php). Serve para o aplicativo autenticador ler a chave da verificação em duas etapas.
 *
 * Modo byte (o texto vai como UTF-8), correção de erros nível M (aguenta ~15% do código danificado), com a
 * menor versão (tamanho) que couber o texto e a máscara escolhida pela pontuação da norma.
 * Segue o algoritmo de referência do Project Nayuki (QR Code generator library, licença MIT).
 *
 * Uso:
 *   $qr = QrCode::gerar('otpauth://totp/...');
 *   $qr->svg();      // <svg> pronto para colocar no HTML (site)
 *   $qr->linhas();   // ["1111111010...", ...]: 1 = módulo escuro, para o aplicativo desenhar
 */
final class QrCode
{
    // Por versão (índice 1 a 40): bytes de correção por bloco e número de blocos, para cada nível.
    private const ECC_POR_BLOCO = [
        'L' => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'M' => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        'Q' => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        'H' => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];
    private const BLOCOS = [
        'L' => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        'M' => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        'Q' => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        'H' => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];
    /** Bits do nível de correção no "formato" (a norma não segue a ordem L, M, Q, H). */
    private const NIVEL_FORMATO = ['L' => 1, 'M' => 0, 'Q' => 3, 'H' => 2];

    private int $tamanho;
    /** @var bool[][] [y][x] true = escuro */
    private array $modulos = [];
    /** @var bool[][] [y][x] true = faz parte dos padrões fixos (não recebe dados nem máscara) */
    private array $fixo = [];

    private function __construct(private int $versao, private string $nivel)
    {
        $this->tamanho = $versao * 4 + 17;
        $linha = array_fill(0, $this->tamanho, false);
        $this->modulos = array_fill(0, $this->tamanho, $linha);
        $this->fixo = array_fill(0, $this->tamanho, $linha);
    }

    public static function gerar(string $texto, string $nivel = 'M'): self
    {
        if (!isset(self::BLOCOS[$nivel])) {
            throw new InvalidArgumentException('Nível de correção inválido: ' . $nivel);
        }
        $bytes = array_values(unpack('C*', $texto) ?: []);

        // Menor versão em que o texto cabe (indicador de modo + contagem + dados).
        for ($versao = 1; ; $versao++) {
            if ($versao > 40) {
                throw new InvalidArgumentException('Texto longo demais para um QR Code.');
            }
            $bitsContagem = $versao <= 9 ? 8 : 16;
            $capacidade = self::codewordsDeDados($versao, $nivel) * 8;
            if (count($bytes) < (1 << $bitsContagem) && 4 + $bitsContagem + count($bytes) * 8 <= $capacidade) {
                break;
            }
        }

        $bits = [];
        self::anexarBits($bits, 0b0100, 4);                       // modo byte
        self::anexarBits($bits, count($bytes), $bitsContagem);
        foreach ($bytes as $b) {
            self::anexarBits($bits, $b, 8);
        }
        // Terminador (até 4 zeros), completa o byte e preenche com 0xEC 0x11 alternados.
        self::anexarBits($bits, 0, min(4, $capacidade - count($bits)));
        self::anexarBits($bits, 0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacidade; $pad ^= 0xEC ^ 0x11) {
            self::anexarBits($bits, $pad, 8);
        }

        $dados = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $bit) {
            $dados[$i >> 3] |= $bit << (7 - ($i & 7));
        }

        $qr = new self($versao, $nivel);
        $qr->desenharPadroes();
        $qr->desenharCodewords($qr->comCorrecao($dados));

        // Escolhe a máscara de menor penalidade (aplicar a mesma máscara duas vezes desfaz).
        $melhor = 0;
        $menor = PHP_INT_MAX;
        for ($m = 0; $m < 8; $m++) {
            $qr->aplicarMascara($m);
            $qr->desenharFormato($m);
            $pena = $qr->penalidade();
            if ($pena < $menor) {
                $melhor = $m;
                $menor = $pena;
            }
            $qr->aplicarMascara($m);
        }
        $qr->aplicarMascara($melhor);
        $qr->desenharFormato($melhor);

        return $qr;
    }

    public function tamanho(): int
    {
        return $this->tamanho;
    }

    /** @return bool[][] [linha][coluna], true = escuro (sem a margem branca). */
    public function matriz(): array
    {
        return $this->modulos;
    }

    /** Uma string por linha, "1" = escuro e "0" = claro (sem a margem branca): é o que o aplicativo desenha. */
    public function linhas(): array
    {
        return array_map(fn(array $linha) => implode('', array_map(fn(bool $m) => $m ? '1' : '0', $linha)), $this->modulos);
    }

    /** SVG com a margem branca obrigatória ($borda módulos). Escuro sobre branco mesmo no tema escuro: é o que as câmeras leem. */
    public function svg(int $borda = 4, string $rotulo = 'QR Code'): string
    {
        $lado = $this->tamanho + $borda * 2;
        $caminho = '';
        foreach ($this->modulos as $y => $linha) {
            foreach ($linha as $x => $escuro) {
                if ($escuro) {
                    $caminho .= 'M' . ($x + $borda) . ',' . ($y + $borda) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $lado . ' ' . $lado . '" shape-rendering="crispEdges"'
            . ' role="img" aria-label="' . htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8') . '">'
            . '<rect width="100%" height="100%" fill="#FFFFFF"/><path fill="#000000" d="' . $caminho . '"/></svg>';
    }

    // ─── Capacidade ──────────────────────────────────────────────────────────

    /** Módulos disponíveis para dados + correção (tudo menos os padrões fixos). */
    private static function modulosDeDados(int $versao): int
    {
        $total = (16 * $versao + 128) * $versao + 64;
        if ($versao >= 2) {
            $alinhamentos = intdiv($versao, 7) + 2;
            $total -= (25 * $alinhamentos - 10) * $alinhamentos - 55;
            if ($versao >= 7) {
                $total -= 36;
            }
        }
        return $total;
    }

    private static function codewordsDeDados(int $versao, string $nivel): int
    {
        return intdiv(self::modulosDeDados($versao), 8) - self::ECC_POR_BLOCO[$nivel][$versao] * self::BLOCOS[$nivel][$versao];
    }

    private static function anexarBits(array &$bits, int $valor, int $quantos): void
    {
        for ($i = $quantos - 1; $i >= 0; $i--) {
            $bits[] = ($valor >> $i) & 1;
        }
    }

    // ─── Correção de erros (Reed-Solomon) e intercalação dos blocos ─────────

    private function comCorrecao(array $dados): array
    {
        $numBlocos = self::BLOCOS[$this->nivel][$this->versao];
        $eccPorBloco = self::ECC_POR_BLOCO[$this->nivel][$this->versao];
        $totalCodewords = intdiv(self::modulosDeDados($this->versao), 8);
        $blocosCurtos = $numBlocos - $totalCodewords % $numBlocos;
        $tamanhoCurto = intdiv($totalCodewords, $numBlocos);

        $divisor = self::divisorRs($eccPorBloco);
        $blocos = [];
        $k = 0;
        for ($i = 0; $i < $numBlocos; $i++) {
            $n = $tamanhoCurto - $eccPorBloco + ($i < $blocosCurtos ? 0 : 1);
            $bloco = array_slice($dados, $k, $n);
            $k += $n;
            $ecc = self::restoRs($bloco, $divisor);
            if ($i < $blocosCurtos) {
                $bloco[] = 0;   // posição vazia: os blocos curtos têm um byte de dados a menos
            }
            $blocos[] = array_merge($bloco, $ecc);
        }

        $resultado = [];
        $largura = count($blocos[0]);
        for ($i = 0; $i < $largura; $i++) {
            foreach ($blocos as $j => $bloco) {
                if ($i !== $tamanhoCurto - $eccPorBloco || $j >= $blocosCurtos) {
                    $resultado[] = $bloco[$i];
                }
            }
        }
        return $resultado;
    }

    private static function divisorRs(int $grau): array
    {
        $resultado = array_fill(0, $grau, 0);
        $resultado[$grau - 1] = 1;
        $raiz = 1;
        for ($i = 0; $i < $grau; $i++) {
            for ($j = 0; $j < $grau; $j++) {
                $resultado[$j] = self::multiplicarGf($resultado[$j], $raiz);
                if ($j + 1 < $grau) {
                    $resultado[$j] ^= $resultado[$j + 1];
                }
            }
            $raiz = self::multiplicarGf($raiz, 0x02);
        }
        return $resultado;
    }

    private static function restoRs(array $dados, array $divisor): array
    {
        $resultado = array_fill(0, count($divisor), 0);
        foreach ($dados as $b) {
            $fator = $b ^ array_shift($resultado);
            $resultado[] = 0;
            foreach ($divisor as $i => $coef) {
                $resultado[$i] ^= self::multiplicarGf($coef, $fator);
            }
        }
        return $resultado;
    }

    /** Multiplicação no corpo finito GF(2^8) com o polinômio 0x11D. */
    private static function multiplicarGf(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    // ─── Padrões fixos ───────────────────────────────────────────────────────

    private function fixar(int $x, int $y, bool $escuro): void
    {
        $this->modulos[$y][$x] = $escuro;
        $this->fixo[$y][$x] = true;
    }

    private function desenharPadroes(): void
    {
        // Linhas de sincronismo
        for ($i = 0; $i < $this->tamanho; $i++) {
            $this->fixar(6, $i, $i % 2 === 0);
            $this->fixar($i, 6, $i % 2 === 0);
        }

        // Os três quadrados dos cantos (com a faixa clara em volta)
        foreach ([[3, 3], [$this->tamanho - 4, 3], [3, $this->tamanho - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $this->tamanho && $y >= 0 && $y < $this->tamanho) {
                        $dist = max(abs($dx), abs($dy));
                        $this->fixar($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        }

        // Padrões de alinhamento (menos onde ficam os quadrados dos cantos)
        $posicoes = $this->posicoesAlinhamento();
        $n = count($posicoes);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->fixar($posicoes[$i] + $dx, $posicoes[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        $this->desenharFormato(0);   // reserva a área; a máscara certa é desenhada no fim
        $this->desenharVersao();
    }

    private function posicoesAlinhamento(): array
    {
        if ($this->versao === 1) {
            return [];
        }
        $n = intdiv($this->versao, 7) + 2;
        $passo = intdiv($this->versao * 8 + $n * 3 + 5, $n * 4 - 4) * 2;
        $resultado = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $resultado[] = $this->tamanho - 7 - $i * $passo;
        }
        $resultado[] = 6;
        return array_reverse($resultado);
    }

    private function desenharFormato(int $mascara): void
    {
        $dados = self::NIVEL_FORMATO[$this->nivel] << 3 | $mascara;
        $resto = $dados;
        for ($i = 0; $i < 10; $i++) {
            $resto = ($resto << 1) ^ (($resto >> 9) * 0x537);
        }
        $bits = ($dados << 10 | $resto) ^ 0x5412;
        $bit = fn(int $i) => (($bits >> $i) & 1) === 1;

        // Primeira cópia (perto do canto superior esquerdo)
        for ($i = 0; $i <= 5; $i++) {
            $this->fixar(8, $i, $bit($i));
        }
        $this->fixar(8, 7, $bit(6));
        $this->fixar(8, 8, $bit(7));
        $this->fixar(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->fixar(14 - $i, 8, $bit($i));
        }

        // Segunda cópia (dividida entre os outros dois cantos)
        for ($i = 0; $i < 8; $i++) {
            $this->fixar($this->tamanho - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->fixar(8, $this->tamanho - 15 + $i, $bit($i));
        }
        $this->fixar(8, $this->tamanho - 8, true);   // módulo sempre escuro
    }

    private function desenharVersao(): void
    {
        if ($this->versao < 7) {
            return;
        }
        $resto = $this->versao;
        for ($i = 0; $i < 12; $i++) {
            $resto = ($resto << 1) ^ (($resto >> 11) * 0x1F25);
        }
        $bits = $this->versao << 12 | $resto;
        for ($i = 0; $i < 18; $i++) {
            $escuro = (($bits >> $i) & 1) === 1;
            $a = $this->tamanho - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->fixar($a, $b, $escuro);
            $this->fixar($b, $a, $escuro);
        }
    }

    // ─── Dados e máscara ─────────────────────────────────────────────────────

    /** Percorre o código em zigue-zague, de baixo para cima, em colunas de 2, pulando os padrões fixos. */
    private function desenharCodewords(array $dados): void
    {
        $totalBits = count($dados) * 8;
        $i = 0;
        for ($direita = $this->tamanho - 1; $direita >= 1; $direita -= 2) {
            if ($direita === 6) {
                $direita = 5;   // pula a coluna de sincronismo
            }
            $subindo = (($direita + 1) & 2) === 0;
            for ($v = 0; $v < $this->tamanho; $v++) {
                $y = $subindo ? $this->tamanho - 1 - $v : $v;
                for ($j = 0; $j < 2; $j++) {
                    $x = $direita - $j;
                    if (!$this->fixo[$y][$x] && $i < $totalBits) {
                        $this->modulos[$y][$x] = (($dados[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function aplicarMascara(int $mascara): void
    {
        for ($y = 0; $y < $this->tamanho; $y++) {
            for ($x = 0; $x < $this->tamanho; $x++) {
                if ($this->fixo[$y][$x]) {
                    continue;
                }
                $inverte = match ($mascara) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($inverte) {
                    $this->modulos[$y][$x] = !$this->modulos[$y][$x];
                }
            }
        }
    }

    // ─── Penalidade (escolha da máscara) ─────────────────────────────────────

    private function penalidade(): int
    {
        $n = $this->tamanho;
        $m = $this->modulos;
        $total = 0;

        // Sequências da mesma cor e padrões parecidos com os quadrados dos cantos, nas linhas e nas colunas.
        for ($eixo = 0; $eixo < 2; $eixo++) {
            for ($a = 0; $a < $n; $a++) {
                $corAtual = false;
                $comprimento = 0;
                $historico = array_fill(0, 7, 0);
                for ($b = 0; $b < $n; $b++) {
                    $cor = $eixo === 0 ? $m[$a][$b] : $m[$b][$a];
                    if ($cor === $corAtual) {
                        $comprimento++;
                        if ($comprimento === 5) {
                            $total += 3;
                        } elseif ($comprimento > 5) {
                            $total++;
                        }
                    } else {
                        $this->historicoAdicionar($comprimento, $historico);
                        if (!$corAtual) {
                            $total += $this->contarPadroes($historico) * 40;
                        }
                        $corAtual = $cor;
                        $comprimento = 1;
                    }
                }
                if ($corAtual) {
                    $this->historicoAdicionar($comprimento, $historico);
                    $comprimento = 0;
                }
                $this->historicoAdicionar($comprimento + $n, $historico);
                $total += $this->contarPadroes($historico) * 40;
            }
        }

        // Blocos 2x2 da mesma cor
        $escuros = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m[$y][$x]) {
                    $escuros++;
                }
                if ($y < $n - 1 && $x < $n - 1) {
                    $c = $m[$y][$x];
                    if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                        $total += 3;
                    }
                }
            }
        }

        // Equilíbrio entre escuros e claros
        $area = $n * $n;
        $k = intdiv(abs($escuros * 20 - $area * 10) + $area - 1, $area) - 1;
        return $total + $k * 10;
    }

    private function historicoAdicionar(int $comprimento, array &$historico): void
    {
        if ($historico[0] === 0) {
            $comprimento += $this->tamanho;   // margem clara antes da primeira sequência
        }
        array_unshift($historico, $comprimento);
        array_pop($historico);
    }

    private function contarPadroes(array $h): int
    {
        $n = $h[1];
        $nucleo = $n > 0 && $h[2] === $n && $h[3] === $n * 3 && $h[4] === $n && $h[5] === $n;
        return ($nucleo && $h[0] >= $n * 4 && $h[6] >= $n ? 1 : 0)
            + ($nucleo && $h[6] >= $n * 4 && $h[0] >= $n ? 1 : 0);
    }
}
