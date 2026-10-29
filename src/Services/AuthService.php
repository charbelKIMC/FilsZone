<?php
    declare(strict_types=1);
    namespace App\Services;
    use App\Repositories\AdminRepository;

    class AuthService {
        private const MAX_ECHECS = 5;

        private AdminRepository $admins;
        private TentativeService $tentatives;

        public function __construct() {
            $this->admins = new AdminRepository();
            $this->tentatives = new TentativeService();
        }

        // Lance une HttpException 429 si l'IP a trop d'échecs récents
        public function loginAdmin(string $login, string $password, string $ip): ?array {
            $cle = 'admin:' . $ip;
            $this->tentatives->assertNotBlocked($cle, self::MAX_ECHECS);

            $admin = $this->admins->findByLogin($login);
            if($admin === null || !password_verify($password, $admin['mot_de_passe'])){
                $this->tentatives->fail($cle);
                return null;
            }
            $this->tentatives->reset($cle);
            unset($admin['mot_de_passe']);
            return $admin;
        }

        public function admin(int $id): ?array {
            return $this->admins->findById($id);
        }
    }
