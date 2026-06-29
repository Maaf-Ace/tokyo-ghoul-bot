-- Migration: tabela para confrontos enviados ao combate em mesa (standby GM)
CREATE TABLE IF NOT EXISTS confrontos_mesa (
    confronto_id   VARCHAR(64)  PRIMARY KEY,
    atacante_id    VARCHAR(32)  NOT NULL,
    distrito_id    INT          DEFAULT 0,
    tatica_atacante VARCHAR(32) DEFAULT NULL,
    status         ENUM('pendente','resolvido','cancelado') DEFAULT 'pendente',
    vencedor_id    VARCHAR(32)  DEFAULT NULL,
    data_criacao   DATETIME     DEFAULT NOW(),
    data_resolucao DATETIME     DEFAULT NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
