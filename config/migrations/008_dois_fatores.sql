-- Executar no banco SICAPDA existente (phpMyAdmin → aba SQL). Seguro rodar de novo; não apaga nada.

-- =============================================================================
-- Migration 008: Verificação em duas etapas (login do site e do aplicativo)
-- =============================================================================
-- IF NOT EXISTS / colunas conferidas antes: seguro rodar de novo (MySQL 5.7+, 8 e MariaDB). No banco de produção
-- (cPanel), rode só este bloco no phpMyAdmin (aba SQL). Enquanto ele não for rodado, o login continua pedindo só
-- e-mail e senha e a tela Configurações → Segurança avisa que a verificação ainda não está disponível.

-- ─── Colunas novas em usuarios ───────────────────────────────────────────────
-- dois_fatores_segredo      segredo do aplicativo autenticador, cifrado (AES-256-GCM, chave derivada do API_SECRET)
-- dois_fatores_ativado_em   quando a pessoa ativou (conferindo um código): só com ele preenchido o login pede o código
-- dois_fatores_ultimo_passo último intervalo de 30 s aceito do aplicativo (o mesmo código não entra duas vezes)
SET @coluna = (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'dois_fatores_segredo');
SET @sql = IF(@coluna = 0, 'ALTER TABLE usuarios ADD COLUMN dois_fatores_segredo VARCHAR(255) NULL AFTER dois_fatores_metodo', 'SELECT 1');
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

SET @coluna = (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'dois_fatores_ativado_em');
SET @sql = IF(@coluna = 0, 'ALTER TABLE usuarios ADD COLUMN dois_fatores_ativado_em DATETIME NULL AFTER dois_fatores_segredo', 'SELECT 1');
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

SET @coluna = (SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'dois_fatores_ultimo_passo');
SET @sql = IF(@coluna = 0, 'ALTER TABLE usuarios ADD COLUMN dois_fatores_ultimo_passo BIGINT NULL AFTER dois_fatores_ativado_em', 'SELECT 1');
PREPARE passo FROM @sql; EXECUTE passo; DEALLOCATE PREPARE passo;

-- Quem marcou "Sim" no cadastro antes desta versão nunca configurou nada: fica como pendente (o método escolhido
-- continua em dois_fatores_metodo e a tela de Segurança convida a concluir), sem trancar ninguém para fora.
UPDATE usuarios SET dois_fatores_habilitado = 0 WHERE dois_fatores_ativado_em IS NULL AND dois_fatores_habilitado <> 0;
UPDATE usuarios SET dois_fatores_metodo = NULL
 WHERE dois_fatores_ativado_em IS NULL AND dois_fatores_metodo IS NOT NULL AND dois_fatores_metodo NOT IN ('app', 'email');

-- ─── Tabela dois_fatores_desafios ────────────────────────────────────────────
-- Cada login que passou pela senha e espera o código (finalidade "login") e cada ativação por e-mail (finalidade
-- "ativacao"). Vale 10 minutos e aceita 5 tentativas. Só o SHA-256 do identificador fica aqui (o identificador de
-- verdade fica na sessão do navegador ou no aplicativo) e o código enviado por e-mail fica como HMAC.
CREATE TABLE IF NOT EXISTS dois_fatores_desafios (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id   INT          NOT NULL,
    finalidade   VARCHAR(20)  NOT NULL,
    metodo       VARCHAR(20)  NOT NULL,
    token_hash   CHAR(64)     NOT NULL UNIQUE,
    codigo_hash  CHAR(64)     NULL,
    tentativas   INT          NOT NULL DEFAULT 0,
    envios       INT          NOT NULL DEFAULT 0,
    ultimo_envio DATETIME     NULL,
    ip           VARCHAR(45)  NULL,
    expira_em    DATETIME     NOT NULL,
    usado_em     DATETIME     NULL,
    criado_em    DATETIME     NOT NULL,
    CONSTRAINT fk_dfa_desafio_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_dfa_desafio_usuario (usuario_id, criado_em)
) ENGINE=InnoDB;

-- ─── Tabela dois_fatores_recuperacao ─────────────────────────────────────────
-- Os 10 códigos de recuperação de cada conta (entram no lugar do código quando a pessoa perde o celular). Cada um
-- vale uma vez; só o SHA-256 fica no banco (o código de verdade aparece uma única vez, ao ativar ou gerar novos).
CREATE TABLE IF NOT EXISTS dois_fatores_recuperacao (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT       NOT NULL,
    codigo_hash CHAR(64)  NOT NULL,
    usado_em    DATETIME  NULL,
    criado_em   DATETIME  NOT NULL,
    CONSTRAINT fk_dfa_recuperacao_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_dfa_recuperacao_usuario (usuario_id, codigo_hash)
) ENGINE=InnoDB;
