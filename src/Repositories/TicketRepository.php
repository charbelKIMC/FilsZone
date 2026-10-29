<?php
    declare(strict_types=1);

    namespace App\Repositories;

    use App\Config\Database;
    use PDO;
    use Throwable;

    class TicketRepository
    {
        // secondes_restantes est calculé par MySQL (NOW()) pour éviter tout décalage d'horloge avec PHP
        private const SELECT = 'SELECT t.id_ticket, t.identifiant, t.mot_de_passe, t.statut,
                t.date_premiere_connexion, t.date_expiration, t.volume_restant, t.prix_vente, t.id_forfait,
                f.nom AS forfait, f.volume_go AS volume_total, f.duree_validite,
                TIMESTAMPDIFF(SECOND, NOW(), t.date_expiration) AS secondes_restantes
            FROM ticket t
            JOIN forfait f ON f.id_forfait = t.id_forfait';

        private PDO $db;

        public function __construct()
        {
            $this->db = Database::connexion();
        }

        public function findByIdentifiant(string $identifiant): ?array
        {
            $stmt = $this->db->prepare(self::SELECT . ' WHERE t.identifiant = :identifiant');
            $stmt->execute(['identifiant' => $identifiant]);
            $row = $stmt->fetch();

            return $row ? $this->hydrate($row) : null;
        }

        // Sans le hash du mot de passe : il ne doit jamais sortir de la couche d'authentification
        public function findById(int $id): ?array
        {
            $stmt = $this->db->prepare(self::SELECT . ' WHERE t.id_ticket = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            unset($row['mot_de_passe']);

            return $this->hydrate($row);
        }

        // Verrouille la ligne du ticket jusqu'à la fin de la transaction en cours :
        // une autre requête qui veut verrouiller le même ticket attend son tour
        public function lock(int $id): void
        {
            $stmt = $this->db->prepare('SELECT id_ticket FROM ticket WHERE id_ticket = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
        }

        public function identifiantExists(string $identifiant): bool
        {
            $stmt = $this->db->prepare('SELECT 1 FROM ticket WHERE identifiant = :identifiant LIMIT 1');
            $stmt->execute(['identifiant' => $identifiant]);

            return $stmt->fetchColumn() !== false;
        }

        // Insère tout le lot ou rien (transaction)
        public function createMany(array $rows): void
        {
            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare(
                    'INSERT INTO ticket (identifiant, mot_de_passe, volume_restant, prix_vente, id_forfait, id_admin)
                    VALUES (:identifiant, :mot_de_passe, :volume_restant, :prix_vente, :id_forfait, :id_admin)'
                );
                foreach ($rows as $row) {
                    $stmt->execute($row);
                }
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        }

        // Filtres : statut exact et/ou début d'identifiant (recherche)
        public function list(?string $statut, ?string $recherche, int $limit, int $offset): array
        {
            [$where, $params] = $this->filters($statut, $recherche);
            $stmt = $this->db->prepare(
                'SELECT t.id_ticket, t.identifiant, t.statut, t.date_creation,
                        t.date_premiere_connexion, t.date_expiration, t.volume_restant, t.prix_vente,
                        f.nom AS forfait, f.volume_go AS volume_total
                FROM ticket t
                JOIN forfait f ON f.id_forfait = t.id_forfait'
                . $where .
                ' ORDER BY t.id_ticket DESC LIMIT :limit OFFSET :offset'
            );
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $rows = $stmt->fetchAll();
            foreach ($rows as &$row) {
                $row['id_ticket'] = (int) $row['id_ticket'];
                $row['volume_restant'] = $row['volume_restant'] === null ? null : (float) $row['volume_restant'];
                $row['volume_total'] = $row['volume_total'] === null ? null : (float) $row['volume_total'];
                $row['prix_vente'] = (float) $row['prix_vente'];
            }

            return $rows;
        }

        public function count(?string $statut, ?string $recherche): int
        {
            [$where, $params] = $this->filters($statut, $recherche);
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM ticket t' . $where);
            $stmt->execute($params);

            return (int) $stmt->fetchColumn();
        }

        private function filters(?string $statut, ?string $recherche): array
        {
            $conditions = [];
            $params = [];
            if ($statut !== null) {
                $conditions[] = 't.statut = :statut';
                $params[':statut'] = $statut;
            }
            if ($recherche !== null) {
                // Identifiants : lettres et chiffres uniquement (validé par le service), rien à échapper pour LIKE
                $conditions[] = 't.identifiant LIKE :recherche';
                $params[':recherche'] = $recherche . '%';
            }

            return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
        }

        public function setStatut(int $id, string $statut): void
        {
            $stmt = $this->db->prepare('UPDATE ticket SET statut = :statut WHERE id_ticket = :id');
            $stmt->execute(['statut' => $statut, 'id' => $id]);
        }

        // Première connexion : le décompte du temps démarre ici (et pas à la génération)
        public function startFirstUse(int $id, ?int $dureeHeures): void
        {
            if ($dureeHeures === null) {
                $stmt = $this->db->prepare(
                    "UPDATE ticket SET date_premiere_connexion = NOW(), statut = 'actif'
                    WHERE id_ticket = :id AND date_premiere_connexion IS NULL"
                );
                $stmt->execute(['id' => $id]);
                return;
            }

            $stmt = $this->db->prepare(
                "UPDATE ticket
                SET date_premiere_connexion = NOW(),
                    date_expiration = DATE_ADD(NOW(), INTERVAL :heures HOUR),
                    statut = 'actif'
                WHERE id_ticket = :id AND date_premiere_connexion IS NULL"
            );
            $stmt->execute(['heures' => $dureeHeures, 'id' => $id]);
        }

        // Retire du volume sans jamais descendre sous 0 (ignoré pour un volume illimité : NULL)
        public function consumeVolume(int $id, float $go): void
        {
            $stmt = $this->db->prepare(
                'UPDATE ticket SET volume_restant = GREATEST(volume_restant - :go, 0)
                WHERE id_ticket = :id AND volume_restant IS NOT NULL'
            );
            $stmt->execute(['go' => $go, 'id' => $id]);
        }

        // Passe en "expire" ou "epuise" les tickets actifs qui ont dépassé leur limite
        public function refreshStatuses(): void
        {
            $this->db->exec(
                "UPDATE ticket SET statut = 'expire'
                WHERE statut = 'actif' AND date_expiration IS NOT NULL AND date_expiration <= NOW()"
            );
            $this->db->exec(
                "UPDATE ticket SET statut = 'epuise'
                WHERE statut = 'actif' AND volume_restant IS NOT NULL AND volume_restant <= 0"
            );
        }

        private function hydrate(array $row): array
        {
            $row['id_ticket'] = (int) $row['id_ticket'];
            $row['id_forfait'] = (int) $row['id_forfait'];
            $row['volume_restant'] = $row['volume_restant'] === null ? null : (float) $row['volume_restant'];
            $row['volume_total'] = $row['volume_total'] === null ? null : (float) $row['volume_total'];
            $row['prix_vente'] = (float) $row['prix_vente'];
            $row['duree_validite'] = $row['duree_validite'] === null ? null : (int) $row['duree_validite'];
            $row['secondes_restantes'] = $row['secondes_restantes'] === null ? null : (int) $row['secondes_restantes'];

            return $row;
        }
    }
