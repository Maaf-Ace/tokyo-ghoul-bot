-- Migração: sistema de operações agendadas para facções ghoul
-- Execute uma única vez: mysql -h HOST -P PORT -u USER -pPASS DB < migration_ghoul_ops.sql

CREATE TABLE IF NOT EXISTS operacoes_ghoul (
    id             VARCHAR(36)   PRIMARY KEY,
    faccao_id      VARCHAR(50)   NOT NULL,
    tipo           VARCHAR(30)   NOT NULL,       -- alimentar | cacar | recrutar | contrabandear
    distrito_id    INT           NOT NULL DEFAULT 0,
    data_inicio    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_fim       DATETIME      NOT NULL,
    concluida      TINYINT(1)    NOT NULL DEFAULT 0,
    interceptada   TINYINT(1)    NOT NULL DEFAULT 0,
    alerta_enviado TINYINT(1)    NOT NULL DEFAULT 0,
    resultado      TEXT          DEFAULT NULL,
    INDEX idx_faccao (faccao_id, concluida),
    INDEX idx_fim    (data_fim,  concluida)
);
