-- =============================================================================
-- SICAPDA by FLUXE — DADOS DE EXEMPLO PARA UMA EMPRESA QUE JÁ EXISTE
-- =============================================================================
-- COMO USAR
--   1) Descubra o id da sua empresa:   SELECT id, nome_fantasia FROM empresas;
--   2) Troque o número na linha "SET @empresa_id" aqui embaixo.
--   3) Execute o script INTEIRO (no Workbench: Ctrl+Shift+Enter, o raio SEM cursor).
--
-- O QUE INSERE (tudo na empresa escolhida, só dados fictícios):
--   20 funcionários (matrículas DEMO-001...), cartões RFID, 3 catracas, metas de
--   refeições (só se a empresa ainda não tiver) e ~30 dias de acessos e de produção.
--   Datas relativas a HOJE, só em dias úteis, sem horários que ainda não aconteceram.
--
-- É SEGURO: não altera nem apaga dados reais. Ao rodar de novo, ele só recria o que
-- ele mesmo criou (matrícula "DEMO-" e aparelhos "[Demo]"). A produção usa INSERT
-- IGNORE: dias que já têm dado real ficam como estão.
-- Para remover tudo depois, rode só os dois DELETE do "PASSO 1".
-- =============================================================================
USE controle_acesso;
SET NAMES utf8mb4;

SET @empresa_id = 8;      -- <<< TROQUE AQUI pelo id da SUA empresa

-- Confere a empresa: tem que aparecer 1 linha com o nome dela (vazio = id errado)
SELECT id, nome_fantasia AS empresa_escolhida FROM empresas WHERE id = @empresa_id;

-- ─── PASSO 1: limpa os dados de exemplo anteriores (só os "DEMO") ────────────
DELETE FROM dispositivos_iot WHERE empresa_id = @empresa_id AND nome LIKE '[Demo]%';    -- apaga também os logs deles
DELETE FROM funcionarios     WHERE empresa_id = @empresa_id AND matricula LIKE 'DEMO-%'; -- e as credenciais

-- ─── PASSO 2: catracas / leitores ────────────────────────────────────────────
-- (se o id da empresa não existir, é aqui que aparece o erro 1452 de chave estrangeira)
INSERT INTO dispositivos_iot (empresa_id, nome, tipo, localizacao, token, status, ultimo_ping, criado_em)
SELECT @empresa_id, d.nome, d.tipo, d.loc, SHA2(CONCAT('demo-', @empresa_id, '-', d.n), 256), 'online', NOW(), NOW()
FROM (SELECT 1 n, '[Demo] Catraca Portaria Principal' nome, 'hibrido' tipo, 'Entrada principal' loc
      UNION ALL SELECT 2, '[Demo] Leitor Administrativo',   'rfid', 'Bloco administrativo'
      UNION ALL SELECT 3, '[Demo] Catraca de Serviços',     'rfid', 'Entrada de prestadores') d;

SET @d1 = (SELECT id FROM dispositivos_iot WHERE empresa_id = @empresa_id AND token = SHA2(CONCAT('demo-', @empresa_id, '-1'), 256));
SET @d2 = (SELECT id FROM dispositivos_iot WHERE empresa_id = @empresa_id AND token = SHA2(CONCAT('demo-', @empresa_id, '-2'), 256));
SET @d3 = (SELECT id FROM dispositivos_iot WHERE empresa_id = @empresa_id AND token = SHA2(CONCAT('demo-', @empresa_id, '-3'), 256));

-- ─── PASSO 3: funcionários (10 operadores, 4 supervisores, 5 prestadores, 1 inativo) ─
INSERT INTO funcionarios (empresa_id, nome, cpf, matricula, cargo, categoria, foto, ativo, criado_em)
SELECT @empresa_id, t.nome, CONCAT(LPAD(@empresa_id, 3, '0'), LPAD(t.n, 8, '0')),
       CONCAT('DEMO-', LPAD(t.n, 3, '0')), t.cargo, t.cat, NULL, t.ativo, NOW()
FROM (SELECT  1 n, 'Carlos Eduardo Lima' nome, 'Operador de Produção' cargo, 'operador' cat, 1 ativo
      UNION ALL SELECT  2, 'Fernanda Souza Alves',      'Operadora de Produção',        'operador',   1
      UNION ALL SELECT  3, 'João Pedro Ramos',          'Operador de Empacotamento',    'operador',   1
      UNION ALL SELECT  4, 'Aline Cristina Martins',    'Operadora de Qualidade',       'operador',   1
      UNION ALL SELECT  5, 'Rafael Henrique Gomes',     'Operador de Máquinas',         'operador',   1
      UNION ALL SELECT  6, 'Patrícia Oliveira Nunes',   'Auxiliar de Produção',         'operador',   1
      UNION ALL SELECT  7, 'Lucas Mendes Barbosa',      'Auxiliar de Estoque',          'operador',   1
      UNION ALL SELECT  8, 'Juliana Ferreira Costa',    'Operadora de Produção',        'operador',   1
      UNION ALL SELECT  9, 'Marcos Vinícius Teixeira',  'Operador de Expedição',        'operador',   1
      UNION ALL SELECT 10, 'Camila Rodrigues Pires',    'Auxiliar de Limpeza Industrial','operador',  1
      UNION ALL SELECT 11, 'Roberto Carlos Andrade',    'Supervisor de Produção',       'supervisor', 1
      UNION ALL SELECT 12, 'Sandra Regina Moreira',     'Supervisora de Qualidade',     'supervisor', 1
      UNION ALL SELECT 13, 'Eduardo Santos Cardoso',    'Supervisor de Logística',      'supervisor', 1
      UNION ALL SELECT 14, 'Tatiana Lopes Vieira',      'Coordenadora de Turno',        'supervisor', 1
      UNION ALL SELECT 15, 'Gustavo Almeida Rocha',     'Técnico de Manutenção (terceirizado)', 'prestador', 1
      UNION ALL SELECT 16, 'Renata Batista Freitas',    'Nutricionista (terceirizada)', 'prestador',  1
      UNION ALL SELECT 17, 'Diego Fernandes Araújo',    'Eletricista (terceirizado)',   'prestador',  1
      UNION ALL SELECT 18, 'Priscila Monteiro Dias',    'Segurança Patrimonial (terceirizada)', 'prestador', 1
      UNION ALL SELECT 19, 'Anderson Luiz Castro',      'Vigilante (terceirizado)',     'prestador',  1
      UNION ALL SELECT 20, 'Bruno Cesar Pacheco',       'Operador de Produção (desligado)', 'operador', 0) t;

-- ─── PASSO 4: credenciais (cartão RFID para todos; biometria para os supervisores) ─
INSERT INTO credenciais_acesso (funcionario_id, tipo, identificador, ativo, criado_em)
SELECT f.id, 'rfid', CONCAT('DEMO-E', @empresa_id, '-RFID-', RIGHT(f.matricula, 3)), f.ativo, NOW()
FROM funcionarios f WHERE f.empresa_id = @empresa_id AND f.matricula LIKE 'DEMO-%';

INSERT INTO credenciais_acesso (funcionario_id, tipo, identificador, ativo, criado_em)
SELECT f.id, 'biometria', CONCAT('DEMO-E', @empresa_id, '-BIO-', RIGHT(f.matricula, 3)), 1, NOW()
FROM funcionarios f WHERE f.empresa_id = @empresa_id AND f.matricula LIKE 'DEMO-%' AND f.categoria = 'supervisor';

-- ─── PASSO 5: metas de refeições por dia (só se a empresa ainda não tiver) ───
INSERT INTO refeicoes (empresa_id, tipo, media_diaria, criado_em)
SELECT @empresa_id, m.tipo, m.media, NOW()
FROM (SELECT 'cafe' tipo, 18 media UNION ALL SELECT 'almoco', 20 UNION ALL SELECT 'jantar', 8) m
WHERE NOT EXISTS (SELECT 1 FROM refeicoes r WHERE r.empresa_id = @empresa_id);

-- ─── PASSO 6: acessos dos últimos 30 dias ────────────────────────────────────
-- tmp_dias = números de 0 a 29 (quantos dias atrás). O "sorteio" usa CRC32, então a
-- falta de um funcionário num dia é a mesma na entrada e na saída.
DROP TEMPORARY TABLE IF EXISTS tmp_dias;
CREATE TEMPORARY TABLE tmp_dias AS
SELECT a.n + b.n * 10 AS d
FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
     (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2) b;

-- entradas (93% de presença nos dias úteis)
INSERT INTO logs_acesso (empresa_id, funcionario_id, dispositivo_id, credencial_id, direcao, resultado, data_hora)
SELECT @empresa_id, x.fid, x.dev, x.cid, 'entrada', 'autorizado', x.quando
FROM (SELECT f.id AS fid, c.id AS cid,
             CASE f.categoria WHEN 'prestador' THEN @d3 WHEN 'supervisor' THEN @d2 ELSE @d1 END AS dev,
             TIMESTAMP(CURDATE() - INTERVAL t.d DAY, SEC_TO_TIME(
                 (CASE f.categoria WHEN 'supervisor' THEN 440 WHEN 'prestador' THEN 450 ELSE 400 END
                  + CRC32(CONCAT('e', f.id, '-', t.d)) % 45) * 60 + CRC32(CONCAT('s', f.id, '-', t.d)) % 60)) AS quando
      FROM tmp_dias t
      JOIN funcionarios f ON f.empresa_id = @empresa_id AND f.matricula LIKE 'DEMO-%' AND f.ativo = 1
      JOIN credenciais_acesso c ON c.funcionario_id = f.id AND c.tipo = 'rfid'
      WHERE DAYOFWEEK(CURDATE() - INTERVAL t.d DAY) NOT IN (1, 7)
        AND CRC32(CONCAT(f.id, '-', t.d)) % 100 < 93) x
WHERE x.quando <= NOW();

-- saídas (hoje só ~30% já saiu: o resto aparece como "presente agora")
INSERT INTO logs_acesso (empresa_id, funcionario_id, dispositivo_id, credencial_id, direcao, resultado, data_hora)
SELECT @empresa_id, x.fid, x.dev, x.cid, 'saida', 'autorizado', x.quando
FROM (SELECT f.id AS fid, c.id AS cid,
             CASE f.categoria WHEN 'prestador' THEN @d3 WHEN 'supervisor' THEN @d2 ELSE @d1 END AS dev,
             TIMESTAMP(CURDATE() - INTERVAL t.d DAY, SEC_TO_TIME(
                 (CASE f.categoria WHEN 'supervisor' THEN 1030 WHEN 'prestador' THEN 1000 ELSE 990 END
                  + CRC32(CONCAT('x', f.id, '-', t.d)) % 50) * 60 + CRC32(CONCAT('y', f.id, '-', t.d)) % 60)) AS quando
      FROM tmp_dias t
      JOIN funcionarios f ON f.empresa_id = @empresa_id AND f.matricula LIKE 'DEMO-%' AND f.ativo = 1
      JOIN credenciais_acesso c ON c.funcionario_id = f.id AND c.tipo = 'rfid'
      WHERE DAYOFWEEK(CURDATE() - INTERVAL t.d DAY) NOT IN (1, 7)
        AND CRC32(CONCAT(f.id, '-', t.d)) % 100 < 93
        AND (t.d > 0 OR CRC32(CONCAT('h', f.id)) % 100 < 30)) x
WHERE x.quando <= NOW();

-- algumas tentativas negadas (cartão desconhecido)
INSERT INTO logs_acesso (empresa_id, funcionario_id, dispositivo_id, credencial_id, direcao, resultado, data_hora)
SELECT @empresa_id, NULL, @d1, NULL, 'entrada', 'negado', x.quando
FROM (SELECT TIMESTAMP(CURDATE() - INTERVAL t.d DAY, SEC_TO_TIME(21600 + CRC32(CONCAT('n', t.d)) % 43200)) AS quando
      FROM tmp_dias t
      WHERE DAYOFWEEK(CURDATE() - INTERVAL t.d DAY) NOT IN (1, 7) AND CRC32(CONCAT('neg', t.d)) % 100 < 35) x
WHERE x.quando <= NOW();

DROP TEMPORARY TABLE IF EXISTS tmp_dias;

-- ─── PASSO 7: produção diária de refeições em kg (ontem e os 28 dias anteriores) ─
CREATE TEMPORARY TABLE tmp_dias AS
SELECT a.n + b.n * 10 AS d
FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
      UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a,
     (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2) b;

INSERT IGNORE INTO producao_refeicoes
    (empresa_id, data, tipo, quantidade_prevista_kg, quantidade_produzida_kg, quantidade_desperdicada_kg, criado_em)
SELECT @empresa_id, z.dia, z.tipo, z.prev, z.prod, ROUND(z.prod * (0.04 + (CRC32(CONCAT('w', z.dia, z.tipo)) % 8) / 100), 2), NOW()
FROM (SELECT y.dia, y.tipo, y.prev, ROUND(y.prev * (0.95 + (CRC32(CONCAT('q', y.dia, y.tipo)) % 14) / 100), 2) AS prod
      FROM (SELECT CURDATE() - INTERVAL t.d DAY AS dia, m.tipo,
                   ROUND(m.kg * (0.92 + (CRC32(CONCAT('p', t.d, m.tipo)) % 17) / 100), 2) AS prev
            FROM tmp_dias t
            JOIN (SELECT 'cafe' tipo, 5.2 kg UNION ALL SELECT 'almoco', 12.5 UNION ALL SELECT 'jantar', 5.0) m
            WHERE t.d BETWEEN 1 AND 29 AND DAYOFWEEK(CURDATE() - INTERVAL t.d DAY) NOT IN (1, 7)) y) z;

DROP TEMPORARY TABLE IF EXISTS tmp_dias;

-- ─── Conferência: o que existe de exemplo nessa empresa agora ────────────────
SELECT 'funcionários DEMO'  AS item, COUNT(*) AS total FROM funcionarios WHERE empresa_id = @empresa_id AND matricula LIKE 'DEMO-%'
UNION ALL SELECT 'catracas [Demo]', COUNT(*) FROM dispositivos_iot WHERE empresa_id = @empresa_id AND nome LIKE '[Demo]%'
UNION ALL SELECT 'logs de acesso', COUNT(*) FROM logs_acesso WHERE empresa_id = @empresa_id AND dispositivo_id IN (@d1, @d2, @d3)
UNION ALL SELECT 'dias de produção', COUNT(DISTINCT data) FROM producao_refeicoes WHERE empresa_id = @empresa_id;
