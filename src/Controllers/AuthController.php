<?php
    declare(strict_types=1);
    namespace App\Controllers;
    use App\Core\Request;
    use App\Core\Response;
    use App\Core\Session;
    use App\Services\AuthService;

    class AuthController{
        private AuthService $auth;
        public function __construct() {
            $this->auth = new AuthService();
        }

        public function loginAdmin(Request $request): void {
            $login = trim((string) $request->input('login', ''));
            $password = (string) $request->input('password', '');

            if($login === '' || $password === '') {
                Response::json(['error' => 'Identifiant et mot de passe réquis'], 422);
            }
            $admin = $this->auth->loginAdmin($login, $password, $request->ip());

            if($admin === null) {
                Response::json(['error' => 'Identifiants incorrects'], 401);
            }
            Session::start();
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $admin['id_admin'];

            Response::json(['message' => 'Votre connexion est un succes', 'admin' => $admin]);
        }

        public function logoutAdmin(Request $request): void {
            unset($_SESSION['admin_id']);
            Response::json(['message' => 'Déconnecté']);
        }

        public function meAdmin(Request $request): void {
            $admin = $this->auth->admin((int) $_SESSION['admin_id']);

            if ($admin === null) {
                unset($_SESSION['admin_id']);
                Response::json(['error' => 'Authentification requise'], 401);
            }
            Response::json(['admin' => $admin]);
        }

    }
