<?php

/**
 * Dados compartilhados por todas as telas internas (menu lateral, usuário
 * logado, ícones SVG) — evita repetir o mesmo bloco em cada view.
 */
class Pagina
{
    public static function menu(): array
    {
        return [
            ['chave' => 'painel',     'rota' => '/painel',        'label' => 'Painel',         'icone' => 'bi-pie-chart-fill'],
            ['chave' => 'acessos',    'rota' => '/acessos',       'label' => 'Análise mensal', 'icone' => 'bi-calendar'],
            ['chave' => 'pessoas',    'rota' => '/pessoas',       'label' => 'Pessoas',        'icone' => 'bi-people-fill'],
            ['chave' => 'previsao',   'rota' => '/assistente',    'label' => 'Assistente IA',  'icone' => 'bi-chat-left'],
            ['chave' => 'relatorios', 'rota' => '/relatorios',    'label' => 'Relatórios',     'icone' => 'bi-clipboard-data'],
            ['chave' => 'config',     'rota' => '/configuracoes', 'label' => 'Configurações',  'icone' => 'bi-gear-fill'],
        ];
    }

    public static function empresaId(): int
    {
        return (int) ($_SESSION['empresa_id'] ?? 0);
    }

    public static function icone(string $nome): string
    {
        static $icones = [
            'menu'       => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'bell'       => '<path d="M6 8a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 12 6 8Z"/><path d="M10 18a2 2 0 0 0 4 0"/>',
            'calendar'   => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'chevron'    => '<path d="M6 9l6 6 6-6"/>',
            'search'     => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
            'filter'     => '<path d="M3 5h18l-7 8v6l-4 2v-8L3 5Z"/>',
            'download'   => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
            'clock'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
            'trend-up'   => '<path d="M4 15l5-5 4 4 7-7"/><path d="M15 7h5v5"/>',
            'trend-down' => '<path d="M4 9l5 5 4-4 7 7"/><path d="M15 17h5v-5"/>',
            'pulse'      => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
            'alert'      => '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 10v4M12 17h.01"/>',
            'send'       => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z"/>',
            'bolt'       => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
            'chevron-r'  => '<path d="M9 6l6 6-6 6"/>',
            'check'      => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>',
            'gear'       => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
            'badge'      => '<circle cx="12" cy="9" r="3.5"/><path d="M6 21c0-3 2.7-5 6-5s6 2 6 5"/>',
            'briefcase'  => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M3 13h18"/>',
        ];
        return $icones[$nome] ?? '';
    }

    /** <svg> completo (ícone de traço) para usar inline no HTML. */
    public static function svg(string $nome): string
    {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . self::icone($nome) . '</svg>';
    }
}
