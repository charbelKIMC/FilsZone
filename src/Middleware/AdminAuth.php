<?php
    declare(strict_types=1);
    namespace App\Middleware;

    use App\Core\Request;
    use App\Core\Response;
    use App\Core\Session;
    use App\Repositories\AdminRepository;
    class AdminAuth {

        public static function handle(Request $request): void {
            Session::start();

            // L'admin doit toujours exister en base (un compte supprimé perd l'accès immédiatement)
            if (isset($_SESSION['admin_id'])
                && (new AdminRepository())->findById((int) $_SESSION['admin_id']) !== null) {
                return;
            }

            unset($_SESSION['admin_id']);
            Response::json(['error' => 'Authentification requise'], 401);
        }
    }
