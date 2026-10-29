-- ============================================================
-- Migration du 28/10/2026 : à exécuter UNE fois sur une base
-- déjà créée avec l'ancien "wifi zone.sql" (les données sont conservées)
-- ============================================================
USE wifi_zone;

-- 1. Prix figé sur chaque ticket (le revenu ne change plus si un prix est modifié)
ALTER TABLE ticket
  ADD COLUMN prix_vente DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER volume_restant,
  ADD CONSTRAINT ck_ticket_prix CHECK (prix_vente >= 0);

-- les tickets existants reçoivent le prix actuel de leur forfait
UPDATE ticket t
JOIN forfait f ON f.id_forfait = t.id_forfait
SET t.prix_vente = f.prix;

-- 2. Précision des volumes : 2 -> 3 décimales (1 Mo au lieu de 10 Mo)
ALTER TABLE ticket       MODIFY volume_restant DECIMAL(12,3) NULL;
ALTER TABLE session_wifi MODIFY volume_utilise DECIMAL(12,3) NOT NULL DEFAULT 0;

-- 3. Dernière activité d'une session (fermeture automatique si inactive)
ALTER TABLE session_wifi
  ADD COLUMN derniere_activite DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER date_fin;

-- sessions fermées : leur date de fin ; sessions en cours : maintenant (pas de fermeture immédiate)
UPDATE session_wifi SET derniere_activite = COALESCE(date_fin, NOW());

-- 4. Échecs de connexion (anti force brute)
CREATE TABLE tentative_connexion (
  id_tentative    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cle             VARCHAR(100) NOT NULL,
  date_tentative  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT pk_tentative_connexion PRIMARY KEY (id_tentative),
  INDEX idx_tentative_cle_date (cle, date_tentative)
) ENGINE=InnoDB;
