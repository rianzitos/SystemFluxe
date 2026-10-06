<?php

date_default_timezone_set('America/Sao_Paulo');

// ─── Cabeçalhos de segurança ────────────────────────────────────────────────
// Também são enviados pelo PHP (e não só pelo .htaccess) para valerem no
// servidor embutido e em qualquer servidor que não leia o .htaccess.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ─── Sessão com cookie endurecido ───────────────────────────────────────────
// HttpOnly (JS não lê o cookie), SameSite=Lax (barra CSRF cross-site em POST)
// e Secure quando o acesso é por HTTPS.
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
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
        __DIR__ . '/../app/helpers/',
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