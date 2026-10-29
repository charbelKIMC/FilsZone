-- ============================================================
-- Plateforme Wi-Fi zone : base de données
-- SGBD : MySQL 8.0.16+ / MariaDB 10.2+ (les CHECK ne sont appliqués
-- qu'à partir de ces versions)
-- ============================================================

DROP DATABASE IF EXISTS wifi_zone;
CREATE DATABASE wifi_zone
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE wifi_zone;

-- ------------------------------------------------------------
-- ADMINISTRATEUR : le propriétaire de la zone
-- ------------------------------------------------------------
CREATE TABLE administrateur (
  id_admin      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  login         VARCHAR(50)  NOT NULL,
  mot_de_passe  VARCHAR(255) NOT NULL COMMENT 'hash (bcrypt/argon2), jamais en clair',
  CONSTRAINT pk_administrateur       PRIMARY KEY (id_admin),
  CONSTRAINT uq_administrateur_login UNIQUE (login)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- FORFAIT : volume, durée, ou les deux
--   volume_go      NULL = volume illimité
--   duree_validite NULL = pas de limite de temps (en heures)
-- ------------------------------------------------------------
CREATE TABLE forfait (
  id_forfait      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  nom             VARCHAR(50)   NOT NULL,
  prix            DECIMAL(10,2) NOT NULL,
  volume_go       DECIMAL(8,2)  NULL,
  duree_validite  INT UNSIGNED  NULL COMMENT 'en heures',
  id_admin        INT UNSIGNED  NOT NULL,
  CONSTRAINT pk_forfait        PRIMARY KEY (id_forfait),
  CONSTRAINT uq_forfait_nom    UNIQUE (nom),
  CONSTRAINT fk_forfait_admin  FOREIGN KEY (id_admin)
    REFERENCES administrateur (id_admin)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT ck_forfait_prix   CHECK (prix >= 0),
  CONSTRAINT ck_forfait_volume CHECK (volume_go IS NULL OR volume_go > 0),
  CONSTRAINT ck_forfait_duree  CHECK (duree_validite IS NULL OR duree_validite > 0),
  -- un forfait doit avoir au moins une limite (volume ou durée)
  CONSTRAINT ck_forfait_limite CHECK (volume_go IS NOT NULL OR duree_validite IS NOT NULL)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TICKET : identifiant + mot de passe distribués à l'utilisateur
--   date_premiere_connexion : NULL tant que le ticket n'a jamais servi
--   date_expiration         : calculée à la première connexion
--                             (première connexion + duree_validite),
--                             NULL si le forfait n'a pas de durée
--   volume_restant          : initialisé avec forfait.volume_go à la
--                             génération, NULL si volume illimité
--                             (3 décimales = précision de 1 Mo)
--   prix_vente              : prix du forfait au moment de la génération
--                             (les stats ne changent pas si le prix change)
-- ------------------------------------------------------------
CREATE TABLE ticket (
  id_ticket                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifiant              VARCHAR(20)  NOT NULL,
  mot_de_passe             VARCHAR(255) NOT NULL COMMENT 'hash ; le mot de passe en clair est imprimé une seule fois à la génération',
  statut                   ENUM('neuf','actif','epuise','expire','desactive')
                           NOT NULL DEFAULT 'neuf',
  date_creation            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_premiere_connexion  DATETIME     NULL,
  date_expiration          DATETIME     NULL,
  volume_restant           DECIMAL(12,3) NULL,
  prix_vente               DECIMAL(10,2) NOT NULL DEFAULT 0,
  id_forfait               INT UNSIGNED NOT NULL,
  id_admin                 INT UNSIGNED NOT NULL,
  CONSTRAINT pk_ticket             PRIMARY KEY (id_ticket),
  CONSTRAINT uq_ticket_identifiant UNIQUE (identifiant),
  CONSTRAINT fk_ticket_forfait     FOREIGN KEY (id_forfait)
    REFERENCES forfait (id_forfait)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_admin       FOREIGN KEY (id_admin)
    REFERENCES administrateur (id_admin)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT ck_ticket_volume      CHECK (volume_restant IS NULL OR volume_restant >= 0),
  CONSTRAINT ck_ticket_prix        CHECK (prix_vente >= 0),
  -- une expiration n'existe qu'après la première connexion et lui est postérieure
  CONSTRAINT ck_ticket_expiration  CHECK (date_expiration IS NULL
    OR (date_premiere_connexion IS NOT NULL AND date_expiration > date_premiere_connexion)),
  -- un ticket "neuf" n'a jamais été utilisé
  CONSTRAINT ck_ticket_neuf        CHECK (statut <> 'neuf' OR date_premiere_connexion IS NULL),
  INDEX idx_ticket_statut (statut),
  INDEX idx_ticket_forfait (id_forfait),
  INDEX idx_ticket_admin (id_admin)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SESSION_WIFI : une connexion d'un ticket
--   (nommée session_wifi car SESSION est un mot-clé MySQL)
--   date_fin NULL = session en cours
--   derniere_activite : mise à jour à chaque requête du client ; une
--                       session inactive trop longtemps est fermée
-- ------------------------------------------------------------
CREATE TABLE session_wifi (
  id_session        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  date_debut        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_fin          DATETIME      NULL,
  derniere_activite DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  adresse_mac       CHAR(17)      NOT NULL,
  adresse_ip        VARCHAR(45)   NOT NULL COMMENT 'IPv4 ou IPv6',
  volume_utilise    DECIMAL(12,3) NOT NULL DEFAULT 0,
  id_ticket       INT UNSIGNED NOT NULL,
  CONSTRAINT pk_session_wifi        PRIMARY KEY (id_session),
  CONSTRAINT fk_session_ticket      FOREIGN KEY (id_ticket)
    REFERENCES ticket (id_ticket)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT ck_session_volume      CHECK (volume_utilise >= 0),
  CONSTRAINT ck_session_dates       CHECK (date_fin IS NULL OR date_fin >= date_debut),
  CONSTRAINT ck_session_mac         CHECK (adresse_mac REGEXP '^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$'),
  -- sert à retrouver rapidement les sessions d'un ticket et celles en cours
  INDEX idx_session_ticket_fin (id_ticket, date_fin)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TENTATIVE_CONNEXION : échecs de connexion récents (anti force brute)
--   cle : "admin:<ip>" ou "client:<ip>"
--   les lignes plus anciennes que la fenêtre de blocage sont purgées
-- ------------------------------------------------------------
CREATE TABLE tentative_connexion (
  id_tentative    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cle             VARCHAR(100) NOT NULL,
  date_tentative  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT pk_tentative_connexion PRIMARY KEY (id_tentative),
  INDEX idx_tentative_cle_date (cle, date_tentative)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Données de départ (à adapter)
-- ------------------------------------------------------------
INSERT INTO administrateur (login, mot_de_passe)
VALUES ('admin', 'REMPLACER_PAR_UN_HASH');

-- prix à définir (0.00 en attendant)
INSERT INTO forfait (nom, prix, volume_go, duree_validite, id_admin) VALUES
  ('2 Go',              100.00, 2,    NULL, 1),
  ('3 Go',              150.00, 3,    NULL, 1),
  ('5 Go',              200.00, 5,    NULL, 1),
  ('Illimité 24 h',     300.00, NULL, 24,   1),
  ('Illimité semaine',  2000.00, NULL, 168,  1),
  ('Illimité mois',     3500.00, NULL, 720,  1),
  ('2 Go journalier',   100.00, 2,    24,   1),
  ('5 Go journalier',   150.00, 5,    24,   1);

