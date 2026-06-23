-- ============================================================
-- MIGRAÇÃO v4 — Etapas 3 a 7
-- Sistema de Satisfação (3 Pilares), Quests, Confrontos Pendentes,
-- Conhecimento CCG, Derrota Sinistra
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Adiciona os 3 pilares de satisfação aos distritos
ALTER TABLE distritos
  ADD COLUMN seguranca      INT NOT NULL DEFAULT 50,
  ADD COLUMN economia       INT NOT NULL DEFAULT 50,
  ADD COLUMN suprimentos_pop INT NOT NULL DEFAULT 50,
  ADD COLUMN satisfacao_geral INT GENERATED ALWAYS AS (ROUND((seguranca + economia + suprimentos_pop) / 3)) STORED;

-- Migra apoio_civil existente para os 3 pilares
UPDATE distritos SET seguranca = apoio_civil, economia = apoio_civil, suprimentos_pop = apoio_civil;

-- 2. Quests por distrito
CREATE TABLE IF NOT EXISTS quests_distrito (
  id                INT          AUTO_INCREMENT PRIMARY KEY,
  distrito_id       INT          NOT NULL,
  titulo            VARCHAR(100) NOT NULL,
  descricao         TEXT         NOT NULL,
  tipo              ENUM('principal','secundaria') NOT NULL,
  status            ENUM('oculta','revelada','concluida') NOT NULL DEFAULT 'oculta',
  requer_satisfacao INT          NOT NULL DEFAULT 60,
  faccao_completou  VARCHAR(50)  NULL,
  data_conclusao    DATETIME     NULL,
  KEY idx_q_distrito (distrito_id),
  CONSTRAINT fk_quest_distrito FOREIGN KEY (distrito_id) REFERENCES distritos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Confrontos pendentes (escolha de tática pelos jogadores)
CREATE TABLE IF NOT EXISTS confrontos_pendentes (
  id              VARCHAR(50) NOT NULL,
  atacante_id     VARCHAR(50) NOT NULL,
  defensor_id     VARCHAR(50) NOT NULL DEFAULT 'ccg',
  distrito_id     INT         NULL,
  message_id      VARCHAR(50) NULL,
  tatica_atacante VARCHAR(20) NULL,
  expira_em       DATETIME    NOT NULL,
  resolvido       TINYINT(1)  NOT NULL DEFAULT 0,
  data_criacao    DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Conhecimento CCG sobre cada distrito
CREATE TABLE IF NOT EXISTS conhecimento_ccg (
  distrito_id          INT         NOT NULL,
  nivel_conhecimento   ENUM('desconhecido','basico','bom','critico') NOT NULL DEFAULT 'desconhecido',
  faccao_conhecida     VARCHAR(50) NULL,
  dominacao_conhecida  INT         NULL,
  tatica_conhecida     VARCHAR(20) NULL,
  poder_aproximado     VARCHAR(20) NULL,
  ultima_investigacao  DATETIME    NULL,
  PRIMARY KEY (distrito_id),
  CONSTRAINT fk_conhecimento_distrito FOREIGN KEY (distrito_id) REFERENCES distritos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: todos desconhecidos por padrão, Chiyoda (#15) já é base da CCG
INSERT IGNORE INTO conhecimento_ccg (distrito_id, nivel_conhecimento)
SELECT id, 'desconhecido' FROM distritos;

UPDATE conhecimento_ccg
SET nivel_conhecimento = 'critico', faccao_conhecida = 'ccg', dominacao_conhecida = 100
WHERE distrito_id = 15;

-- 5. Derrota sinistra e escolha manual no histórico de combates
ALTER TABLE historico_combates
  ADD COLUMN derrota_sinistra TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN escolha_manual   TINYINT(1) NOT NULL DEFAULT 0;

SET FOREIGN_KEY_CHECKS = 1;
