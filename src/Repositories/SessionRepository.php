<?php
    declare(strict_types=1);

    namespace App\Repositories;

    use App\Config\Database;
    use PDO;

    class SessionRepository
    {
        private PDO $db;

        public function __construct()
        {
            $this->db = Database::connexion();
        }

        public function open(int $idTicket, string $mac, string $ip): int
        {
            $stmt = $this->db->prepare(
                'INSERT INTO session_wifi (adresse_mac, adresse_ip, id_ticket)
                VALUES (:mac, :ip, :id_ticket)'
            );
            $stmt->execute(['mac' => $mac, 'ip' => $ip, 'id_ticket' => $idTicket]);

            return (int) $this->db->lastInsertId();
        }

        public function close(int $idSession): void
        {
            $stmt = $this->db->prepare(
                'UPDATE session_wifi SET date_fin = NOW() WHERE id_session = :id AND date_fin IS NULL'
            );
            $stmt->execute(['id' => $idSession]);
        }

        // Signale que le client est toujours là (appelé à chaque requête authentifiée)
        public function touch(int $idSession): void
        {
            $stmt = $this->db->prepare(
                'UPDATE session_wifi SET derniere_activite = NOW() WHERE id_session = :id AND date_fin IS NULL'
            );
            $stmt->execute(['id' => $idSession]);
        }

        // Ferme les sessions sans activité depuis $minutes : la fin retenue est la dernière activité
        // (GREATEST : jamais avant date_debut, sinon le CHECK ck_session_dates bloquerait toute l'API)
        public function closeInactive(int $minutes): void
        {
            $stmt = $this->db->prepare(
                'UPDATE session_wifi SET date_fin = GREATEST(date_debut, derniere_activite)
                WHERE date_fin IS NULL AND derniere_activite < NOW() - INTERVAL :minutes MINUTE'
            );
            $stmt->execute(['minutes' => $minutes]);
        }

        public function findById(int $idSession): ?array
        {
            $stmt = $this->db->prepare(
                'SELECT id_session, id_ticket, date_debut, date_fin, derniere_activite, adresse_mac, adresse_ip, volume_utilise,
                        TIMESTAMPDIFF(SECOND, date_debut, COALESCE(date_fin, NOW())) AS duree_secondes
                FROM session_wifi WHERE id_session = :id'
            );
            $stmt->execute(['id' => $idSession]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $row['id_session'] = (int) $row['id_session'];
            $row['id_ticket'] = (int) $row['id_ticket'];
            $row['volume_utilise'] = (float) $row['volume_utilise'];
            $row['duree_secondes'] = (int) $row['duree_secondes'];

            return $row;
        }

        // Cumul de toutes les sessions d'un ticket (récapitulatif client)
        public function totalsByTicket(int $idTicket): array
        {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) AS nb_sessions, COALESCE(SUM(volume_utilise), 0) AS volume_utilise
                FROM session_wifi WHERE id_ticket = :id'
            );
            $stmt->execute(['id' => $idTicket]);
            $row = $stmt->fetch();

            return ['nb_sessions' => (int) $row['nb_sessions'], 'volume_utilise' => (float) $row['volume_utilise']];
        }

        public function findOpenByTicket(int $idTicket): ?array
        {
            $stmt = $this->db->prepare(
                'SELECT id_session, adresse_mac FROM session_wifi
                WHERE id_ticket = :id AND date_fin IS NULL
                ORDER BY id_session DESC LIMIT 1'
            );
            $stmt->execute(['id' => $idTicket]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $row['id_session'] = (int) $row['id_session'];

            return $row;
        }

        public function addUsage(int $idSession, float $go): void
        {
            $stmt = $this->db->prepare(
                'UPDATE session_wifi SET volume_utilise = volume_utilise + :go WHERE id_session = :id'
            );
            $stmt->execute(['go' => $go, 'id' => $idSession]);
        }

        // Ferme les sessions encore ouvertes dont le ticket est expiré, épuisé ou désactivé
        public function closeOrphans(): void
        {
            $this->db->exec(
                "UPDATE session_wifi s
                JOIN ticket t ON t.id_ticket = s.id_ticket
                SET s.date_fin = GREATEST(
                    s.date_debut,
                    IF(t.statut = 'expire' AND t.date_expiration IS NOT NULL,
                        LEAST(NOW(), t.date_expiration),
                        NOW())
                )
                WHERE s.date_fin IS NULL AND t.statut IN ('expire', 'epuise', 'desactive')"
            );
        }

        public function list(bool $enCours, int $limit, int $offset): array
        {
            $where = $enCours ? ' WHERE s.date_fin IS NULL' : '';
            $stmt = $this->db->prepare(
                'SELECT s.id_session, t.identifiant, f.nom AS forfait, s.date_debut, s.date_fin,
                        s.adresse_mac, s.adresse_ip, s.volume_utilise,
                        TIMESTAMPDIFF(SECOND, s.date_debut, COALESCE(s.date_fin, NOW())) AS duree_secondes
                FROM session_wifi s
                JOIN ticket t ON t.id_ticket = s.id_ticket
                JOIN forfait f ON f.id_forfait = t.id_forfait'
                . $where .
                ' ORDER BY s.id_session DESC LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['id_session'] = (int) $row['id_session'];
                $row['volume_utilise'] = (float) $row['volume_utilise'];
                $row['duree_secondes'] = (int) $row['duree_secondes'];
            }

            return $rows;
        }

        public function count(bool $enCours): int
        {
            $sql = 'SELECT COUNT(*) FROM session_wifi' . ($enCours ? ' WHERE date_fin IS NULL' : '');

            return (int) $this->db->query($sql)->fetchColumn();
        }
    }
