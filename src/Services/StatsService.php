<?php
    declare(strict_types=1);
    namespace App\Services;
    use App\Repositories\StatsRepository;

    class StatsService
    {
        private StatsRepository $stats;
        private TicketService $ticketService;

        public function __construct()
        {
            $this->stats = new StatsRepository();
            $this->ticketService = new TicketService();
        }

        public function overview(): array
        {
            $this->ticketService->refreshStatuses();

            $utilises = $this->stats->ticketsUtilises();

            return [
                'tickets_par_statut'       => $this->stats->ticketsParStatut(),
                'sessions_en_cours'        => $this->stats->sessionsEnCours(),
                'volume_total_consomme_go' => $this->stats->volumeTotalConsomme(),
                'tickets_utilises'         => $utilises['utilises'],
                'revenu_estime'            => $utilises['revenu'],
                'par_forfait'              => $this->stats->parForfait(),
                'ventes_7_jours'           => $this->stats->ventesParJour(7),
            ];
        }
    }
