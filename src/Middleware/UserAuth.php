<?php
    declare(strict_types=1);
    namespace App\Middleware;
    use App\Core\Request;
    use App\Core\Response;
    use App\Core\Session;
    use App\Repositories\SessionRepository;
    use App\Services\TicketService;

    class UserAuth {
        public static function handle(Request $request): void {
            Session::start();
            if (isset($_SESSION['ticket_id'], $_SESSION['session_id'])) {
                // Ferme d'abord les sessions expirées, épuisées ou inactives depuis trop longtemps
                (new TicketService())->refreshStatuses();

                $sessions = new SessionRepository();
                $session = $sessions->findById((int) $_SESSION['session_id']);

                // La session PHP n'est valable que si la session Wi-Fi est encore ouverte en base
                if (
                    $session !== null
                    && $session['date_fin'] === null
                    && $session['id_ticket'] === (int) $_SESSION['ticket_id']
                ) {
                    $sessions->touch($session['id_session']);
                    return;
                }
            }

            unset($_SESSION['ticket_id'], $_SESSION['session_id']);
            Response::json(['error' => 'Connexion requise ou session terminée'], 401);
        }
    }
