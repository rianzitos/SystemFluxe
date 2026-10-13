-- =============================================================================
-- SICAPDA — cadastro de uma pessoa + cartão RFID para testar a catraca (Wokwi ou real)
-- =============================================================================
-- ATENÇÃO: não é mais necessário! Pessoas e cartões se cadastram na tela Pessoas → Cadastro
-- (adicionar pessoa, vincular cartão, importar planilha). Este script fica só como alternativa.
--
-- Rode no phpMyAdmin / Workbench, com o banco do sistema selecionado.
--
-- Antes: crie o dispositivo em Configurações → Integrações → "Adicionar dispositivo" e passe o
-- token para o firmware. Este script só cria a PESSOA e o CARTÃO dela.
--
-- O UID é o que a ESP32 mostra no Monitor Serial ("UID lido: 01 02 03 04"), escrito junto, sem
-- espaços e em maiúsculas: 01020304. Cartão que não estiver cadastrado é negado (e fica no log).
-- =============================================================================

SET @emp = (SELECT id FROM empresas ORDER BY id LIMIT 1);   -- ou escolha a empresa: SET @emp = 8;
SELECT id, nome_fantasia FROM empresas WHERE id = @emp;     -- confira se é a empresa certa

-- Tudo ou nada: se o cartão falhar (UID já usado), a pessoa também não é criada.
START TRANSACTION;

INSERT INTO funcionarios (empresa_id, nome, matricula, cargo, categoria, ativo, criado_em)
VALUES (@emp, 'Pessoa Teste Wokwi', 'WOKWI01', 'Operador de Teste', 'operador', 1, NOW());
SET @func = LAST_INSERT_ID();

INSERT INTO credenciais_acesso (funcionario_id, tipo, identificador, ativo, criado_em)
VALUES (@func, 'rfid', '01020304', 1, NOW());   -- troque pelo UID do seu cartão

COMMIT;

-- Um segundo cartão para a mesma pessoa (opcional):
INSERT INTO credenciais_acesso (funcionario_id, tipo, identificador, ativo, criado_em)
VALUES (@func, 'rfid', '11223344', 1, NOW());

-- Depois de passar o cartão, a pessoa aparece em Pessoas ("presentes agora"), no Painel e nos Relatórios.

-- ─── LIMPEZA (rode depois dos testes, para a pessoa de teste sumir dos números) ───────────────
-- Apaga a pessoa de teste, os cartões dela (em cascata) e os registros de acesso dela:
--
-- SET @func = (SELECT id FROM funcionarios WHERE empresa_id = @emp AND matricula = 'WOKWI01');
-- DELETE FROM logs_acesso WHERE funcionario_id = @func;
-- DELETE FROM funcionarios WHERE id = @func;
--
-- Tentativas negadas de cartões desconhecidos ficam em logs_acesso sem pessoa e não entram nos
-- relatórios; para apagá-las também: DELETE FROM logs_acesso WHERE empresa_id = @emp AND resultado = 'negado';
