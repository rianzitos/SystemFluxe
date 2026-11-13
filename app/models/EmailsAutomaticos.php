<?php

/**
 * Todos os e-mails que o próprio SICAPDA envia (o conteúdo e para quem vai):
 *
 *   Contato        contatoEquipe(), contatoConfirmacao()  ← formulário das páginas públicas (Contato)
 *   Conta          boasVindas() no cadastro da empresa, redefinirSenha() e senhaAlterada()
 *   Alertas        desperdicioAlto()  ← quando o desperdício do dia passa da meta (balança ou tarefa agendada)
 *   Resumo         resumoDiario()     ← às 8h, para quem ligou "Resumo diário por e-mail"
 *
 * Os alertas e o resumo vão para cada usuário da empresa que deixou a notificação ligada em
 * Configurações → Notificações (site ou aplicativo) e são registrados em `emails_enviados`, que impede o
 * mesmo aviso de chegar duas vezes. A tarefa agendada (bin/emails.php) chama rotina().
 */
class EmailsAutomaticos
{
    /** Hora a partir da qual o resumo do dia anterior é enviado. */
    public const HORA_RESUMO = 8;

    /** Sem produção informada, "desperdício alto" = 30% acima da média diária dos últimos 30 dias. */
    private const FATOR_MEDIA = 1.3;

    // ─── Contato (páginas públicas) ──────────────────────────────────────────

    public static function contatoEquipe(array $c): bool
    {
        $origem = Contato::ORIGENS[$c['origem']] ?? $c['origem'];
        $m = (new EmailModelo('Nova mensagem pelo site', $c['nome'] . ': ' . mb_substr($c['mensagem'], 0, 90)))
            ->paragrafo('Chegou uma mensagem pelo formulário de contato (' . $origem . '). Responda este e-mail para falar direto com a pessoa.')
            ->indicadores(array_values(array_filter([
                ['rotulo' => 'Nome', 'valor' => $c['nome']],
                ['rotulo' => 'E-mail', 'valor' => $c['email']],
                !empty($c['telefone']) ? ['rotulo' => 'Telefone', 'valor' => $c['telefone']] : null,
            ])))
            ->citacao($c['mensagem'])
            ->paragrafo('Recebida em ' . date('d/m/Y \à\s H:i', strtotime($c['criado_em'] ?? 'now')) . '.', true);

        return Email::enviar(
            Email::destinoContato(),
            '[' . $origem . '] Contato de ' . $c['nome'],
            $m->html('Mensagem enviada pelo formulário de contato do site.'),
            $m->texto('Mensagem enviada pelo formulário de contato do site.'),
            ['reply_to' => $c['email'], 'reply_to_nome' => $c['nome']]
        );
    }

    /** Confirmação para o visitante. Não repete a mensagem dele (evita o formulário virar disparador de spam). */
    public static function contatoConfirmacao(array $c): bool
    {
        $m = (new EmailModelo('Recebemos a sua mensagem', 'Obrigado pelo contato! Respondemos em até 1 dia útil.'))
            ->paragrafo('Olá, ' . self::primeiroNome($c['nome']) . '!')
            ->paragrafo('Obrigado por falar com a Fluxe. A sua mensagem chegou ao nosso time e respondemos em até 1 dia útil, neste mesmo e-mail.')
            ->paragrafo('Enquanto isso, conheça como o SICAPDA une controle de acesso e previsão de demanda alimentar:')
            ->botao('Conhecer o SICAPDA', Env::urlSite() . '/sicapda')
            ->paragrafo('Se precisar de algo urgente, fale com a gente pelo WhatsApp (19) 99482-0921.', true);

        $rodape = 'Você recebeu este e-mail porque enviou uma mensagem pelo site da Fluxe. Se não foi você, ignore-o.';
        return Email::enviar($c['email'], 'Recebemos a sua mensagem — Fluxe', $m->html($rodape), $m->texto($rodape),
            ['reply_to' => Email::destinoContato()]);
    }

    // ─── Conta ───────────────────────────────────────────────────────────────

    /** Depois do cadastro da empresa: para o administrador (e o e-mail institucional, se for outro). */
    public static function boasVindas(string $nome, string $email, string $empresa, string $emailInstitucional = ''): void
    {
        $url = Env::urlSite();
        $m = (new EmailModelo('Bem-vindo ao SICAPDA!', 'A conta da ' . $empresa . ' está pronta. Veja os próximos passos.'))
            ->paragrafo('Olá, ' . self::primeiroNome($nome) . '!')
            ->paragrafo('A conta da ' . $empresa . ' foi criada e você é o administrador. Entre com o e-mail ' . $email . ' e a senha que escolheu no cadastro.')
            ->subtitulo('Próximos passos')
            ->tabela(['', 'Onde fazer'], [
                ['1. Cadastre as pessoas e os cartões', 'Pessoas → Cadastro (ou importe uma planilha)'],
                ['2. Conecte as catracas e a balança', 'Configurações → Integrações'],
                ['3. Instale o aplicativo', $url . '/sicapda#baixar'],
                ['4. Receba o resumo do dia por e-mail', 'Configurações → Notificações'],
            ])
            ->botao('Entrar no SICAPDA', $url . '/login')
            ->paragrafo('Dúvidas? Responda este e-mail que o nosso time ajuda.', true);

        $rodape = 'Você recebeu este e-mail porque a sua empresa foi cadastrada no SICAPDA.';
        Email::enviar($email, 'Bem-vindo ao SICAPDA, ' . self::primeiroNome($nome) . '!', $m->html($rodape), $m->texto($rodape),
            ['reply_to' => Email::destinoContato()]);

        if ($emailInstitucional !== '' && strcasecmp($emailInstitucional, $email) !== 0) {
            $c = (new EmailModelo('A ' . $empresa . ' agora usa o SICAPDA'))
                ->paragrafo('Este e-mail institucional foi informado no cadastro da ' . $empresa . ' no SICAPDA, o sistema de controle de acesso e previsão de demanda alimentar da Fluxe.')
                ->paragrafo('O administrador da conta é ' . $nome . ' (' . $email . '). Se você não reconhece este cadastro, responda este e-mail.')
                ->botao('Conhecer o SICAPDA', $url . '/sicapda');
            Email::enviar($emailInstitucional, 'Cadastro da ' . $empresa . ' no SICAPDA', $c->html($rodape), $c->texto($rodape),
                ['reply_to' => Email::destinoContato()]);
        }
    }

    public static function redefinirSenha(string $nome, string $email, string $link, int $minutos): bool
    {
        $m = (new EmailModelo('Redefinir a sua senha', 'Use o link em até ' . $minutos . ' minutos para criar uma senha nova.'))
            ->paragrafo('Olá, ' . self::primeiroNome($nome) . '!')
            ->paragrafo('Recebemos um pedido para redefinir a senha da sua conta no SICAPDA. Clique no botão abaixo para criar uma senha nova:')
            ->botao('Criar senha nova', $link)
            ->destaque('info', 'O link vale por ' . $minutos . ' minutos e só pode ser usado uma vez.',
                'Se você não pediu, ignore este e-mail: a sua senha atual continua valendo.')
            ->paragrafo('Se o botão não abrir, copie este endereço no navegador: ' . $link, true);

        $rodape = 'E-mail de segurança da sua conta no SICAPDA. Nunca pedimos a sua senha por e-mail.';
        return Email::enviar($email, 'Redefinir a senha do SICAPDA', $m->html($rodape), $m->texto($rodape));
    }

    /** Aviso de segurança depois de qualquer troca de senha (Configurações no site, no app ou pelo link do e-mail). */
    public static function senhaAlterada(int $usuarioId, string $como): void
    {
        $u = (new Usuario())->buscarPorId($usuarioId);
        if (!$u) {
            return;
        }
        $m = (new EmailModelo('A sua senha foi alterada', 'A senha da sua conta no SICAPDA acabou de ser trocada.'))
            ->paragrafo('Olá, ' . self::primeiroNome($u['nome']) . '!')
            ->paragrafo('A senha da sua conta no SICAPDA (' . $u['email'] . ') foi alterada em ' . date('d/m/Y \à\s H:i') . ' ' . $como . '.')
            ->destaque('aviso', 'Não foi você?',
                'Redefina a senha agora pelo link abaixo e avise o administrador da sua empresa.')
            ->botao('Redefinir a senha', Env::urlSite() . '/senha/esqueci');

        $rodape = 'E-mail de segurança da sua conta no SICAPDA. Ele é enviado sempre que a senha muda.';
        Email::enviar($u['email'], 'A sua senha do SICAPDA foi alterada', $m->html($rodape), $m->texto($rodape));
    }

    // ─── Desperdício alto ────────────────────────────────────────────────────

    /**
     * O desperdício do dia está alto? Duas regras:
     *   - com produção informada (kg produzidos): % desperdiçado acima da meta (Previsao::META_DESPERDICIO);
     *   - só com a balança (sem produção): kg desperdiçados 30% acima da média diária dos últimos 30 dias.
     * Devolve null (normal ou sem dados) ou os números para o e-mail.
     */
    public static function avaliarDesperdicio(int $empresaId, string $data): ?array
    {
        $db = Database::connect();
        $stmt = $db->prepare(
            'SELECT tipo, quantidade_produzida_kg AS produzido, quantidade_desperdicada_kg AS desperdicado
             FROM producao_refeicoes WHERE empresa_id = ? AND data = ?'
        );
        $stmt->execute([$empresaId, $data]);
        $refeicoes = $stmt->fetchAll();

        $kg = array_sum(array_map(fn($r) => (float) $r['desperdicado'], $refeicoes));
        $produzido = array_sum(array_map(fn($r) => (float) $r['produzido'], $refeicoes));
        if ($kg <= 0) {
            return null;
        }

        $resultado = ['data' => $data, 'kg' => $kg, 'produzido' => $produzido, 'refeicoes' => $refeicoes, 'pct' => null, 'media' => null];

        if ($produzido > 0) {
            $resultado['pct'] = $kg / $produzido * 100;
            return $resultado['pct'] > Previsao::META_DESPERDICIO ? $resultado : null;
        }

        $stmt = $db->prepare(
            'SELECT AVG(total) AS media, COUNT(*) AS dias FROM (
                 SELECT SUM(quantidade_desperdicada_kg) AS total FROM producao_refeicoes
                 WHERE empresa_id = ? AND data BETWEEN ? AND ? GROUP BY data HAVING total > 0
             ) d'
        );
        $dia = new DateTimeImmutable($data);
        $stmt->execute([$empresaId, $dia->modify('-30 days')->format('Y-m-d'), $dia->modify('-1 day')->format('Y-m-d')]);
        $hist = $stmt->fetch();

        // Precisa de pelo menos 5 dias de histórico para a média dizer alguma coisa.
        if ((int) $hist['dias'] < 5 || (float) $hist['media'] <= 0) {
            return null;
        }
        $resultado['media'] = (float) $hist['media'];
        return ($kg > $resultado['media'] * self::FATOR_MEDIA && $kg - $resultado['media'] >= 1) ? $resultado : null;
    }

    /**
     * Confere o desperdício do dia e, se estiver alto, avisa por e-mail quem ligou o alerta (uma vez por
     * empresa e por dia). Chamado logo depois de cada pesagem da balança e pela tarefa agendada. Devolve
     * quantos e-mails saíram.
     */
    public static function desperdicioAlto(int $empresaId, string $data): int
    {
        $dados = self::avaliarDesperdicio($empresaId, $data);
        if ($dados === null) {
            return 0;
        }

        $dia = new DateTimeImmutable($data);
        $diaTexto = $dia->format('d/m');
        $n = fn(float $v, int $c = 1) => Previsao::n($v, $c);

        if ($dados['pct'] !== null) {
            $resumo = $n($dados['pct']) . '% da produção foi desperdiçada (meta: até ' . $n(Previsao::META_DESPERDICIO, 0) . '%).';
            $excesso = $dados['kg'] - $dados['produzido'] * Previsao::META_DESPERDICIO / 100;
            $detalhe = 'Foram ' . $n($dados['kg']) . ' kg de ' . $n($dados['produzido']) . ' kg produzidos: cerca de ' . $n($excesso) . ' kg acima da meta.';
        } else {
            $acima = ($dados['kg'] / $dados['media'] - 1) * 100;
            $resumo = $n($dados['kg']) . ' kg desperdiçados, ' . $n($acima, 0) . '% acima da média diária.';
            $detalhe = 'A média dos últimos 30 dias é de ' . $n($dados['media']) . ' kg por dia.';
        }

        $linhas = [];
        foreach ($dados['refeicoes'] as $r) {
            if ((float) $r['desperdicado'] <= 0 && (float) $r['produzido'] <= 0) {
                continue;
            }
            $pct = (float) $r['produzido'] > 0 ? $n((float) $r['desperdicado'] / (float) $r['produzido'] * 100) . '%' : '—';
            $linhas[] = [Producao::TIPOS[$r['tipo']] ?? $r['tipo'],
                (float) $r['produzido'] > 0 ? $n((float) $r['produzido']) . ' kg' : '—',
                $n((float) $r['desperdicado']) . ' kg', $pct];
        }

        $empresa = self::nomeEmpresa($empresaId);
        $assunto = 'Alerta: desperdício alto em ' . $diaTexto . ' — ' . $empresa;

        $enviados = 0;
        foreach (self::destinatarios($empresaId, 'email_desperdicio') as $u) {
            if (!self::reservar($empresaId, (int) $u['id'], 'desperdicio_alto', $empresaId . ':' . $data, $u['email'])) {
                continue;   // já avisado hoje
            }
            $m = (new EmailModelo('Desperdício alto em ' . $diaTexto, $resumo))
                ->paragrafo('Olá, ' . self::primeiroNome($u['nome']) . '. O desperdício de comida da ' . $empresa . ' em '
                    . Datas::nomeDiaSemana($dia) . ', ' . $dia->format('d/m/Y') . ', passou do esperado.')
                ->destaque('aviso', $resumo, $detalhe)
                ->subtitulo('Por refeição')
                ->tabela(['Refeição', 'Produzido', 'Desperdiçado', '%'], $linhas)
                ->subtitulo('O que fazer')
                ->paragrafo("• Compare a quantidade produzida com a previsão do painel para os próximos dias.\n"
                    . "• Veja no assistente a previsão de amanhã (pergunte \"previsão para amanhã\").\n"
                    . '• Confira se houve evento, feriado ou falta de pessoas que explique a sobra.')
                ->botao('Ver a análise mensal', Env::urlSite() . '/acessos');

            $rodape = 'Você recebe este alerta porque "Desperdício alto por e-mail" está ligado em Configurações → Notificações.';
            if (Email::enviar($u['email'], $assunto, $m->html($rodape), $m->texto($rodape))) {
                $enviados++;
            } else {
                self::liberar('desperdicio_alto', $empresaId . ':' . $data, $u['email']);
            }
        }
        return $enviados;
    }

    // ─── Resumo diário ───────────────────────────────────────────────────────

    /** Resumo de ontem (acessos, refeitório, dispositivos) + previsão de hoje para uma pessoa. */
    public static function resumoDiario(array $u, ?DateTimeImmutable $hoje = null): bool
    {
        $hoje ??= new DateTimeImmutable('today');
        $ontem = $hoje->modify('-1 day');
        $dataOntem = $ontem->format('Y-m-d');
        $empresaId = (int) $u['empresa_id'];

        if (!self::reservar($empresaId, (int) $u['id'], 'resumo_diario', $hoje->format('Y-m-d'), $u['email'])) {
            return false;   // já recebeu o de hoje
        }

        $acesso = new Acesso();
        $entradas = $acesso->contarEntradas($empresaId, $dataOntem, $dataOntem);
        $pessoas = $acesso->pessoasPorDia($empresaId, $dataOntem, $dataOntem)[$dataOntem] ?? 0;
        $stmt = Database::connect()->prepare(
            "SELECT COUNT(*) FROM logs_acesso WHERE empresa_id = ? AND resultado = 'negado' AND data_hora >= ? AND data_hora < ?"
        );
        $stmt->execute([$empresaId, $dataOntem . ' 00:00:00', $hoje->format('Y-m-d') . ' 00:00:00']);
        $negados = (int) $stmt->fetchColumn();

        $prod = (new Producao())->totais($empresaId, $dataOntem, $dataOntem);
        $previsao = new Previsao($empresaId, $hoje);
        $hojePrev = $previsao->previsaoDoDia($hoje);
        $n = fn(float $v, int $c = 0) => Previsao::n($v, $c);

        $empresa = self::nomeEmpresa($empresaId);
        $m = (new EmailModelo('Resumo de ' . Datas::nomeDiaSemana($ontem) . ', ' . $ontem->format('d/m'),
            $entradas . ' entradas, ' . $pessoas . ' pessoas e a previsão de hoje.'))
            ->paragrafo('Bom dia, ' . self::primeiroNome($u['nome']) . '! Este é o resumo de ontem da ' . $empresa . '.')
            ->subtitulo('Acessos')
            ->indicadores([
                ['rotulo' => 'Entradas', 'valor' => $n($entradas)],
                ['rotulo' => 'Pessoas', 'valor' => $n($pessoas)],
                ['rotulo' => 'Acessos negados', 'valor' => $n($negados)],
            ]);

        if ($prod['produzido'] > 0 || $prod['desperdicado'] > 0) {
            $pct = $prod['produzido'] > 0 ? $prod['desperdicado'] / $prod['produzido'] * 100 : null;
            $m->subtitulo('Refeitório')->indicadores([
                ['rotulo' => 'Produzido', 'valor' => $prod['produzido'] > 0 ? $n($prod['produzido'], 1) . ' kg' : '—'],
                ['rotulo' => 'Desperdiçado', 'valor' => $n($prod['desperdicado'], 1) . ' kg'],
                ['rotulo' => 'Desperdício', 'valor' => $pct !== null ? $n($pct, 1) . '%' : '—'],
            ]);
            if ($pct !== null && $pct > Previsao::META_DESPERDICIO) {
                $m->destaque('aviso', 'Desperdício acima da meta', $n($pct, 1) . '% ontem; a meta é de até ' . $n(Previsao::META_DESPERDICIO) . '%.');
            }
        }

        $m->subtitulo('Previsão para hoje');
        if (!empty($hojePrev['feriado'])) {
            $m->destaque('info', 'Feriado: ' . $hojePrev['feriado'], 'Previsão de operação mínima.');
        }
        $m->indicadores([
            ['rotulo' => 'Pessoas previstas', 'valor' => $n($hojePrev['pessoas'])],
            ['rotulo' => 'Refeições', 'valor' => $hojePrev['total_refeicoes'] > 0 ? $n($hojePrev['total_refeicoes']) : '—'],
            ['rotulo' => 'A produzir', 'valor' => $hojePrev['kg'] !== null ? $n($hojePrev['kg'], 1) . ' kg' : '—'],
        ]);

        foreach ((new Dispositivo())->semSinal($empresaId) as $d) {
            $m->destaque('erro', 'Dispositivo sem sinal: ' . $d['nome'], 'Último sinal ' . Datas::haQuanto($d['ultimo_ping']) . '.');
        }
        foreach (array_slice($previsao->alertas(), 0, 3) as $a) {
            $m->destaque($a['tipo'] === 'sucesso' ? 'sucesso' : ($a['tipo'] === 'aviso' ? 'aviso' : 'info'), $a['titulo'], $a['descricao']);
        }
        $m->botao('Abrir o painel', Env::urlSite() . '/painel');

        $rodape = 'Você recebe este resumo porque "Resumo diário por e-mail" está ligado em Configurações → Notificações (site ou aplicativo).';
        $ok = Email::enviar($u['email'], 'Resumo do dia ' . $ontem->format('d/m') . ' — ' . $empresa, $m->html($rodape), $m->texto($rodape));
        if (!$ok) {
            self::liberar('resumo_diario', $hoje->format('Y-m-d'), $u['email']);
        }
        return $ok;
    }

    // ─── Tarefa agendada ─────────────────────────────────────────────────────

    /**
     * O que a tarefa agendada faz a cada execução (o ideal é de hora em hora):
     *   1. reenvia mensagens de contato que falharam;
     *   2. confere o desperdício de ontem e de hoje em todas as empresas (o aviso sai uma vez por dia);
     *   3. a partir das 8h, envia o resumo diário a quem ligou e ainda não recebeu hoje.
     * Devolve o que foi feito, para o log do cron.
     */
    public static function rotina(?DateTimeImmutable $agora = null): array
    {
        $agora ??= new DateTimeImmutable();
        $hoje = new DateTimeImmutable($agora->format('Y-m-d'));
        $saida = ['contatos' => 0, 'desperdicio' => 0, 'resumos' => 0];

        try {
            $saida['contatos'] = (new Contato())->reenviarPendentes();
        } catch (PDOException $e) {
            error_log('SICAPDA rotina de e-mails (contatos): ' . $e->getMessage());
        }

        $empresas = Database::connect()->query('SELECT id FROM empresas')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($empresas as $empresaId) {
            foreach ([$hoje->modify('-1 day'), $hoje] as $dia) {
                $saida['desperdicio'] += self::desperdicioAlto((int) $empresaId, $dia->format('Y-m-d'));
            }
            if ((int) $agora->format('G') >= self::HORA_RESUMO) {
                foreach (self::destinatarios((int) $empresaId, 'resumo_diario') as $u) {
                    $saida['resumos'] += self::resumoDiario($u, $hoje) ? 1 : 0;
                }
            }
        }
        return $saida;
    }

    // ─── Apoio ───────────────────────────────────────────────────────────────

    /** Usuários da empresa com a notificação $chave ligada. */
    private static function destinatarios(int $empresaId, string $chave): array
    {
        $stmt = Database::connect()->prepare('SELECT id, nome, email, empresa_id FROM usuarios WHERE empresa_id = ?');
        $stmt->execute([$empresaId]);
        $preferencia = new Preferencia();

        return array_values(array_filter(
            $stmt->fetchAll(),
            fn($u) => filter_var($u['email'], FILTER_VALIDATE_EMAIL) && ($preferencia->obter((int) $u['id'])['notificacoes'][$chave] ?? false)
        ));
    }

    /**
     * Marca o e-mail como enviado ANTES de enviar (INSERT IGNORE na chave única): se duas execuções correrem
     * juntas, só uma consegue. false = já foi enviado (ou a migration 007 não foi rodada: aí não envia, para
     * nunca repetir alertas a cada hora).
     */
    private static function reservar(?int $empresaId, ?int $usuarioId, string $tipo, string $referencia, string $destino): bool
    {
        try {
            $stmt = Database::connect()->prepare(
                'INSERT IGNORE INTO emails_enviados (empresa_id, usuario_id, tipo, referencia, destinatario, criado_em)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$empresaId, $usuarioId, $tipo, $referencia, mb_strtolower($destino), date('Y-m-d H:i:s')]);
            return $stmt->rowCount() === 1;
        } catch (PDOException $e) {
            error_log('SICAPDA e-mails: emails_enviados indisponível (rode a migration 007): ' . $e->getMessage());
            return false;
        }
    }

    /** O envio falhou: tira a marca para a próxima execução tentar de novo. */
    private static function liberar(string $tipo, string $referencia, string $destino): void
    {
        try {
            Database::connect()->prepare('DELETE FROM emails_enviados WHERE tipo = ? AND referencia = ? AND destinatario = ?')
                ->execute([$tipo, $referencia, mb_strtolower($destino)]);
        } catch (PDOException $e) {
            error_log('SICAPDA e-mails: ' . $e->getMessage());
        }
    }

    private static function nomeEmpresa(int $empresaId): string
    {
        $e = (new Empresa())->buscarPorId($empresaId) ?: [];
        return ($e['nome_fantasia'] ?? '') ?: (($e['razao_social'] ?? '') ?: 'sua empresa');
    }

    private static function primeiroNome(string $nome): string
    {
        $primeiro = strtok(trim($nome), ' ') ?: $nome;
        return mb_substr($primeiro, 0, 40);
    }
}
