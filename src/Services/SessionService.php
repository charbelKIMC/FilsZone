<?php
    declare(strict_types=1);

    namespace App\Services;

    use App\Core\HttpException;
    use App\Repositories\SessionRepository;

    class SessionService
    {
        private SessionRepository $sessions;
        private TicketService $ticketService;

        public function __construct()
        {
            $this->sessions = new SessionRepository();
            $this->ticketService = new TicketService();
        }

        public function list(bool $enCours, int $page, int $perPage): array
        {
            $this->ticketService->refreshStatuses();

            return [
                'data'     => $this->sessions->list($enCours, $perPage, ($page - 1) * $perPage),
                'total'    => $this->sessions->count($enCours),
                'page'     => $page,
                'per_page' => $perPage,
            ];
        }

        // L'admin coupe une connexion (le ticket reste utilisable, le client pourra se reconnecter)
        public function close(int $id): array
        {
            $session = $this->sessions->findById($id) ?? throw new HttpException('Connexion introuvable', 404);
            if ($session['date_fin'] !== null) {
                throw new HttpException('Cette connexion est déjà terminée', 409);
            }
            $this->sessions->close($id);

            return ['id_session' => $id, 'terminee' => true];
        }
    }
