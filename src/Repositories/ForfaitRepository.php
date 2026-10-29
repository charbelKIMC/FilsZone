<?php
    declare(strict_types=1);

    namespace App\Repositories;

    use App\Config\Database;
    use PDO;

    class ForfaitRepository
    {
        private PDO $db;

        public function __construct()
        {
            $this->db = Database::connexion();
        }

        public function all(): array
        {
            // nb_tickets : l'interface s'en sert pour bloquer la suppression et la modification des limites
            $rows = $this->db->query(
                'SELECT f.id_forfait, f.nom, f.prix, f.volume_go, f.duree_validite,
                        (SELECT COUNT(*) FROM ticket t WHERE t.id_forfait = f.id_forfait) AS nb_tickets
                FROM forfait f ORDER BY f.prix, f.nom'
            )->fetchAll();

            return array_map([$this, 'hydrate'], $rows);
        }

        public function find(int $id): ?array
        {
            $stmt = $this->db->prepare(
                'SELECT id_forfait, nom, prix, volume_go, duree_validite FROM forfait WHERE id_forfait = :id'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            return $row ? $this->hydrate($row) : null;
        }

        public function create(array $data): int
        {
            $stmt = $this->db->prepare(
                'INSERT INTO forfait (nom, prix, volume_go, duree_validite, id_admin)
                VALUES (:nom, :prix, :volume_go, :duree_validite, :id_admin)'
            );
            $stmt->execute([
                'nom'            => $data['nom'],
                'prix'           => $data['prix'],
                'volume_go'      => $data['volume_go'],
                'duree_validite' => $data['duree_validite'],
                'id_admin'       => $data['id_admin'],
            ]);

            return (int) $this->db->lastInsertId();
        }

        public function update(int $id, array $data): void
        {
            $stmt = $this->db->prepare(
                'UPDATE forfait
                SET nom = :nom, prix = :prix, volume_go = :volume_go, duree_validite = :duree_validite
                WHERE id_forfait = :id'
            );
            $stmt->execute([
                'nom'            => $data['nom'],
                'prix'           => $data['prix'],
                'volume_go'      => $data['volume_go'],
                'duree_validite' => $data['duree_validite'],
                'id'             => $id,
            ]);
        }

        public function delete(int $id): void
        {
            $stmt = $this->db->prepare('DELETE FROM forfait WHERE id_forfait = :id');
            $stmt->execute(['id' => $id]);
        }

        public function countTickets(int $id): int
        {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ticket WHERE id_forfait = :id');
            $stmt->execute(['id' => $id]);

            return (int) $stmt->fetchColumn();
        }

        // MySQL renvoie les DECIMAL en texte : on les convertit en nombres
        private function hydrate(array $row): array
        {
            $forfait = [
                'id_forfait'     => (int) $row['id_forfait'],
                'nom'            => $row['nom'],
                'prix'           => (float) $row['prix'],
                'volume_go'      => $row['volume_go'] === null ? null : (float) $row['volume_go'],
                'duree_validite' => $row['duree_validite'] === null ? null : (int) $row['duree_validite'],
            ];
            if (isset($row['nb_tickets'])) {
                $forfait['nb_tickets'] = (int) $row['nb_tickets'];
            }

            return $forfait;
        }
    }
