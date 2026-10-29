<?php
    declare(strict_types=1);
    namespace App\Core;

    class Session {
        // Démarre la session PHP une seule fois par requête
        public static function start(): void {
            if (session_status() === PHP_SESSION_NONE) {
                // Front sur un autre domaine : SESSION_SAMESITE=None dans le .env (exige HTTPS)
                $sameSite = Env::get('SESSION_SAMESITE', 'Lax');
                $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

                session_set_cookie_params([
                    'httponly' => true,
                    'samesite' => $sameSite,
                    // les navigateurs refusent un cookie SameSite=None sans Secure
                    'secure'   => $https || strcasecmp($sameSite, 'None') === 0,
                ]);
                session_start();
            }
        }
    }
