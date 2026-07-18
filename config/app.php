<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Autoload simples por convenção de pastas
spl_autoload_register(function (string $class): void {
    // Monitora o sistema e é ativada automaticamente sempre
    // que você tenta usar uma classe que o PHP ainda não conhece.
    $dirs = [
        __DIR__ . '/../app/models/',
        __DIR__ . '/../app/controllers/',
        __DIR__ . '/../app/middleware/',
    ];
    // Lista todas as pastas do seu projeto onde os seus arquivos de código
    // (models, controllers e middleware) ficam guardados.
    foreach ($dirs as $dir) {
        // Percorre cada uma dessas pastas procurando um arquivo PHP que tenha
        // exatamente o mesmo nome da classe que você tentou usar.
        $file = $dir . $class . '.php';
        if (file_exists($file)) {
            // Se encontrar o arquivo, faz o require_once dele para trazê-lo ao sistema
            // e interrompe a busca.
            require_once $file;
            return;
        }
    }
});

require_once __DIR__ . '/database.php';