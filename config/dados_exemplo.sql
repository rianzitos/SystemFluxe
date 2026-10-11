-- =============================================================================
-- SICAPDA by FLUXE — DADOS FICTÍCIOS (DEMO) para os dashboards
-- Rode no MySQL Workbench com TODO o script (ícone do raio ⚡, Ctrl+Shift+Enter).
-- Pode rodar de novo à vontade: ele apaga os dados DEMO anteriores e recria.
-- Os dados DEMO são identificados por: matrícula 'DEMO...' e token 'demo-...'.
--
-- EMPRESA: por padrão vai para a primeira empresa. Para escolher outra, veja o
-- passo 1 (troque o número em  SET @emp = 8;  e tire os dois traços do começo).
-- Descubra os ids com:  SELECT id, nome_fantasia FROM empresas;
--
-- ATENÇÃO (use em banco de teste):
--   • preenche as metas de refeições que estiverem com média 0 (sem isso a previsão fica 0);
--   • marca a empresa escolhida como "possui refeitório" e, se ela tiver menos de
--     40 colaboradores, ajusta para 45 (a previsão de demanda precisa disso);
--   • recria a produção de refeições dos últimos 90 dias dessa empresa, inclusive
--     dias que já tinham dado.
-- =============================================================================

USE controle_acesso;
SET NAMES utf8mb4;          -- garante os acentos (Produção, Natália...) em qualquer forma de importar

SET SQL_SAFE_UPDATES = 0;   -- o Workbench bloqueia DELETE/UPDATE sem chave (erro 1175)

-- ─── 1) Empresa que receberá os dados ────────────────────────────────────────
-- Padrão: a primeira empresa. Para escolher outra, comente a linha de baixo
-- (coloque -- no começo) e tire o -- da seguinte, trocando o número pelo id.
SET @emp = (SELECT id FROM empresas ORDER BY id LIMIT 1);
-- SET @emp = 8;

-- Confere: tem que aparecer 1 linha com o nome da empresa (vazio = id inexistente)
SELECT id, nome_fantasia AS empresa_escolhida FROM empresas WHERE id = @emp;

-- "Presentes agora" (tela Pessoas): ela mostra quem está no local NESTE MOMENTO, e o
-- script só cria acessos reais de dias úteis até a hora atual. Em fim de semana, feriado
-- ou fora do expediente ela fica em 0 mesmo. Para demonstrar a tela a qualquer hora,
-- troque 0 por 1: o script coloca 15 pessoas (operadores, supervisores e prestadores)
-- "dentro" agora, com a entrada nas últimas horas.
SET @presentes_agora = 0;

-- ─── 2) Limpa dados DEMO anteriores (só os dessa empresa) ────────────────────
DELETE FROM dispositivos_iot WHERE empresa_id = @emp AND token LIKE 'demo-%';       -- apaga também os logs (CASCADE)
DELETE FROM funcionarios     WHERE empresa_id = @emp AND matricula LIKE 'DEMO%';    -- apaga também as credenciais (CASCADE)

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

-- ─── 6) Gera ~90 dias de acessos e de produção ───────────────────────────────
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

        -- Só dias úteis e fora dos principais feriados nacionais fixos
        IF dow BETWEEN 2 AND 6
           AND DATE_FORMAT(dia, '%m-%d') NOT IN ('01-01','04-21','05-01','09-07','10-12','11-02','11-15','12-25') THEN

            -- Taxa de presença: segunda forte, sexta mais vazia, com variação diária
            SET taxa = CASE dow WHEN 2 THEN 0.92 WHEN 3 THEN 0.95 WHEN 4 THEN 0.95 WHEN 5 THEN 0.93 ELSE 0.84 END
                       + (RAND() * 0.06 - 0.03);

            TRUNCATE TABLE tmp_demo_dia;

            -- Horário de entrada por turno (id MOD 3) e permanência por categoria
            INSERT INTO tmp_demo_dia (funcionario_id, credencial_id, entrada, saida)
            SELECT x.id, x.cred, x.entrada,
                   DATE_ADD(x.entrada, INTERVAL x.perm MINUTE)
              FROM (
                    SELECT f.id,
                           (SELECT c.id FROM credenciais_acesso c WHERE c.funcionario_id = f.id LIMIT 1) AS cred,
                           DATE_ADD(dia, INTERVAL
                               CASE f.categoria
                                   WHEN 'prestador' THEN 480 + FLOOR(RAND() * 120)
                                   ELSE CASE f.id MOD 3
                                            WHEN 0 THEN 360 + FLOOR(RAND() * 50)   -- 06:00–06:50
                                            WHEN 1 THEN 450 + FLOOR(RAND() * 60)   -- 07:30–08:30
                                            ELSE        780 + FLOOR(RAND() * 45)   -- 13:00–13:45
                                        END
                               END MINUTE) AS entrada,
                           CASE f.categoria
                               WHEN 'prestador'  THEN 300 + FLOOR(RAND() * 180)
                               WHEN 'supervisor' THEN 520 + FLOOR(RAND() * 60)
                               ELSE                   470 + FLOOR(RAND() * 40)
                           END AS perm
                      FROM funcionarios f
                     WHERE f.empresa_id = @emp AND f.matricula LIKE 'DEMO%' AND f.ativo = 1
                       AND RAND() < IF(f.categoria = 'supervisor', 0.97, IF(f.categoria = 'prestador', 0.80, taxa))
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
            IF RAND() < 0.25 THEN
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
                        UNION ALL SELECT 'jantar', 0.20 UNION ALL SELECT 'ceia', 0.06) t
                ON DUPLICATE KEY UPDATE
                    quantidade_prevista_kg  = VALUES(quantidade_prevista_kg),
                    quantidade_produzida_kg = VALUES(quantidade_produzida_kg);

                UPDATE producao_refeicoes
                   SET quantidade_desperdicada_kg = ROUND(quantidade_produzida_kg * (desperdicio + (RAND() * 0.02 - 0.01)), 2)
                 WHERE empresa_id = @emp AND data = dia;
            END IF;
        END IF;

        SET i = i - 1;
    END WHILE;

    DROP TEMPORARY TABLE IF EXISTS tmp_demo_dia;
END$$
DELIMITER ;

CALL sp_demo_sicapda();
DROP PROCEDURE IF EXISTS sp_demo_sicapda;

-- ─── Opcional: pessoas "presentes agora" (só se @presentes_agora = 1, lá no topo) ──
-- 10 operadores, 3 supervisores e 2 prestadores: entrada nas últimas 20 a 180 minutos, sem saída.
DELETE FROM logs_acesso
 WHERE @presentes_agora = 1 AND empresa_id = @emp AND data_hora >= CURDATE()
   AND funcionario_id IN (SELECT id FROM funcionarios
                           WHERE empresa_id = @emp
                             AND (matricula BETWEEN 'DEMO001' AND 'DEMO010' OR matricula BETWEEN 'DEMO031' AND 'DEMO033'
                                  OR matricula IN ('DEMO037', 'DEMO038')));

INSERT INTO logs_acesso (empresa_id, funcionario_id, dispositivo_id, credencial_id, direcao, resultado, data_hora)
SELECT @emp, f.id, @disp, (SELECT c.id FROM credenciais_acesso c WHERE c.funcionario_id = f.id LIMIT 1),
       'entrada', 'autorizado',
       GREATEST(TIMESTAMP(CURDATE()), NOW() - INTERVAL (20 + (CAST(RIGHT(f.matricula, 3) AS UNSIGNED) * 7) MOD 160) MINUTE)
  FROM funcionarios f
 WHERE @presentes_agora = 1 AND f.empresa_id = @emp
   AND (f.matricula BETWEEN 'DEMO001' AND 'DEMO010' OR f.matricula BETWEEN 'DEMO031' AND 'DEMO033'
        OR f.matricula IN ('DEMO037', 'DEMO038'));

SET SQL_SAFE_UPDATES = 1;   -- devolve a proteção do Workbench

-- ─── 7) Conferência (deve mostrar números > 0) ───────────────────────────────
SELECT 'Funcionários DEMO'   AS item, COUNT(*) AS total FROM funcionarios WHERE empresa_id = @emp AND matricula LIKE 'DEMO%'
UNION ALL SELECT 'Logs de acesso',         COUNT(*) FROM logs_acesso        WHERE empresa_id = @emp
UNION ALL SELECT 'Dias de produção',       COUNT(DISTINCT data) FROM producao_refeicoes WHERE empresa_id = @emp;
