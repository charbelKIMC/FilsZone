<?php
    declare(strict_types=1);
    namespace App\Repositories;
    use App\Config\Database;
    use PDO;

    class AdminRepository{
        private PDO $db;

        public function __construct(){
            $this->db = Database::connexion();
        }

        public function findByLogin(string $login): ?array {
            $repository = $this->db->prepare('SELECT id_admin, login, mot_de_passe FROM administrateur WHERE login = :login ');
            $repository->execute(['login' => $login]);
            $admin = $repository->fetch();

            return $admin ?: null;
        }

        public function findById(int $id): ?array {
            $stmt = $this->db->prepare(
                'SELECT id_admin, login FROM administrateur WHERE id_admin = :id'
            );
            $stmt->execute(['id' => $id]);
            $admin = $stmt->fetch();

            return $admin ?: null;
        }
    }
