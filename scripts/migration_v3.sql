-- ============================================================
-- MIGRAÇÃO v3
-- 23 distritos completos + adjacências + posição das facções
-- + limites semanais + movimentos + missões urgentes
-- + informantes fixos + agentes feridos
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Limpa tabelas dependentes de distritos
TRUNCATE TABLE eventos_mapa;
DELETE FROM distritos;

-- 2. Atualiza base da CCG para Chiyoda (15) antes de reinserir distritos
UPDATE faccoes SET distrito_base = 15 WHERE id = 'ccg';
UPDATE faccoes SET distrito_base = 11 WHERE id = 'aogiri';

-- 3. Insere os 23 distritos
INSERT INTO distritos (id, nome, bonus_dominio, faccao_dominante_id, apoio_civil, nivel_alerta, nivel_dominacao, status_guerra) VALUES
( 1, 'Adachi',    'Operacoes defensivas ganham +15 Poder Militar.',                        NULL,    30, 1, 100, 'pacificado'),
( 2, 'Katsushika','Custo de informantes reduzido em 25%.',                                 NULL,    40, 1, 100, 'pacificado'),
( 3, 'Edogawa',   'Operacoes de contrabando sao detectadas com mais facilidade.',          NULL,    35, 1, 100, 'pacificado'),
( 4, 'Koto',      'Rotas portuarias: suprimentos +20 em contrabando interceptado.',        NULL,    25, 1, 100, 'pacificado'),
( 5, 'Sumida',    'Alta densidade urbana: todas as operacoes levam 1h extra.',             NULL,    20, 2, 100, 'pacificado'),
( 6, 'Taito',     'Zona cultural: campanhas de midia tem 50% mais efeito.',                NULL,    50, 1, 100, 'pacificado'),
( 7, 'Arakawa',   'Zona industrial: ghouls tem facilidade de se ocultar.',                 NULL,    30, 1, 100, 'pacificado'),
( 8, 'Bunkyo',    'Zona universitaria: pesquisa de Quinque custa 25% menos.',              NULL,    55, 1, 100, 'pacificado'),
( 9, 'Kita',      'Investigacoes revelam detalhes extras sobre movimentos inimigos.',      NULL,    45, 1, 100, 'pacificado'),
(10, 'Toshima',   'Zona de entretenimento: informantes custam 30% menos.',                 NULL,    40, 1, 100, 'pacificado'),
(11, 'Itabashi',  'Recrutamento da Aogiri e 20% mais eficiente nesta area.',              'aogiri', 10, 2,  60, 'em_disputa'),
(12, 'Nerima',    'Zona residencial: apoio civil sobe +2 passivamente por tick.',         NULL,    60, 1, 100, 'pacificado'),
(13, 'Nakano',    'Zona comercial: atividade de contrabando frequente.',                  'aogiri', 15, 2, 100, 'pacificado'),
(14, 'Shinjuku',  'Alta densidade: patrulhas tem menor visibilidade.',                    NULL,    30, 2, 100, 'pacificado'),
(15, 'Chiyoda',   'Centro administrativo: Ghouls perdem sigilo ao entrar neste distrito.','ccg',   70, 1, 100, 'pacificado'),
(16, 'Chuo',      'Centro financeiro: renda semanal da CCG aumenta em +300.',             NULL,    50, 1, 100, 'pacificado'),
(17, 'Minato',    'Zona diplomatica: campanhas de midia tem efeito nos distritos adjacentes.', NULL, 65, 1, 100, 'pacificado'),
(18, 'Shibuya',   'Alta densidade comercial: informantes tem acesso ampliado.',           NULL,    35, 2, 100, 'pacificado'),
(19, 'Suginami',  'Zona mista: area estrategica de transicao.',                           NULL,    45, 1, 100, 'pacificado'),
(20, 'Setagaya',  'Zona residencial: apoio civil sobe passivamente.',                     NULL,    55, 1, 100, 'pacificado'),
(21, 'Meguro',    'Bairro nobre: indenizacoes por dano colateral custam 50% mais.',       NULL,    60, 1, 100, 'pacificado'),
(22, 'Shinagawa', 'Hub de transporte: duracao de operacoes reduzida em 1h.',              NULL,    40, 1, 100, 'pacificado'),
(23, 'Ota',       'Area industrial e portuaria: ghouls tem refugios de dificil acesso.',  NULL,    25, 1, 100, 'pacificado');

-- 4. Tabela de adjacências (grafo bidirec.)
CREATE TABLE IF NOT EXISTS adjacencias (
  distrito_a INT NOT NULL,
  distrito_b INT NOT NULL,
  PRIMARY KEY (distrito_a, distrito_b),
  CONSTRAINT adj_ibfk_a FOREIGN KEY (distrito_a) REFERENCES distritos(id) ON DELETE CASCADE,
  CONSTRAINT adj_ibfk_b FOREIGN KEY (distrito_b) REFERENCES distritos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO adjacencias (distrito_a, distrito_b) VALUES
-- 1 Adachi
(1,2),(1,7),(1,9),(1,11),
(2,1),(2,3),(2,5),(2,7),
-- 3 Edogawa
(3,2),(3,4),(3,5),
(4,3),(4,5),(4,16),(4,23),
-- 5 Sumida
(5,2),(5,3),(5,4),(5,6),(5,7),
(6,5),(6,7),(6,8),
(7,1),(7,2),(7,5),(7,6),(7,9),
-- 8 Bunkyo
(8,6),(8,7),(8,9),(8,10),(8,14),(8,15),
(9,1),(9,7),(9,8),(9,10),(9,11),
-- 10 Toshima
(10,8),(10,9),(10,11),(10,12),(10,13),(10,14),
(11,1),(11,9),(11,10),(11,12),
(12,10),(12,11),(12,13),(12,19),
(13,10),(13,12),(13,14),(13,18),(13,19),
(14,8),(14,10),(14,13),(14,15),(14,18),
-- 15 Chiyoda
(15,8),(15,14),(15,16),(15,17),(15,18),
(16,4),(16,5),(16,8),(16,15),(16,17),
(17,15),(17,16),(17,18),(17,21),(17,22),
(18,13),(18,14),(18,15),(18,17),(18,19),(18,21),
(19,12),(19,13),(19,18),(19,20),(19,21),
(20,19),(20,21),(20,23),
(21,17),(21,18),(21,19),(21,20),(21,22),
(22,17),(22,21),(22,23),
(23,4),(23,20),(23,22);

-- 5. Posição atual e agentes feridos nas facções
ALTER TABLE faccoes
  ADD COLUMN posicao_atual INT DEFAULT NULL,
  ADD COLUMN agentes_feridos_ate DATETIME DEFAULT NULL;

UPDATE faccoes SET posicao_atual = distrito_base;

-- 6. Movimentos registrados das facções
CREATE TABLE IF NOT EXISTS movimentos_faccoes (
  id               INT          NOT NULL AUTO_INCREMENT,
  faccao_id        VARCHAR(50)  NOT NULL,
  distrito_origem  INT          NOT NULL,
  distrito_destino INT          NOT NULL,
  motivo           VARCHAR(50)  DEFAULT 'rotina',
  data_movimento   DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY faccao_id (faccao_id),
  KEY distrito_destino (distrito_destino),
  CONSTRAINT mov_ibfk_1 FOREIGN KEY (faccao_id) REFERENCES faccoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 7. Controle de ações semanais (por facção, por tipo, por semana)
CREATE TABLE IF NOT EXISTS acoes_semanais (
  id          INT         NOT NULL AUTO_INCREMENT,
  faccao_id   VARCHAR(50) NOT NULL,
  tipo_acao   VARCHAR(50) NOT NULL,
  semana      DATE        NOT NULL,
  quantidade  INT         DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uk_faccao_tipo_semana (faccao_id, tipo_acao, semana),
  CONSTRAINT as_ibfk_1 FOREIGN KEY (faccao_id) REFERENCES faccoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 8. Informantes fixos por distrito
CREATE TABLE IF NOT EXISTS informantes_fixos (
  distrito_id         INT         NOT NULL,
  faccao_id           VARCHAR(50) NOT NULL DEFAULT 'ccg',
  ativo               TINYINT(1)  DEFAULT 1,
  data_recrutamento   DATETIME    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (distrito_id, faccao_id),
  CONSTRAINT inf_ibfk_1 FOREIGN KEY (distrito_id) REFERENCES distritos(id) ON DELETE CASCADE,
  CONSTRAINT inf_ibfk_2 FOREIGN KEY (faccao_id)   REFERENCES faccoes(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 9. Missões urgentes semanais
CREATE TABLE IF NOT EXISTS missoes_urgentes (
  id               INT          NOT NULL AUTO_INCREMENT,
  distrito_id      INT          NOT NULL,
  titulo           VARCHAR(200) NOT NULL,
  descricao        TEXT,
  data_limite      DATETIME     NOT NULL,
  status           VARCHAR(20)  DEFAULT 'ativa',
  penalidade_renda INT          DEFAULT 500,
  cumprida_por     VARCHAR(50)  DEFAULT NULL,
  data_criacao     DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY distrito_id (distrito_id),
  CONSTRAINT mu_ibfk_1 FOREIGN KEY (distrito_id) REFERENCES distritos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS = 1;
