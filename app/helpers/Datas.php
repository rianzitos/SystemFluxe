<?php

/**
 * Utilitários de data em português do Brasil (nomes, feriados, formatação).
 */
class Datas
{
    public const DIAS_SEMANA = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    public const MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    public static function nomeDiaSemana(DateTimeInterface $d): string
    {
        return self::DIAS_SEMANA[(int) $d->format('w')];
    }

    /** "Terça-feira, 25 de agosto de 2026" */
    public static function porExtenso(DateTimeInterface $d): string
    {
        return ucfirst(self::nomeDiaSemana($d)) . ', ' . $d->format('j') . ' de '
            . self::MESES[(int) $d->format('n')] . ' de ' . $d->format('Y');
    }

    /** "Outubro 2026" */
    public static function mesAno(DateTimeInterface $d): string
    {
        return ucfirst(self::MESES[(int) $d->format('n')]) . ' ' . $d->format('Y');
    }

    /** Valida 'YYYY-MM-DD' e devolve DateTimeImmutable (ou null). */
    public static function parse(?string $valor): ?DateTimeImmutable
    {
        if ($valor === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        return ($d && $d->format('Y-m-d') === $valor) ? $d : null;
    }

    /** "8h 30min", "9h", "45min". */
    public static function duracao(?int $minutos): string
    {
        if ($minutos === null || $minutos < 0) {
            return '—';
        }
        $h = intdiv($minutos, 60);
        $m = $minutos % 60;
        if ($h === 0) {
            return $m . 'min';
        }
        return $m === 0 ? $h . 'h' : $h . 'h ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . 'min';
    }

    /**
     * Feriados nacionais do ano: ['Y-m-d' => 'Nome'].
     * Fixos + móveis (calculados a partir da Páscoa).
     */
    public static function feriados(int $ano): array
    {
        static $cache = [];
        if (isset($cache[$ano])) {
            return $cache[$ano];
        }

        $f = [
            "$ano-01-01" => 'Confraternização Universal',
            "$ano-04-21" => 'Tiradentes',
            "$ano-05-01" => 'Dia do Trabalho',
            "$ano-09-07" => 'Independência do Brasil',
            "$ano-10-12" => 'Nossa Senhora Aparecida',
            "$ano-11-02" => 'Finados',
            "$ano-11-15" => 'Proclamação da República',
            "$ano-11-20" => 'Consciência Negra',
            "$ano-12-25" => 'Natal',
        ];

        // Páscoa (algoritmo de Meeus/Jones/Butcher — não depende da extensão calendar)
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $g = intdiv(8 * $b + 13, 25);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 19 * $l, 433);
        $mes = intdiv($h + $l - 7 * $m + 90, 25);
        $dia = ($h + $l - 7 * $m + 33 * $mes + 19) % 32;
        $pascoa = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));

        $f[$pascoa->modify('-48 days')->format('Y-m-d')] = 'Carnaval';
        $f[$pascoa->modify('-47 days')->format('Y-m-d')] = 'Carnaval';
        $f[$pascoa->modify('-2 days')->format('Y-m-d')]  = 'Sexta-feira Santa';
        $f[$pascoa->modify('+60 days')->format('Y-m-d')] = 'Corpus Christi';

        ksort($f);
        return $cache[$ano] = $f;
    }

    public static function feriado(DateTimeInterface $d): ?string
    {
        return self::feriados((int) $d->format('Y'))[$d->format('Y-m-d')] ?? null;
    }
}
