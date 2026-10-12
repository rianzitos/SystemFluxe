-- =============================================================================
-- SICAPDA by FLUXE — DADOS FICTÍCIOS (DEMO) para o painel   [versão 7 dias / turnos / limpeza total]
-- Rode no MySQL Workbench com TODO o script (ícone do raio ⚡, Ctrl+Shift+Enter).
-- Pode rodar de novo à vontade: a cada execução ele LIMPA e recria tudo da empresa.
--
-- Preenche TUDO: pessoas presentes agora, acessos, relatórios, refeições previstas,
-- kg produzidos e desperdiçados, gráficos, próximas horas e alertas. A empresa funciona
-- em turnos o dia todo, todos os dias, então o painel aparece completo em qualquer dia
-- e horário (inclusive fim de semana e de madrugada).
--
-- EMPRESA: por padrão vai para a primeira empresa. Para escolher outra, veja o
-- passo 1 (comente a linha de cima e use  SET @emp = 8;).
-- Descubra os ids com:  SELECT id, nome_fantasia FROM empresas;
--
-- ⚠ ATENÇÃO — A CADA EXECUÇÃO ELE APAGA, DA EMPRESA ESCOLHIDA (e de nenhuma outra):
--     funcionários, credenciais de acesso, dispositivos (catracas), logs de acesso e
--     produção de refeições. Use só em banco de teste/demonstração.
-- NÃO APAGA: usuários, empresas e metas de refeições (tabela refeicoes).
-- ALTERA na empresa escolhida (os valores de ANTES aparecem na consulta do passo 1):
--     marca "possui refeitório" e, se ela tiver menos de 40 colaboradores, ajusta para 45
--     (a previsão de demanda precisa disso); e preenche metas de refeição com média 0.
--
-- No final aparece uma tabela de DIAGNÓSTICO. Se "Pessoas presentes agora" ou "Metas de
-- refeição" aparecerem como 0, ou "Dias de acesso em fim de semana" for 0, você está rodando
-- um arquivo antigo: baixe o dados_exemplo.sql mais recente.
-- =============================================================================

USE fluxetea_controle_acesso;
SET NAMES utf8mb4;          -- garante os acentos (Produção, Natália...) em qualquer forma de importar
SET time_zone = '-03:00';   -- horário de Brasília (o do sistema), mesmo que o servidor do banco use outro fuso

SET SQL_SAFE_UPDATES = 0;   -- o Workbench bloqueia DELETE/UPDATE sem chave (erro 1175)

-- ─── 1) Empresa que receberá os dados ────────────────────────────────────────
-- Padrão: a primeira empresa. Para escolher outra, comente a linha de baixo
-- (coloque -- no começo) e tire o -- da seguinte, trocando o número pelo id.
SET @emp = (SELECT id FROM empresas ORDER BY id LIMIT 1);
-- SET @emp = 8;

-- Confere: tem que aparecer 1 linha com o nome da empresa (vazio = id inexistente).
-- As duas últimas colunas são os valores de ANTES; o script pode alterá-los (passo 3).
SELECT id, nome_fantasia AS empresa_escolhida,
       qtd_colaboradores AS colaboradores_antes, possui_refeitorio AS refeitorio_antes
  FROM empresas WHERE id = @emp;

-- ─── 2) LIMPA os dados da empresa escolhida (nenhuma outra empresa é tocada) ────
-- Mantém: usuários, empresas e metas de refeições.
DELETE FROM logs_acesso        WHERE empresa_id = @emp;
DELETE FROM producao_refeicoes WHERE empresa_id = @emp;
DELETE FROM dispositivos_iot   WHERE empresa_id = @emp;
DELETE FROM funcionarios       WHERE empresa_id = @emp;     -- apaga também as credenciais (CASCADE)

-- ─── 3) Empresa com refeitório + metas diárias de refeições ─────────────────
UPDATE empresas
   SET possui_refeitorio = 1,
       qtd_colaboradores = IF(qtd_colaboradores < 40, 45, qtd_colaboradores)
 WHERE id = @emp;

INSERT INTO refeicoes (empresa_id, tipo, media_diaria, criado_em)
SELECT @emp, t.tipo, t.media, NOW()
  FROM (SELECT 'cafe' AS tipo, 25 AS media UNION ALL SELECT 'almoco', 40
        UNION ALL SELECT 'jantar', 12 UNION ALL SELECT 'ceia', 4) t
 WHERE NOT EXISTS (SELECT 1 FROM refeicoes r WHERE r.empresa_id = @emp AND r.tipo = t.tipo);

-- Empresas cadastradas pelo assistente podem ter as metas gravadas com média 0 (campo em
-- branco). Com meta 0 a previsão de refeições fica em 0, então só essas metas zeradas
-- são preenchidas (metas que você já definiu não mudam).
UPDATE refeicoes SET media_diaria = 25 WHERE empresa_id = @emp AND tipo = 'cafe'   AND media_diaria = 0;
UPDATE refeicoes SET media_diaria = 40 WHERE empresa_id = @emp AND tipo = 'almoco' AND media_diaria = 0;
UPDATE refeicoes SET media_diaria = 12 WHERE empresa_id = @emp AND tipo = 'jantar' AND media_diaria = 0;
UPDATE refeicoes SET media_diaria =  4 WHERE empresa_id = @emp AND tipo = 'ceia'   AND media_diaria = 0;

-- ─── 4) Dispositivos IoT (catracas) ──────────────────────────────────────────
-- O token leva o id da empresa porque ele é único no banco inteiro: assim dá para
-- rodar o script para empresas diferentes sem um atrapalhar o outro.
INSERT INTO dispositivos_iot (empresa_id, nome, tipo, localizacao, token, status, ultimo_ping, criado_em) VALUES
  (@emp, 'Catraca Principal',    'rfid',    'Portaria principal', CONCAT('demo-catraca-principal-',  @emp), 'online', NOW(), NOW()),
  (@emp, 'Leitor do Refeitório', 'hibrido', 'Refeitório',         CONCAT('demo-leitor-refeitorio-',  @emp), 'online', NOW(), NOW());

SET @disp = LAST_INSERT_ID();   -- id da 'Catraca Principal' (1ª linha do INSERT acima); sem comparar texto, então não depende de collation

-- ─── 5) Funcionários (30 operadores, 6 supervisores, 4 prestadores) ──────────
INSERT INTO funcionarios (empresa_id, nome, matricula, cargo, categoria, ativo, criado_em) VALUES
  (@emp, 'João Silva',          'DEMO001', 'Operador de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Maria Santos',        'DEMO002', 'Operadora de Produção',    'operador',   1, '2026-01-05'),
  (@emp, 'Marcos Pereira',      'DEMO003', 'Operador de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Juliana Rocha',       'DEMO004', 'Operadora de Produção',    'operador',   1, '2026-01-05'),
  (@emp, 'Carla Mendes',        'DEMO005', 'Operadora de Produção',    'operador',   1, '2026-01-05'),
  (@emp, 'Rafael Souza',        'DEMO006', 'Operador de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Bruno Alves',         'DEMO007', 'Operador de Máquinas',     'operador',   1, '2026-01-05'),
  (@emp, 'Camila Torres',       'DEMO008', 'Operadora de Máquinas',    'operador',   1, '2026-01-05'),
  (@emp, 'Diego Ramos',         'DEMO009', 'Operador de Empilhadeira', 'operador',   1, '2026-01-05'),
  (@emp, 'Elisa Prado',         'DEMO010', 'Auxiliar de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Felipe Gomes',        'DEMO011', 'Auxiliar de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Gabriela Reis',       'DEMO012', 'Auxiliar de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Hugo Matos',          'DEMO013', 'Operador de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Isabela Cruz',        'DEMO014', 'Operadora de Produção',    'operador',   1, '2026-01-05'),
  (@emp, 'Lucas Barbosa',       'DEMO015', 'Operador de Embalagem',    'operador',   1, '2026-01-05'),
  (@emp, 'Larissa Cardoso',     'DEMO016', 'Operadora de Embalagem',   'operador',   1, '2026-01-05'),
  (@emp, 'Mateus Teixeira',     'DEMO017', 'Operador de Embalagem',    'operador',   1, '2026-01-05'),
  (@emp, 'Natália Freitas',     'DEMO018', 'Auxiliar de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Otávio Moreira',      'DEMO019', 'Operador de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Patrícia Duarte',     'DEMO020', 'Operadora de Produção',    'operador',   1, '2026-01-05'),
  (@emp, 'Renato Campos',       'DEMO021', 'Operador de Máquinas',     'operador',   1, '2026-01-05'),
  (@emp, 'Sabrina Lopes',       'DEMO022', 'Operadora de Produção',    'operador',   1, '2026-01-05'),
  (@emp, 'Thiago Martins',      'DEMO023', 'Operador de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Vanessa Ribeiro',     'DEMO024', 'Auxiliar de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'William Araújo',      'DEMO025', 'Operador de Empilhadeira', 'operador',   1, '2026-01-05'),
  (@emp, 'Yasmin Correia',      'DEMO026', 'Operadora de Embalagem',   'operador',   1, '2026-01-05'),
  (@emp, 'Alan Nogueira',       'DEMO027', 'Operador de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Bianca Farias',       'DEMO028', 'Operadora de Produção',    'operador',   1, '2026-01-05'),
  (@emp, 'Caio Monteiro',       'DEMO029', 'Auxiliar de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Daniela Pinto',       'DEMO030', 'Auxiliar de Produção',     'operador',   1, '2026-01-05'),
  (@emp, 'Carlos Oliveira',     'DEMO031', 'Supervisor de Linha',      'supervisor', 1, '2026-01-05'),
  (@emp, 'Ana Costa',           'DEMO032', 'Supervisora de Qualidade', 'supervisor', 1, '2026-01-05'),
  (@emp, 'Fernando Dias',       'DEMO033', 'Supervisor de Turno',      'supervisor', 1, '2026-01-05'),
  (@emp, 'Renata Lima',         'DEMO034', 'Supervisora de Turno',     'supervisor', 1, '2026-01-05'),
  (@emp, 'Paulo Henrique Melo', 'DEMO035', 'Supervisor de Logística',  'supervisor', 1, '2026-01-05'),
  (@emp, 'Tatiane Borges',      'DEMO036', 'Supervisora de Produção',  'supervisor', 1, '2026-01-05'),
  (@emp, 'Pedro Lima',          'DEMO037', 'Manutenção Terceirizada',  'prestador',  1, '2026-01-05'),
  (@emp, 'Lucia Ferreira',      'DEMO038', 'Limpeza',                  'prestador',  1, '2026-01-05'),
  (@emp, 'Sérgio Andrade',      'DEMO039', 'Segurança Patrimonial',    'prestador',  1, '2026-01-05'),
  (@emp, 'Eliane Vasconcelos',  'DEMO040', 'Jardinagem',               'prestador',  1, '2026-01-05');

-- Um cartão RFID para cada funcionário DEMO
INSERT INTO credenciais_acesso (funcionario_id, tipo, identificador, ativo, criado_em)
SELECT id, 'rfid', CONCAT('DEMO-RFID-', @emp, '-', LPAD(id, 6, '0')), 1, NOW()
  FROM funcionarios WHERE empresa_id = @emp AND matricula LIKE 'DEMO%';

-- ─── 6) Gera 90 dias de acessos e de produção ───────────────────────────────
-- A empresa funciona em 7 turnos que começam em horários diferentes (cada funcionário
-- tem o seu, pelo número da matrícula), todos os dias. Ninguém passa da meia-noite, para
-- cada entrada ter a sua saída no mesmo dia (é assim que o sistema monta os relatórios).
--   turno 0: 00h (pontual)   turno 1: 03h   turno 2: 06h   turno 3: 09h   turno 4: 12h   turno 5: 14h
--   (cada um entra em até 2 horas depois) e turno 6: entra entre 18h e 22h e sai entre 23h50 e 23h58.
-- Fim de semana e feriado vão menos gente; produção segue a quantidade de pessoas do dia.
DROP PROCEDURE IF EXISTS sp_demo_sicapda;
DELIMITER $$
CREATE PROCEDURE sp_demo_sicapda()
BEGIN
    DECLARE i INT DEFAULT 90;
    DECLARE dia DATE;
    DECLARE dow INT;
    DECLARE taxa DECIMAL(5,3);
    DECLARE pessoas INT;
    DECLARE desperdicio DECIMAL(5,3);

    DROP TEMPORARY TABLE IF EXISTS tmp_demo_dia;
    CREATE TEMPORARY TABLE tmp_demo_dia (
        funcionario_id INT, credencial_id INT, entrada DATETIME, saida DATETIME
    );

    WHILE i >= 0 DO
        SET dia = DATE_SUB(CURDATE(), INTERVAL i DAY);
        SET dow = DAYOFWEEK(dia);   -- 1 = domingo, 7 = sábado

        -- Taxa de presença: dias úteis fortes (sexta mais vazia), fim de semana reduzido
        SET taxa = CASE dow WHEN 1 THEN 0.50 WHEN 7 THEN 0.62 WHEN 2 THEN 0.92 WHEN 3 THEN 0.95
                            WHEN 4 THEN 0.95 WHEN 5 THEN 0.93 ELSE 0.84 END
                   + (RAND() * 0.06 - 0.03);
        -- Principais feriados nacionais fixos: operação mínima
        IF DATE_FORMAT(dia, '%m-%d') IN ('01-01','04-21','05-01','09-07','10-12','11-02','11-15','12-25') THEN
            SET taxa = taxa * 0.40;
        END IF;

        TRUNCATE TABLE tmp_demo_dia;

        INSERT INTO tmp_demo_dia (funcionario_id, credencial_id, entrada, saida)
        SELECT x.id, x.cred, x.entrada,
               IF(x.turno = 6, DATE_ADD(dia, INTERVAL 1430 + FLOOR(RAND() * 9) MINUTE),   -- turno 6 sai entre 23h50 e 23h58
                               DATE_ADD(x.entrada, INTERVAL x.perm MINUTE))
          FROM (
                SELECT y.id, y.turno,
                       (SELECT c.id FROM credenciais_acesso c WHERE c.funcionario_id = y.id LIMIT 1) AS cred,
                       DATE_ADD(dia, INTERVAL
                           CASE y.turno WHEN 0 THEN 0 WHEN 1 THEN 180 WHEN 2 THEN 360 WHEN 3 THEN 540
                                        WHEN 4 THEN 720 WHEN 5 THEN 840 ELSE 1080 END
                           + FLOOR(RAND() * CASE y.turno WHEN 0 THEN 20 WHEN 6 THEN 240 ELSE 120 END) MINUTE) AS entrada,
                       CASE y.turno WHEN 5 THEN 450 + FLOOR(RAND() * 20)    -- turno das 14h: ~7h30
                                    WHEN 0 THEN 420 + FLOOR(RAND() * 30)
                                    WHEN 1 THEN 420 + FLOOR(RAND() * 30)
                                    ELSE        470 + FLOOR(RAND() * 30) END AS perm
                  FROM (
                        -- turno de cada funcionário (pela matrícula): 12 no turno das 6h, 8 no da noite e 4 em cada um dos outros
                        SELECT f.id,
                               CASE CAST(RIGHT(f.matricula, 3) AS UNSIGNED) MOD 10
                                   WHEN 7 THEN 0 WHEN 8 THEN 1 WHEN 0 THEN 2 WHEN 1 THEN 2 WHEN 5 THEN 2
                                   WHEN 2 THEN 3 WHEN 6 THEN 6 WHEN 3 THEN 4 WHEN 4 THEN 5 ELSE 6
                               END AS turno,
                               f.categoria
                          FROM funcionarios f
                         WHERE f.empresa_id = @emp AND f.matricula LIKE 'DEMO%' AND f.ativo = 1
                           AND RAND() < IF(f.categoria = 'prestador', taxa * 0.85, taxa)
                       ) y
               ) x;

        -- Entradas (hoje: só até a hora atual)
        INSERT INTO logs_acesso (empresa_id, funcionario_id, dispositivo_id, credencial_id, direcao, resultado, data_hora)
        SELECT @emp, funcionario_id, @disp, credencial_id, 'entrada', 'autorizado', entrada
          FROM tmp_demo_dia WHERE entrada <= NOW();

        -- Saídas (hoje: só de quem já teria saído)
        INSERT INTO logs_acesso (empresa_id, funcionario_id, dispositivo_id, credencial_id, direcao, resultado, data_hora)
        SELECT @emp, funcionario_id, @disp, credencial_id, 'saida', 'autorizado', saida
          FROM tmp_demo_dia WHERE saida <= NOW();

        -- Uma ou outra tentativa negada (cartão desconhecido)
        IF RAND() < 0.25 AND DATE_ADD(dia, INTERVAL 500 MINUTE) <= NOW() THEN
            INSERT INTO logs_acesso (empresa_id, funcionario_id, dispositivo_id, credencial_id, direcao, resultado, data_hora)
            VALUES (@emp, NULL, @disp, NULL, 'entrada', 'negado', DATE_ADD(dia, INTERVAL 500 + FLOOR(RAND() * 60) MINUTE));
        END IF;

        -- Produção do dia (não gera para hoje: a balança só fecha o dia à noite)
        IF i > 0 THEN
            SELECT COUNT(*) INTO pessoas FROM tmp_demo_dia;
            -- Desperdício melhora com o tempo: ~11% (90 dias atrás) → ~7% (ontem)
            SET desperdicio = 0.07 + (i / 90) * 0.04 + (RAND() * 0.025 - 0.0125);

            -- kg necessários por pessoa: café 0,13 · almoço 0,48 · jantar 0,20 · ceia 0,06
            INSERT INTO producao_refeicoes
                (empresa_id, data, tipo, quantidade_prevista_kg, quantidade_produzida_kg, quantidade_desperdicada_kg, criado_em)
            SELECT @emp, dia, t.tipo,
                   ROUND(pessoas * t.kg * (0.97 + RAND() * 0.12), 2),              -- previsto
                   ROUND(pessoas * t.kg * (1.05 + RAND() * 0.10), 2),              -- produzido
                   0, NOW()
              FROM (SELECT 'cafe' AS tipo, 0.13 AS kg UNION ALL SELECT 'almoco', 0.48
                    UNION ALL SELECT 'jantar', 0.20 UNION ALL SELECT 'ceia', 0.06) t;

            UPDATE producao_refeicoes
               SET quantidade_desperdicada_kg = ROUND(quantidade_produzida_kg * (desperdicio + (RAND() * 0.02 - 0.01)), 2)
             WHERE empresa_id = @emp AND data = dia;
        END IF;

        SET i = i - 1;
    END WHILE;

    DROP TEMPORARY TABLE IF EXISTS tmp_demo_dia;
END$$
DELIMITER ;

CALL sp_demo_sicapda();
DROP PROCEDURE IF EXISTS sp_demo_sicapda;

SET SQL_SAFE_UPDATES = 1;   -- devolve a proteção do Workbench

-- ─── 7) DIAGNÓSTICO (confira os números) ─────────────────────────────────────
SELECT 'Funcionários (esperado 40)' AS item, CAST(COUNT(*) AS CHAR) AS valor FROM funcionarios WHERE empresa_id = @emp
UNION ALL SELECT 'Logs de acesso (total)', CAST(COUNT(*) AS CHAR) FROM logs_acesso WHERE empresa_id = @emp
UNION ALL SELECT 'Logs de acesso de HOJE (deve ser > 0)', CAST(COUNT(*) AS CHAR) FROM logs_acesso WHERE empresa_id = @emp AND data_hora >= CURDATE()
UNION ALL SELECT 'Dias de acesso em fim de semana (deve ser > 0)', CAST(COUNT(DISTINCT DATE(data_hora)) AS CHAR) FROM logs_acesso WHERE empresa_id = @emp AND DAYOFWEEK(data_hora) IN (1, 7)
UNION ALL SELECT 'Pessoas presentes agora (deve ser > 0)', CAST(COUNT(DISTINCT l.funcionario_id) AS CHAR)
            FROM logs_acesso l JOIN funcionarios f ON f.id = l.funcionario_id AND f.empresa_id = l.empresa_id
           WHERE l.empresa_id = @emp AND l.resultado = 'autorizado' AND l.direcao = 'entrada' AND l.data_hora >= CURDATE() AND f.ativo = 1
             AND NOT EXISTS (SELECT 1 FROM logs_acesso l2 WHERE l2.funcionario_id = l.funcionario_id AND l2.resultado = 'autorizado' AND l2.data_hora > l.data_hora)
UNION ALL SELECT 'Dias de produção (esperado 90)', CAST(COUNT(DISTINCT data) AS CHAR) FROM producao_refeicoes WHERE empresa_id = @emp
UNION ALL SELECT 'Metas de refeição somadas (deve ser > 0)', CAST(COALESCE(SUM(media_diaria), 0) AS CHAR) FROM refeicoes WHERE empresa_id = @emp
UNION ALL SELECT 'Colaboradores cadastrados na empresa', CAST(qtd_colaboradores AS CHAR) FROM empresas WHERE id = @emp
UNION ALL SELECT 'Hora do servidor (horário de Brasília)', CAST(NOW() AS CHAR);
