<?php
    declare(strict_types=1);

    namespace App\Repositories;

    use App\Config\Database;
    use PDO;

    class StatsRepository
    {
        private PDO $db;

        public function __construct()
        {
            $this->db = Database::connexion();
        }

        public function ticketsParStatut(): array
        {
            $result = ['neuf' => 0, 'actif' => 0, 'epuise' => 0, 'expire' => 0, 'desactive' => 0];
            $rows = $this->db->query('SELECT statut, COUNT(*) AS n FROM ticket GROUP BY statut')->fetchAll();
            foreach ($rows as $row) {
                $result[$row['statut']] = (int) $row['n'];
            }

            return $result;
        }

        public function sessionsEnCours(): int
        {
            return (int) $this->db->query('SELECT COUNT(*) FROM session_wifi WHERE date_fin IS NULL')->fetchColumn();
        }

        public function volumeTotalConsomme(): float
        {
            return (float) $this->db->query('SELECT COALESCE(SUM(volume_utilise), 0) FROM session_wifi')->fetchColumn();
        }

        // Un ticket "utilisé" = connecté au moins une fois
        // Le revenu utilise le prix figé sur le ticket, pas le prix actuel du forfait
        public function ticketsUtilises(): array
        {
            $row = $this->db->query(
                'SELECT COUNT(*) AS utilises, COALESCE(SUM(prix_vente), 0) AS revenu
                FROM ticket
                WHERE date_premiere_connexion IS NOT NULL'
            )->fetch();

            return ['utilises' => (int) $row['utilises'], 'revenu' => (float) $row['revenu']];
        }

        public function parForfait(): array
        {
            $rows = $this->db->query(
                'SELECT f.nom, COUNT(t.id_ticket) AS generes, COUNT(t.date_premiere_connexion) AS utilises,
                        COALESCE(SUM(CASE WHEN t.date_premiere_connexion IS NOT NULL THEN t.prix_vente END), 0) AS revenu
                FROM forfait f
                LEFT JOIN ticket t ON t.id_forfait = f.id_forfait
                GROUP BY f.id_forfait, f.nom
                ORDER BY revenu DESC, f.nom'
            )->fetchAll();

            foreach ($rows as &$row) {
                $row['generes'] = (int) $row['generes'];
                $row['utilises'] = (int) $row['utilises'];
                $row['revenu'] = (float) $row['revenu'];
            }

            return $rows;
        }

        // Tickets activés (première connexion) et revenu par jour, jours sans vente inclus (à 0)
        public function ventesParJour(int $jours): array
        {
            $stmt = $this->db->prepare(
                'SELECT DATE(date_premiere_connexion) AS jour, COUNT(*) AS tickets, SUM(prix_vente) AS revenu
                FROM ticket
                WHERE date_premiere_connexion >= CURDATE() - INTERVAL :jours DAY
                GROUP BY DATE(date_premiere_connexion)'
            );
            $stmt->execute(['jours' => $jours - 1]);
            $parJour = [];
            foreach ($stmt->fetchAll() as $row) {
                $parJour[$row['jour']] = $row;
            }

            // Les dates sont générées par MySQL (CURDATE) pour rester sur la même horloge que les données
            $aujourdhui = (string) $this->db->query('SELECT CURDATE()')->fetchColumn();
            $result = [];
            for ($i = $jours - 1; $i >= 0; $i--) {
                $jour = date('Y-m-d', strtotime($aujourdhui . ' -' . $i . ' day'));
                $result[] = [
                    'jour'    => $jour,
                    'tickets' => (int) ($parJour[$jour]['tickets'] ?? 0),
                    'revenu'  => (float) ($parJour[$jour]['revenu'] ?? 0),
                ];
            }

            return $result;
        }
    }
