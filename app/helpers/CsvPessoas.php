<?php

/**
 * Lê e valida a planilha de pessoas (CSV) da importação em Pessoas → Cadastro. Só interpreta o texto;
 * quem grava no banco é Pessoa::importar().
 *
 * Colunas (a primeira linha é o cabeçalho, em qualquer ordem): nome e cargo são obrigatórias;
 * categoria (operador, supervisor ou prestador; padrão operador), matricula e cartao (o UID) são opcionais.
 * Aceita separador ";" (Excel em português) ou ",", arquivo em UTF-8 ou Windows-1252 (o "CSV" comum do Excel).
 */
class CsvPessoas
{
    public const MAX_LINHAS = 1000;

    private const COLUNAS = [
        'nome'      => ['nome', 'name', 'colaborador', 'funcionario'],
        'cargo'     => ['cargo', 'funcao'],
        'categoria' => ['categoria', 'tipo', 'grupo'],
        'matricula' => ['matricula', 'registro'],
        'cartao'    => ['cartao', 'uid', 'rfid', 'identificador', 'tag'],
    ];

    /** Cabeçalho + 2 exemplos, para o administrador baixar e preencher. */
    public static function modelo(): string
    {
        $linhas = [
            ['nome', 'cargo', 'categoria', 'matricula', 'cartao'],
            // O cartão fica em branco nos exemplos de propósito: o UID é único no sistema inteiro, então um
            // UID de exemplo travaria a segunda pessoa que importasse o modelo. Preencha com o UID do cartão (ex.: A1B2C3D4).
            ['Maria Souza', 'Operadora de Produção', 'operador', '1001', ''],
            ['João Lima', 'Supervisor de Turno', 'supervisor', '1002', ''],
        ];
        $saida = "\xEF\xBB\xBF"; // BOM: o Excel abre com os acentos certos
        foreach ($linhas as $l) {
            $saida .= implode(';', $l) . "\r\n";
        }
        return $saida;
    }

    /**
     * @return array{linhas: array, erros: array, total: int}  linhas = prontas para gravar; erros = ['linha' => n, 'motivo' => '...']
     * @throws InvalidArgumentException se o arquivo não tiver cabeçalho aproveitável ou for grande demais
     */
    public static function interpretar(string $conteudo): array
    {
        $conteudo = self::paraUtf8($conteudo);
        $registros = self::registros($conteudo);

        if (!$registros) {
            throw new InvalidArgumentException('O arquivo está vazio.');
        }

        // Cabeçalho: descobre em que coluna está cada campo.
        $indice = [];
        foreach ($registros[0]['campos'] as $posicao => $titulo) {
            $chave = self::semAcento(mb_strtolower(trim((string) $titulo)));
            foreach (self::COLUNAS as $campo => $apelidos) {
                if (in_array($chave, $apelidos, true) && !isset($indice[$campo])) {
                    $indice[$campo] = $posicao;
                }
            }
        }
        if (!isset($indice['nome']) || !isset($indice['cargo'])) {
            throw new InvalidArgumentException(
                'A primeira linha precisa ser o cabeçalho, com pelo menos as colunas nome e cargo '
                . '(opcionais: categoria, matricula, cartao). Baixe o modelo para ver o formato.'
            );
        }

        $dados = array_slice($registros, 1);
        if (count($dados) > self::MAX_LINHAS) {
            throw new InvalidArgumentException('A planilha tem mais de ' . self::MAX_LINHAS . ' linhas. Divida em partes menores.');
        }

        $linhas = [];
        $erros = [];
        $uidsVistos = [];
        $matriculasVistas = [];

        foreach ($dados as $registro) {
            $n = $registro['linha'];
            $campo = fn(string $nome) => isset($indice[$nome]) ? (string) ($registro['campos'][$indice[$nome]] ?? '') : '';

            $nome = self::limpar($campo('nome'));
            $cargo = self::limpar($campo('cargo'));
            $matricula = self::limpar($campo('matricula'));
            $textoCategoria = $campo('categoria');
            $uid = Catraca::normalizarUid($campo('cartao'));

            if ($nome === '') {
                $erros[] = ['linha' => $n, 'motivo' => 'nome em branco'];
                continue;
            }
            if (mb_strlen($nome) > 150) {
                $erros[] = ['linha' => $n, 'motivo' => 'nome com mais de 150 caracteres'];
                continue;
            }
            if ($cargo === '') {
                $erros[] = ['linha' => $n, 'motivo' => 'cargo em branco'];
                continue;
            }
            if (mb_strlen($cargo) > 100) {
                $erros[] = ['linha' => $n, 'motivo' => 'cargo com mais de 100 caracteres'];
                continue;
            }
            if (mb_strlen($matricula) > 30) {
                $erros[] = ['linha' => $n, 'motivo' => 'matrícula com mais de 30 caracteres'];
                continue;
            }

            $categoria = self::categoria($textoCategoria);
            if ($categoria === null) {
                $erros[] = ['linha' => $n, 'motivo' => 'categoria "' . self::limpar($textoCategoria) . '" inválida (use operador, supervisor ou prestador)'];
                continue;
            }

            if ($uid !== '' && !preg_match(Pessoa::PADRAO_UID, $uid)) {
                $erros[] = ['linha' => $n, 'motivo' => 'cartão "' . $uid . '" inválido (use letras, números, hífen ou sublinhado, de 4 a 100 caracteres)'];
                continue;
            }
            if ($uid !== '' && isset($uidsVistos[$uid])) {
                $erros[] = ['linha' => $n, 'motivo' => 'o cartão ' . $uid . ' já aparece na linha ' . $uidsVistos[$uid]];
                continue;
            }
            $chaveMatricula = mb_strtolower($matricula);
            if ($matricula !== '' && isset($matriculasVistas[$chaveMatricula])) {
                $erros[] = ['linha' => $n, 'motivo' => 'a matrícula ' . $matricula . ' já aparece na linha ' . $matriculasVistas[$chaveMatricula]];
                continue;
            }

            if ($uid !== '') {
                $uidsVistos[$uid] = $n;
            }
            if ($matricula !== '') {
                $matriculasVistas[$chaveMatricula] = $n;
            }

            $linhas[] = [
                'linha'     => $n,
                'nome'      => $nome,
                'cargo'     => $cargo,
                'categoria' => $categoria,
                'matricula' => $matricula !== '' ? $matricula : null,
                'cartao'    => $uid !== '' ? $uid : null,
            ];
        }

        return ['linhas' => $linhas, 'erros' => $erros, 'total' => count($dados)];
    }

    // ─── Texto ───────────────────────────────────────────────────────────────

    /** Excel em português costuma salvar CSV em Windows-1252; converte para UTF-8 e tira o BOM. */
    private static function paraUtf8(string $conteudo): string
    {
        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        return preg_replace('/^\xEF\xBB\xBF/', '', $conteudo);
    }

    /**
     * Registros do CSV com o número da linha no arquivo (o cabeçalho é a linha 1).
     * @return array<int, array{linha: int, campos: array}>
     */
    private static function registros(string $conteudo): array
    {
        $primeira = strtok($conteudo, "\n") ?: '';
        $separador = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';

        $fluxo = fopen('php://temp', 'r+');
        fwrite($fluxo, $conteudo);
        rewind($fluxo);

        $registros = [];
        $n = 0;
        while (($campos = fgetcsv($fluxo, 0, $separador, '"', '')) !== false) {
            $n++;
            if ($campos === [null] || trim(implode('', $campos)) === '') {
                continue; // linha em branco
            }
            $registros[] = ['linha' => $n, 'campos' => $campos];
        }
        fclose($fluxo);

        return $registros;
    }

    /** Espaços repetidos viram um só, sem caracteres de controle nas pontas nem no meio. */
    private static function limpar(string $texto): string
    {
        $texto = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto);
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    private static function semAcento(string $texto): string
    {
        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /** "Operador", "supervisora", "Prestador de Serviço"... → chave do banco; vazio = operador; desconhecido = null. */
    private static function categoria(string $texto): ?string
    {
        $t = self::semAcento(mb_strtolower(self::limpar($texto)));

        return match (true) {
            $t === '' => 'operador',
            str_starts_with($t, 'oper') => 'operador',
            str_starts_with($t, 'superv') => 'supervisor',
            str_starts_with($t, 'prest') => 'prestador',
            default => null,
        };
    }
}
