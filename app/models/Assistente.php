<?php

/**
 * Assistente virtual do SICAPDA.
 *
 * Não é uma IA generativa: lê a pergunta, identifica a intenção por palavras-
 * chave (previsão, desperdício, precisão, presença, ajuda...) e a data citada
 * ("amanhã", "sexta", "15/10"), consulta o banco e responde com cálculos do
 * motor de previsão (Previsao). Tudo restrito à empresa do usuário logado.
 */
class Assistente
{
    private const DIAS_NOME = [
        'domingo' => 0, 'segunda' => 1, 'terca' => 2, 'quarta' => 3,
        'quinta' => 4, 'sexta' => 5, 'sabado' => 6,
    ];

    private int $empresaId;
    private DateTimeImmutable $hoje;
    private Previsao $previsao;
    private Acesso $acesso;
    private Producao $producao;

    public function __construct(int $empresaId, ?DateTimeImmutable $hoje = null)
    {
        $this->empresaId = $empresaId;
        $this->hoje      = $hoje ?? new DateTimeImmutable('today');
        $this->previsao  = new Previsao($empresaId, $this->hoje);
        $this->acesso    = new Acesso();
        $this->producao  = new Producao();
    }

    public function boasVindas(): string
    {
        return 'Olá! Sou o assistente inteligente do SICAPDA. Posso ajudá-lo a prever a quantidade de pessoas '
            . 'e alimentos necessários. Como posso ajudar?';
    }

    public function responder(string $mensagem): string
    {
        $mensagem = trim(mb_substr($mensagem, 0, 300));
        if ($mensagem === '') {
            return 'Digite uma pergunta, por exemplo: "previsão para amanhã".';
        }

        $t = self::normalizar($mensagem);

        if (preg_match('/\b(ajuda|help|o que (voce|vc) (faz|sabe)|comandos)\b/', $t)) {
            return $this->ajuda();
        }
        if (preg_match('/\b(desperdic|sobra|sobras|lixo|perda)/', $t)) {
            return $this->respostaDesperdicio();
        }
        if (preg_match('/\b(precisao|acuracia|confiavel|confianca|acerta)/', $t)) {
            return $this->respostaPrecisao();
        }
        if (preg_match('/\b(agora|presentes?|no local|quem esta)\b/', $t)) {
            return $this->respostaPresentes();
        }
        if (preg_match('/\b(pico|horario|movimentado|hora de maior)/', $t)) {
            return $this->respostaPico();
        }
        if (preg_match('/\b(alerta|evento|feriado|aviso)/', $t)) {
            return $this->respostaAlertas();
        }
        if (preg_match('/\b(semana|semanal|proximos dias|sete dias|7 dias)\b/', $t)) {
            return $this->respostaSemana();
        }

        $dia = $this->extrairData($t);
        $querPrevisao = preg_match('/(previs|prever|quant|quanto|vai ter|produzir|produc|preparar|comprar|refeic|pessoas|kg|alimento)/', $t);
        if ($dia !== null || $querPrevisao) {
            return $this->respostaDia($dia ?? $this->hoje->modify('+1 day'));
        }

        if (preg_match('/\b(oi|ola|bom dia|boa tarde|boa noite|e ai)\b/', $t)) {
            return "Olá! 😊 Posso informar a previsão de pessoas e de alimentos para qualquer dia. Experimente: \"previsão para amanhã\" ou \"previsão semanal\".";
        }
        if (preg_match('/\b(obrigad|valeu|ok)\b/', $t)) {
            return 'Por nada! Se precisar de mais alguma previsão, é só perguntar.';
        }

        return "Não entendi bem a pergunta. Tente algo como:\n• Previsão para amanhã\n• Previsão para sexta\n• Previsão semanal\n• Como está o desperdício?\n• Qual a precisão das previsões?";
    }

    // ─── Respostas ───────────────────────────────────────────────────────────

    private function ajuda(): string
    {
        return "Posso responder sobre:\n"
            . "• Previsão de pessoas e refeições (\"amanhã\", \"sexta\", \"15/10\")\n"
            . "• Previsão semanal\n"
            . "• Desperdício e recomendações de produção\n"
            . "• Precisão das previsões\n"
            . "• Quem está presente agora e horário de pico\n"
            . "• Feriados e alertas dos próximos dias";
    }

    private function respostaDia(DateTimeImmutable $dia): string
    {
        if ($dia < $this->hoje) {
            return $this->respostaPassado($dia);
        }

        $p = $this->previsao->previsaoDoDia($dia);
        $rotulo = $this->rotuloDia($dia);

        if ($p['feriado'] !== null) {
            $cab = "Previsão para {$rotulo} — feriado ({$p['feriado']}):\n";
        } else {
            $cab = "Previsão para {$rotulo}:\n";
        }

        if ($p['pessoas'] === 0) {
            return $cab . ($p['fim_de_semana']
                ? 'Não há operação prevista (fim de semana sem movimento no histórico). Não é necessário produzir refeições.'
                : 'Não há movimento previsto para este dia.');
        }

        $linhas = [$cab . '• Pessoas: cerca de ' . $p['pessoas']];

        if ($p['refeicoes']) {
            $partes = [];
            foreach ($p['refeicoes'] as $tipo => $qtd) {
                $partes[] = (Producao::TIPOS[$tipo] ?? $tipo) . ' ' . $qtd;
            }
            $linhas[] = '• Refeições: ' . implode(' · ', $partes) . ' (total ' . $p['total_refeicoes'] . ')';
        }

        if ($p['kg'] !== null) {
            $linhas[] = '• Produção recomendada: ~' . Previsao::n($p['kg'], 1) . ' kg';
        } else {
            $linhas[] = '• Ainda não há produção registrada para estimar os quilos.';
        }

        $linhas[] = $this->textoConfianca($p);

        return implode("\n", $linhas);
    }

    private function textoConfianca(array $p): string
    {
        if ($p['base'] === 'cadastro') {
            return '⚠ Confiança baixa: ainda não há histórico de acessos; estimativa baseada no cadastro da empresa.';
        }
        if ($p['base'] === 'media_geral') {
            return '⚠ Confiança baixa: sem registros neste dia da semana; usei a média geral dos dias úteis.';
        }
        $conf = ['alta' => 'alta', 'media' => 'média', 'baixa' => 'baixa'][$p['confianca']];
        return "Confiança {$conf} — baseada em {$p['amostras']} " . ($p['amostras'] === 1 ? 'dia' : 'dias')
            . ' semelhantes' . ($p['feriado'] ? ', ajustada para feriado.' : '.');
    }

    /** Pergunta sobre uma data passada: mostra o que de fato aconteceu. */
    private function respostaPassado(DateTimeImmutable $dia): string
    {
        $k = $dia->format('Y-m-d');
        $rotulo = $this->rotuloDia($dia);
        $pessoas = $this->acesso->pessoasPorDia($this->empresaId, $k, $k)[$k] ?? 0;
        $prod = $this->producao->serieDiaria($this->empresaId, $k, $k)[$k] ?? null;

        if ($pessoas === 0 && $prod === null) {
            return "Não há registros de acesso nem de produção em {$rotulo}.";
        }

        $linhas = ["Em {$rotulo} (já passou):", '• Pessoas que acessaram: ' . $pessoas];
        if ($prod) {
            $linhas[] = '• Produzido: ' . Previsao::n($prod['produzido'], 1) . ' kg (previsto ' . Previsao::n($prod['previsto'], 1) . ' kg)';
            $linhas[] = '• Desperdiçado: ' . Previsao::n($prod['desperdicado'], 1) . ' kg';
        }
        return implode("\n", $linhas);
    }

    private function respostaSemana(): string
    {
        $dias = $this->previsao->proximosDias(7);
        $linhas = ['Previsão para os próximos 7 dias:'];
        $totPessoas = 0;
        $totKg = 0.0;
        $temKg = false;

        foreach ($dias as $p) {
            $nome = ucfirst(Datas::nomeDiaSemana($p['data']));
            $d = $p['data']->format('d/m');
            if ($p['pessoas'] === 0) {
                $linhas[] = "• {$nome} ({$d}): sem operação" . ($p['feriado'] ? " — {$p['feriado']}" : '');
                continue;
            }
            $txt = "• {$nome} ({$d}): {$p['pessoas']} pessoas";
            if ($p['kg'] !== null) {
                $txt .= ', ~' . Previsao::n($p['kg'], 1) . ' kg';
                $totKg += $p['kg'];
                $temKg = true;
            }
            if ($p['feriado']) {
                $txt .= " ({$p['feriado']})";
            }
            $linhas[] = $txt;
            $totPessoas += $p['pessoas'];
        }

        $resumo = "Total da semana: {$totPessoas} pessoas";
        if ($temKg) {
            $resumo .= ', ~' . Previsao::n($totKg, 1) . ' kg de alimento';
        }
        $linhas[] = $resumo . '.';
        return implode("\n", $linhas);
    }

    private function respostaDesperdicio(): string
    {
        $ontem = $this->hoje->modify('-1 day')->format('Y-m-d');
        $t7  = $this->producao->totais($this->empresaId, $this->hoje->modify('-7 days')->format('Y-m-d'), $ontem);
        $t30 = $this->producao->totais($this->empresaId, $this->hoje->modify('-30 days')->format('Y-m-d'), $ontem);

        if ($t30['produzido'] <= 0) {
            return 'Ainda não há registros de produção para calcular o desperdício.';
        }

        $pct7  = $t7['produzido'] > 0 ? $t7['desperdicado'] / $t7['produzido'] * 100 : 0;
        $pct30 = $t30['desperdicado'] / $t30['produzido'] * 100;
        $meta  = Previsao::META_DESPERDICIO;

        $linhas = [
            'Desperdício:',
            '• Últimos 7 dias: ' . Previsao::n($t7['desperdicado'], 1) . ' kg (' . Previsao::n($pct7, 1) . '% da produção)',
            '• Últimos 30 dias: ' . Previsao::n($t30['desperdicado'], 1) . ' kg (' . Previsao::n($pct30, 1) . '%)',
            '• Meta: até ' . Previsao::n($meta, 0) . '%',
        ];

        if ($pct7 > $meta) {
            $excesso = $t7['desperdicado'] - $t7['produzido'] * $meta / 100;
            $linhas[] = '⚠ Acima da meta. Reduzir ~' . Previsao::n($excesso, 1) . ' kg por semana colocaria o desperdício na meta — siga a produção recomendada nas previsões.';
        } else {
            $linhas[] = '✔ Dentro da meta. Continue acompanhando as previsões diárias.';
        }
        return implode("\n", $linhas);
    }

    private function respostaPrecisao(): string
    {
        $r = $this->previsao->precisao();
        if ($r === null) {
            return 'Ainda não há dados suficientes (mínimo de 5 dias com movimento) para medir a precisão das previsões.';
        }
        return 'Precisão das previsões: ' . Previsao::n($r['precisao'], 1) . '%.' . "\n"
            . "Calculada refazendo a previsão de {$r['dias']} dias dos últimos 30 e comparando com o número real de pessoas.";
    }

    private function respostaPresentes(): string
    {
        $presentes = $this->acesso->presentesAgora($this->empresaId, $this->hoje->format('Y-m-d'));
        if (!$presentes) {
            return 'Nenhuma pessoa registrada no local neste momento.';
        }
        $por = [];
        foreach ($presentes as $p) {
            $por[$p['categoria']] = ($por[$p['categoria']] ?? 0) + 1;
        }
        $linhas = ['Agora há ' . count($presentes) . ' pessoas no local:'];
        foreach (Acesso::CATEGORIAS as $chave => $rotulo) {
            if (!empty($por[$chave])) {
                $linhas[] = '• ' . $rotulo . ': ' . $por[$chave];
            }
        }
        return implode("\n", $linhas);
    }

    private function respostaPico(): string
    {
        $resumo = $this->acesso->resumoRegistros(
            $this->empresaId,
            $this->hoje->modify('-30 days')->format('Y-m-d'),
            $this->hoje->format('Y-m-d'),
            '',
            ''
        );
        if ($resumo['pico_hora'] === null) {
            return 'Ainda não há acessos suficientes para identificar o horário de pico.';
        }
        $h = $resumo['pico_hora'];
        return sprintf("O pico de entradas nos últimos 30 dias ocorre entre %02d:00 e %02d:00.\nPlaneje a abertura do refeitório e a reposição considerando esse horário.", $h, $h + 1);
    }

    private function respostaAlertas(): string
    {
        $alertas = $this->previsao->alertas();
        if (!$alertas) {
            return 'Nenhum alerta no momento. Tudo dentro do esperado.';
        }
        $linhas = ['Alertas e recomendações:'];
        foreach (array_slice($alertas, 0, 6) as $a) {
            $linhas[] = '• ' . $a['titulo'] . ' — ' . $a['descricao'];
        }
        return implode("\n", $linhas);
    }

    // ─── Interpretação da data ───────────────────────────────────────────────

    private function extrairData(string $t): ?DateTimeImmutable
    {
        if (preg_match('/depois de amanha/', $t)) {
            return $this->hoje->modify('+2 days');
        }
        if (preg_match('/\bamanha\b/', $t)) {
            return $this->hoje->modify('+1 day');
        }
        if (preg_match('/\bhoje\b/', $t)) {
            return $this->hoje;
        }
        if (preg_match('/\bontem\b/', $t)) {
            return $this->hoje->modify('-1 day');
        }

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/', $t, $m)) {
            $dia = (int) $m[1];
            $mes = (int) $m[2];
            $ano = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $this->hoje->format('Y');
            if ($ano < 100) {
                $ano += 2000;
            }
            if (checkdate($mes, $dia, $ano)) {
                $d = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $ano, $mes, $dia));
                // "15/03" sem ano que já passou há muito → assume o próximo ano
                if (!isset($m[3]) && $d < $this->hoje->modify('-60 days')) {
                    $d = $d->modify('+1 year');
                }
                return $d;
            }
        }

        foreach (self::DIAS_NOME as $nome => $dow) {
            if (preg_match('/\b' . $nome . '(-feira)?s?\b/', $t)) {
                // próxima ocorrência estritamente depois de hoje
                $delta = ($dow - (int) $this->hoje->format('w') + 7) % 7;
                return $this->hoje->modify('+' . ($delta === 0 ? 7 : $delta) . ' days');
            }
        }
        return null;
    }

    private function rotuloDia(DateTimeImmutable $dia): string
    {
        $dif = (int) $this->hoje->diff($dia)->format('%r%a');
        $nome = Datas::nomeDiaSemana($dia) . ', ' . $dia->format('d/m');
        return match ($dif) {
            0 => 'hoje (' . $nome . ')',
            1 => 'amanhã (' . $nome . ')',
            default => $nome,
        };
    }

    /** minúsculas, sem acentos. */
    private static function normalizar(string $s): string
    {
        $s = mb_strtolower($s);
        return strtr($s, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }
}
