<?php
    declare(strict_types=1);

    namespace App\Repositories;

    use App\Config\Database;
    use PDO;

    class TentativeRepository
    {
        private PDO $db;

        public function __construct()
        {
            $this->db = Database::connexion();
        }

        public function add(string $cle): void
        {
            $stmt = $this->db->prepare('INSERT INTO tentative_connexion (cle) VALUES (:cle)');
            $stmt->execute(['cle' => $cle]);
        }

        public function countRecent(string $cle, int $minutes): int
        {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM tentative_connexion
                WHERE cle = :cle AND date_tentative >= NOW() - INTERVAL :minutes MINUTE'
            );
            $stmt->execute(['cle' => $cle, 'minutes' => $minutes]);

            return (int) $stmt->fetchColumn();
        }

        public function clear(string $cle): void
        {
            $stmt = $this->db->prepare('DELETE FROM tentative_connexion WHERE cle = :cle');
            $stmt->execute(['cle' => $cle]);
        }

        // Supprime les tentatives sorties de la fenêtre : la table reste petite
        public function purge(int $minutes): void
        {
            $stmt = $this->db->prepare(
                'DELETE FROM tentative_connexion WHERE date_tentative < NOW() - INTERVAL :minutes MINUTE'
            );
            $stmt->execute(['minutes' => $minutes]);
        }
    }
