<?php

/**
 * Layout dos e-mails do SICAPDA (mesma identidade do site: preto #171717 e amarelo #FFC400).
 *
 * Cada e-mail é montado como uma lista de blocos e sai em duas versões, HTML e texto puro, porque alguns
 * leitores (e filtros de spam) só olham o texto. O HTML usa tabelas e estilos inline: é o que funciona no
 * Gmail, Outlook e celulares. Nada de imagens obrigatórias (muitos leitores bloqueiam por padrão).
 *
 * Uso:
 *   $m = new EmailModelo('Título', 'Pré-visualização na caixa de entrada');
 *   $m->paragrafo('Olá, Maria.')->destaque('aviso', 'Desperdício alto', '12,4% ontem')->botao('Abrir painel', $url);
 *   Email::enviar($para, $assunto, $m->html(), $m->texto());
 */
class EmailModelo
{
    private const AMARELO = '#FFC400';
    private const PRETO = '#171717';
    private const CORES = [
        'aviso'   => ['#FFF4E5', '#B45309'],
        'erro'    => ['#FDECEC', '#B42318'],
        'sucesso' => ['#E8F7EE', '#1E7B45'],
        'info'    => ['#F3F3F3', '#333333'],
    ];

    private array $html = [];
    private array $texto = [];

    public function __construct(private string $titulo, private string $previa = '')
    {
    }

    public static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function paragrafo(string $texto, bool $suave = false): static
    {
        $cor = $suave ? '#666666' : '#222222';
        $this->html[] = '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:' . $cor . ';">'
            . nl2br(self::e($texto)) . '</p>';
        $this->texto[] = $texto;
        return $this;
    }

    public function subtitulo(string $texto): static
    {
        $this->html[] = '<h2 style="margin:24px 0 10px;font-size:16px;color:' . self::PRETO . ';">' . self::e($texto) . '</h2>';
        $this->texto[] = mb_strtoupper($texto);
        return $this;
    }

    /** Caixa colorida com título e descrição (tipo: aviso | erro | sucesso | info). */
    public function destaque(string $tipo, string $titulo, string $descricao = ''): static
    {
        [$fundo, $cor] = self::CORES[$tipo] ?? self::CORES['info'];
        $this->html[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">'
            . '<tr><td style="background:' . $fundo . ';border-left:4px solid ' . $cor . ';border-radius:6px;padding:14px 16px;">'
            . '<div style="font-size:15px;font-weight:bold;color:' . $cor . ';">' . self::e($titulo) . '</div>'
            . ($descricao !== '' ? '<div style="margin-top:4px;font-size:14px;line-height:1.5;color:#333333;">' . nl2br(self::e($descricao)) . '</div>' : '')
            . '</td></tr></table>';
        $this->texto[] = '» ' . $titulo . ($descricao !== '' ? "\n  " . $descricao : '');
        return $this;
    }

    /** Indicadores lado a lado: [['rotulo' => 'Acessos', 'valor' => '124'], ...] (até 3 por linha). */
    public function indicadores(array $itens): static
    {
        $linhas = array_chunk($itens, 3);
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">';
        foreach ($linhas as $linha) {
            $html .= '<tr>';
            foreach ($linha as $item) {
                $html .= '<td width="' . floor(100 / count($linha)) . '%" style="padding:4px;">'
                    . '<div style="background:#F6F6F6;border-radius:8px;padding:12px 10px;text-align:center;">'
                    . '<div style="font-size:20px;font-weight:bold;color:' . self::PRETO . ';">' . self::e((string) $item['valor']) . '</div>'
                    . '<div style="margin-top:2px;font-size:12px;color:#666666;">' . self::e($item['rotulo']) . '</div>'
                    . '</div></td>';
            }
            $html .= '</tr>';
        }
        $this->html[] = $html . '</table>';
        $this->texto[] = implode("\n", array_map(fn($i) => '- ' . $i['rotulo'] . ': ' . $i['valor'], $itens));
        return $this;
    }

    /** Tabela simples: $cabecalho = ['Refeição', 'Desperdício'], $linhas = [['Almoço', '12 kg'], ...]. */
    public function tabela(array $cabecalho, array $linhas): static
    {
        $th = 'padding:8px 10px;font-size:12px;text-align:left;color:#666666;border-bottom:2px solid #E5E5E5;';
        $td = 'padding:8px 10px;font-size:14px;color:#222222;border-bottom:1px solid #EEEEEE;';
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border-collapse:collapse;"><tr>';
        foreach ($cabecalho as $c) {
            $html .= '<th style="' . $th . '">' . self::e($c) . '</th>';
        }
        $html .= '</tr>';
        foreach ($linhas as $linha) {
            $html .= '<tr>' . implode('', array_map(fn($c) => '<td style="' . $td . '">' . self::e((string) $c) . '</td>', $linha)) . '</tr>';
        }
        $this->html[] = $html . '</table>';
        $this->texto[] = implode("\n", array_map(fn($l) => '- ' . implode(' | ', $l), $linhas));
        return $this;
    }

    /** Lista de citação (ex.: a mensagem enviada pelo formulário de contato). */
    public function citacao(string $texto): static
    {
        $this->html[] = '<div style="margin:0 0 16px;padding:14px 16px;background:#F6F6F6;border-radius:8px;font-size:14px;line-height:1.6;color:#222222;">'
            . nl2br(self::e($texto)) . '</div>';
        $this->texto[] = preg_replace('/^/m', '> ', $texto);
        return $this;
    }

    public function botao(string $rotulo, string $url): static
    {
        $this->html[] = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 20px;"><tr>'
            . '<td style="border-radius:8px;background:' . self::AMARELO . ';">'
            . '<a href="' . self::e($url) . '" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:bold;color:' . self::PRETO . ';text-decoration:none;border-radius:8px;">'
            . self::e($rotulo) . '</a></td></tr></table>';
        $this->texto[] = $rotulo . ': ' . $url;
        return $this;
    }

    public function html(string $rodape = ''): string
    {
        $rodape = $rodape !== '' ? $rodape : 'Você recebeu este e-mail por ter uma conta no SICAPDA.';
        $previa = $this->previa !== ''
            ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . self::e($this->previa) . '</div>'
            : '';

        return '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . self::e($this->titulo) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#EFEFEF;font-family:Arial,Helvetica,sans-serif;">' . $previa
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EFEFEF;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:12px;overflow:hidden;">'
            // Cabeçalho com a marca em texto (não depende de imagem carregar)
            . '<tr><td style="background:' . self::PRETO . ';padding:22px 28px;">'
            . '<span style="font-size:22px;font-weight:bold;letter-spacing:1px;color:#FFFFFF;">SICA<span style="color:' . self::AMARELO . ';">PDA</span></span>'
            . '<span style="float:right;font-size:12px;line-height:28px;color:#A1A1A1;">by <span style="color:' . self::AMARELO . ';">Fluxe</span></span>'
            . '</td></tr>'
            . '<tr><td style="height:4px;background:' . self::AMARELO . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:28px;">'
            . '<h1 style="margin:0 0 18px;font-size:21px;line-height:1.3;color:' . self::PRETO . ';">' . self::e($this->titulo) . '</h1>'
            . implode('', $this->html)
            . '</td></tr>'
            . '<tr><td style="padding:18px 28px;background:#F6F6F6;font-size:12px;line-height:1.5;color:#888888;">'
            . self::e($rodape) . '<br>Fluxe Soluções Inteligentes · Mococa, SP'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    public function texto(string $rodape = ''): string
    {
        $rodape = $rodape !== '' ? $rodape : 'Você recebeu este e-mail por ter uma conta no SICAPDA.';
        return 'SICAPDA by Fluxe' . "\n" . str_repeat('=', 40) . "\n\n"
            . $this->titulo . "\n\n"
            . implode("\n\n", $this->texto) . "\n\n"
            . str_repeat('-', 40) . "\n" . $rodape . "\nFluxe Soluções Inteligentes · Mococa, SP\n";
    }
}
