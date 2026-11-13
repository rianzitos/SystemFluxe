-- Executar no banco SICAPDA existente (phpMyAdmin → aba SQL). Seguro rodar de novo; não apaga nada.

-- =============================================================================
-- Migration 007: E-mails automáticos (contato, recuperação de senha, alertas e resumo diário)
-- =============================================================================
-- IF NOT EXISTS: seguro rodar de novo. No banco de produção (cPanel), rode só este bloco no
-- phpMyAdmin (aba SQL). Enquanto ele não for rodado, o "Fale conosco" ainda envia o e-mail
-- (sem guardar a mensagem nem limitar o envio), mas a recuperação de senha, o alerta de
-- desperdício e o resumo diário ficam desligados.

-- ─── Tabela contatos ─────────────────────────────────────────────────────────
-- Mensagens do formulário "Contato" das páginas públicas (/ e /sicapda). Guardar a mensagem
-- garante que nada se perde se o servidor de e-mail falhar: a tarefa agendada (bin/emails.php)
-- reenvia as que ficaram com enviado_em NULL. O IP serve só para limitar abusos (spam).
CREATE TABLE IF NOT EXISTS contatos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    origem     VARCHAR(20)   NOT NULL,
    nome       VARCHAR(120)  NOT NULL,
    email      VARCHAR(150)  NOT NULL,
    telefone   VARCHAR(30)   NULL,
    mensagem   TEXT          NOT NULL,
    ip         VARCHAR(45)   NULL,
    navegador  VARCHAR(255)  NULL,
    tentativas INT           NOT NULL DEFAULT 0,
    enviado_em DATETIME      NULL,
    criado_em  DATETIME      NOT NULL,
    INDEX idx_contatos_ip (ip, criado_em),
    INDEX idx_contatos_pendentes (enviado_em, criado_em)
) ENGINE=InnoDB;

-- ─── Tabela senha_redefinicoes ───────────────────────────────────────────────
-- "Esqueceu a senha?": cada pedido gera um link de uso único que vale 1 hora. Só o SHA-256
-- do token fica no banco (o token de verdade só existe no e-mail).
CREATE TABLE IF NOT EXISTS senha_redefinicoes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT          NOT NULL,
    token_hash CHAR(64)     NOT NULL UNIQUE,
    ip         VARCHAR(45)  NULL,
    expira_em  DATETIME     NOT NULL,
    usado_em   DATETIME     NULL,
    criado_em  DATETIME     NOT NULL,
    CONSTRAINT fk_redefinicao_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_redefinicao_usuario (usuario_id, criado_em),
    INDEX idx_redefinicao_ip (ip, criado_em)
) ENGINE=InnoDB;

-- ─── Tabela emails_enviados ──────────────────────────────────────────────────
-- Registro dos e-mails automáticos de alerta e resumo. A chave única (tipo, referencia,
-- destinatario) garante que o mesmo alerta não chega duas vezes (ex.: um alerta de
-- desperdício por empresa e por dia, um resumo diário por pessoa e por dia).
CREATE TABLE IF NOT EXISTS emails_enviados (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id   INT          NULL,
    usuario_id   INT          NULL,
    tipo         VARCHAR(40)  NOT NULL,
    referencia   VARCHAR(60)  NOT NULL,
    destinatario VARCHAR(150) NOT NULL,
    criado_em    DATETIME     NOT NULL,
    CONSTRAINT fk_emails_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_emails_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    UNIQUE KEY uk_emails_unico (tipo, referencia, destinatario),
    INDEX idx_emails_empresa (empresa_id, criado_em)
) ENGINE=InnoDB;
