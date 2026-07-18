<?php

class Database
{
    private static ?PDO $instance = null;

    public static function connect(): PDO
    {
        if (self::$instance === null) {
            $env = parse_ini_file(__DIR__ . '/../config/.env');
// parse_ini_file: Lê o arquivo .env para carregar as credenciais secretas do banco de 
// dados (host, usuário e senha).
            $dsn = "mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_NAME']};charset=utf8mb4";
// $dsn: Monta a string de endereço de conexão com o MySQL definindo servidor, banco e suporte a acentos
            self::$instance = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
                // new PDO: Abre a conexão oficial configurada para disparar exceções em caso de erros e organizar os dados em arrays associativos.
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$instance;

        // $instance e if: Aplica o padrão Singleton, garantindo que a conexão com o banco
        //  seja aberta apenas uma vez para economizar memória.
    }
}
