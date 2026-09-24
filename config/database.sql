--  Código do banco do dados

-- =============================================================================
-- SICAPDA by FLUXE — Migration 003: Pessoas, Dispositivos IoT e Acessos
-- =============================================================================
--
-- IF NOT EXISTS: seguro rodar de novo mesmo se alguma tabela já existir.
-- =============================================================================
USE controle_acesso;

-- ─── Tabela empresas ────────────────────────────────────────────────────────
-- (IF NOT EXISTS: seguro rodar de novo mesmo se ela já tiver sido criada antes)
USE controle_acesso;

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
    tipo          ENUM('rfid', 'biometria', 'hibrido') NOT NULL DEFAULT 'rfid',
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