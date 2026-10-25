--  Código do banco do dados

-- =============================================================================
-- SICAPDA by FLUXE — Migration 003: Pessoas, Dispositivos IoT e Acessos
-- =============================================================================
--
-- IF NOT EXISTS: seguro rodar de novo mesmo se alguma tabela já existir.
-- =============================================================================
USE fluxetea_controle_acesso;

-- ─── Tabela empresas ────────────────────────────────────────────────────────
-- (IF NOT EXISTS: seguro rodar de novo mesmo se ela já tiver sido criada antes)
USE fluxetea_controle_acesso;

CREATE TABLE IF NOT EXISTS empresas (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    razao_social              VARCHAR(150) NOT NULL,
    nome_fantasia             VARCHAR(150) NOT NULL,
    cnpj                      VARCHAR(14)  NOT NULL UNIQUE,
    inscricao_estadual        VARCHAR(30)  NULL,
    segmento                  VARCHAR(50)  NOT NULL,
    qtd_colaboradores         INT          NOT NULL,
    endereco                  VARCHAR(200) NOT NULL,
    cep                       VARCHAR(8)   NOT NULL,
    cidade                    VARCHAR(100) NOT NULL,
    estado                    CHAR(2)      NOT NULL,
    telefone                  VARCHAR(20)  NOT NULL,
    site                      VARCHAR(150) NULL,
    horario_funcionamento     VARCHAR(50)  NOT NULL,
    responsavel_contrato      VARCHAR(150) NOT NULL,
    email_institucional       VARCHAR(150) NOT NULL,
    possui_refeitorio         TINYINT(1)   NOT NULL DEFAULT 0,
    controle_atual_refeicoes  VARCHAR(30)  NOT NULL DEFAULT 'Não',
    recursos_acesso           JSON         NULL,
    integracoes               JSON         NULL,
    criado_em                 DATETIME     NOT NULL
) ENGINE=InnoDB;

-- ─── Tabela usuarios (criada do zero — ela ainda não existia nesse banco) ──
CREATE TABLE IF NOT EXISTS usuarios (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    nome                      VARCHAR(150) NOT NULL,
    cpf                       VARCHAR(11)  NULL UNIQUE,
    telefone                  VARCHAR(20)  NULL,
    cargo                     VARCHAR(100) NULL,
    matricula                 VARCHAR(30)  NULL,
    foto                      VARCHAR(255) NULL,
    email                     VARCHAR(150) NOT NULL UNIQUE,
    senha                     VARCHAR(255) NOT NULL,
    perfil                    VARCHAR(20)  NOT NULL DEFAULT 'usuario',
    dois_fatores_habilitado   TINYINT(1)   NOT NULL DEFAULT 0,
    dois_fatores_metodo       VARCHAR(20)  NULL,
    empresa_id                INT          NULL,
    criado_em                 DATETIME     NOT NULL,
    CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── Tabela refeicoes ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS refeicoes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id   INT NOT NULL,
    tipo         ENUM('cafe','almoco','jantar','ceia') NOT NULL,
    media_diaria INT NOT NULL DEFAULT 0,
    criado_em    DATETIME NOT NULL,
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ─── Tabela funcionarios ─────────────────────────────────────────────────────
-- As pessoas da empresa cliente. "categoria" bate exatamente com os 3 grupos
-- que já existem no pessoas.php (Operadores / Supervisores / Prestadores),
-- então a view pode agrupar direto por essa coluna. "cargo" é o texto livre
-- que aparece embaixo do nome na lista (ex: "Operador de Produção").
CREATE TABLE IF NOT EXISTS funcionarios (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id   INT          NOT NULL,
    nome         VARCHAR(150) NOT NULL,
    cpf          VARCHAR(11)  NULL UNIQUE,
    matricula    VARCHAR(30)  NULL,
    cargo        VARCHAR(100) NOT NULL,
    categoria    ENUM('operador', 'supervisor', 'prestador') NOT NULL DEFAULT 'operador',
    foto         VARCHAR(255) NULL,
    ativo        TINYINT(1)   NOT NULL DEFAULT 1,
    criado_em    DATETIME     NOT NULL,
    CONSTRAINT fk_funcionarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    INDEX idx_funcionarios_empresa (empresa_id)
) ENGINE=InnoDB;

-- ─── Tabela credenciais_acesso ───────────────────────────────────────────────
-- RFID, biometria ou código, tudo na mesma tabela — um funcionário pode ter
-- mais de uma credencial (ex: cartão + biometria de backup). "identificador"
-- é o UID do cartão ou o hash/template da biometria, conforme o tipo.
CREATE TABLE IF NOT EXISTS credenciais_acesso (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    funcionario_id INT          NOT NULL,
    tipo           ENUM('rfid', 'biometria', 'codigo') NOT NULL DEFAULT 'rfid',
    identificador  VARCHAR(100) NOT NULL UNIQUE,
    ativo          TINYINT(1)   NOT NULL DEFAULT 1,
    criado_em      DATETIME     NOT NULL,
    CONSTRAINT fk_credenciais_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE,
    INDEX idx_credenciais_funcionario (funcionario_id)
) ENGINE=InnoDB;

-- ─── Tabela dispositivos_iot ─────────────────────────────────────────────────
-- As catracas/leitores físicos (ESP32 + RFID ou biometria). O "token" é o
-- que o próprio dispositivo manda em cada requisição pro endpoint de
-- check-access, pra autenticar sem precisar de login de usuário.
CREATE TABLE IF NOT EXISTS dispositivos_iot (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id    INT          NOT NULL,
    nome          VARCHAR(150) NOT NULL,
    tipo          ENUM('rfid', 'biometria', 'hibrido', 'balanca') NOT NULL DEFAULT 'rfid',
    localizacao   VARCHAR(150) NULL,
    token         VARCHAR(64)  NOT NULL UNIQUE,
    status        ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
    ultimo_ping   DATETIME     NULL,
    criado_em     DATETIME     NOT NULL,
    CONSTRAINT fk_dispositivos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    INDEX idx_dispositivos_empresa (empresa_id)
) ENGINE=InnoDB;

-- ─── Tabela logs_acesso ──────────────────────────────────────────────────────
-- Cada leitura na catraca, autorizada ou negada. empresa_id fica duplicado
-- aqui de propósito (em vez de só vir via funcionario/dispositivo) porque
-- toda consulta do dashboard filtra por empresa e por data — evita um JOIN
-- a mais em queries que já vão rodar muito (é daqui que sai "Acessos
-- Realizados" no painel e quem está presente agora no pessoas.php).
-- funcionario_id e credencial_id ficam nuláveis pra também registrar
-- tentativas com uma credencial desconhecida (cartão não cadastrado, etc).
CREATE TABLE IF NOT EXISTS logs_acesso (
    id             BIGINT AUTO_INCREMENT PRIMARY KEY,
    empresa_id     INT       NOT NULL,
    funcionario_id INT       NULL,
    dispositivo_id INT       NOT NULL,
    credencial_id  INT       NULL,
    direcao        ENUM('entrada', 'saida') NOT NULL DEFAULT 'entrada',
    resultado      ENUM('autorizado', 'negado') NOT NULL DEFAULT 'autorizado',
    data_hora      DATETIME  NOT NULL,
    CONSTRAINT fk_logs_empresa     FOREIGN KEY (empresa_id)     REFERENCES empresas(id)          ON DELETE CASCADE,
    CONSTRAINT fk_logs_funcionario FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)       ON DELETE SET NULL,
    CONSTRAINT fk_logs_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES dispositivos_iot(id)   ON DELETE CASCADE,
    CONSTRAINT fk_logs_credencial  FOREIGN KEY (credencial_id)  REFERENCES credenciais_acesso(id) ON DELETE SET NULL,
    INDEX idx_logs_empresa_data (empresa_id, data_hora),
    INDEX idx_logs_funcionario_data (funcionario_id, data_hora)
) ENGINE=InnoDB;

-- ─── Tabela producao_refeicoes ───────────────────────────────────────────────
-- Números reais de cada dia, por tipo de refeição — é isso que alimenta os
-- gráficos que hoje são mock: "Previsão de Demanda" e "Distribuição de
-- Refeições" no painel.php, e "Produção Diária (kg)" / "Desperdício Diário
-- (kg)" no acessos.php. Diferente da tabela "refeicoes" (que guarda só uma
-- média/meta geral por tipo), essa aqui é um registro por dia.
CREATE TABLE IF NOT EXISTS producao_refeicoes (
    id                         INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id                 INT      NOT NULL,
    data                       DATE     NOT NULL,
    tipo                       ENUM('cafe', 'almoco', 'jantar', 'ceia') NOT NULL,
    quantidade_prevista_kg     DECIMAL(8,2) NOT NULL DEFAULT 0,
    quantidade_produzida_kg    DECIMAL(8,2) NOT NULL DEFAULT 0,
    quantidade_desperdicada_kg DECIMAL(8,2) NOT NULL DEFAULT 0,
    criado_em                  DATETIME NOT NULL,
    CONSTRAINT fk_producao_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    UNIQUE KEY uk_producao_dia (empresa_id, data, tipo)
) ENGINE=InnoDB;

-- =============================================================================
-- Migration 004: Preferências do usuário e sessões ativas (tela de Configurações)
-- =============================================================================
-- IF NOT EXISTS: seguro rodar de novo. No banco de produção (cPanel), rode só este
-- bloco no phpMyAdmin (aba SQL). Enquanto ele não for rodado o sistema continua
-- funcionando: usa as preferências padrão e não controla as sessões.

-- ─── Tabela preferencias_usuario ─────────────────────────────────────────────
-- Uma linha por usuário. "notificacoes": {"previsao_demanda":true,"acesso_negado":true,
-- "dispositivo_offline":true}. "sistema": {"periodo_padrao":"mes","itens_pagina":20}.
CREATE TABLE IF NOT EXISTS preferencias_usuario (
    usuario_id    INT      NOT NULL PRIMARY KEY,
    notificacoes  JSON     NULL,
    sistema       JSON     NULL,
    atualizado_em DATETIME NOT NULL,
    CONSTRAINT fk_preferencias_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Tabela sessoes_usuario ──────────────────────────────────────────────────
-- Cada login pelo site (navegador). "sid_hash" é o SHA-256 do ID da sessão do PHP: o ID de
-- verdade nunca é guardado, então um vazamento do banco não permite sequestrar sessões.
-- "Encerrar" apaga a linha; a sessão que perdeu a linha é derrubada na próxima requisição.
CREATE TABLE IF NOT EXISTS sessoes_usuario (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id    INT          NOT NULL,
    sid_hash      CHAR(64)     NOT NULL UNIQUE,
    ip            VARCHAR(45)  NULL,
    navegador     VARCHAR(255) NULL,
    criado_em     DATETIME     NOT NULL,
    ultimo_acesso DATETIME     NOT NULL,
    CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_sessoes_usuario (usuario_id, ultimo_acesso)
) ENGINE=InnoDB;


-- =============================================================================
-- Migration 005: Cartões lidos mas ainda não cadastrados (tela Pessoas → Vincular cartão)
-- =============================================================================
-- IF NOT EXISTS: seguro rodar de novo. No banco de produção (cPanel), rode só este bloco no
-- phpMyAdmin (aba SQL). Enquanto ele não for rodado, as catracas continuam funcionando; só a lista
-- "últimas leituras" do vínculo de cartão fica vazia.

-- ─── Tabela cartoes_nao_cadastrados ──────────────────────────────────────────
-- Quando uma catraca lê um cartão que ninguém tem, o UID fica aqui (uma linha por empresa e UID,
-- com a contagem de leituras e a última). A tela de Pessoas mostra essa lista para o administrador
-- escolher o cartão que acabou de passar e vincular a uma pessoa; ao vincular, a linha some.
-- Linhas paradas há mais de 30 dias são apagadas sozinhas.
CREATE TABLE IF NOT EXISTS cartoes_nao_cadastrados (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id       INT          NOT NULL,
    identificador    VARCHAR(100) NOT NULL,
    dispositivo_id   INT          NULL,
    leituras         INT          NOT NULL DEFAULT 1,
    primeira_leitura DATETIME     NOT NULL,
    ultima_leitura   DATETIME     NOT NULL,
    CONSTRAINT fk_naocad_empresa     FOREIGN KEY (empresa_id)     REFERENCES empresas(id)        ON DELETE CASCADE,
    CONSTRAINT fk_naocad_dispositivo FOREIGN KEY (dispositivo_id) REFERENCES dispositivos_iot(id) ON DELETE SET NULL,
    UNIQUE KEY uk_naocad_empresa_uid (empresa_id, identificador),
    INDEX idx_naocad_ultima (empresa_id, ultima_leitura)
) ENGINE=InnoDB;
