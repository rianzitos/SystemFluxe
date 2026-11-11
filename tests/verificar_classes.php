<?php
/**
 * Confere se cada arquivo de app/models, controllers, middleware e helpers define uma classe (ou trait)
 * com o MESMO nome do arquivo. O carregamento automático (config/app.php) depende disso: se o nome
 * da classe tiver um erro de digitação (ex.: "class CsrfMiddlewaregit"), o PHP responde
 * 'Class "CsrfMiddleware" not found'.
 *
 * Rode antes de subir mudanças:   php tests/verificar_classes.php
 * Sai com código 1 se achar algum problema.
 */

$pastas = ['models', 'controllers', 'middleware', 'helpers'];
$problemas = 0;

foreach ($pastas as $pasta) {
    foreach (glob(__DIR__ . "/../app/$pasta/*.php") as $arquivo) {
        $nome = basename($arquivo, '.php');
        $codigo = (string) file_get_contents($arquivo);
        if (trim($codigo) === '') {
            continue;   // arquivo vazio (ainda não implementado)
        }

        $declaradas = [];
        $tokens = token_get_all($codigo);
        foreach ($tokens as $i => $t) {
            if (is_array($t) && in_array($t[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $declaradas[] = $tokens[$j][1];
                        break;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }
                    break;   // "class" usado como ::class ou classe anônima
                }
            }
        }

        if (!in_array($nome, $declaradas, true)) {
            $problemas++;
            echo "ERRO  app/$pasta/$nome.php não define a classe \"$nome\"" . ($declaradas ? ' (define: ' . implode(', ', $declaradas) . ')' : '') . "\n";
        }
    }
}

echo $problemas === 0 ? "OK  todas as classes batem com o nome do arquivo\n" : "$problemas problema(s) encontrado(s)\n";
exit($problemas === 0 ? 0 : 1);
