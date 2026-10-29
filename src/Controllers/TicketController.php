<?php
    declare(strict_types=1);
    namespace App\Controllers;
    use App\Core\Request;
    use App\Core\Response;
    use App\Services\TicketService;

    class TicketController {
        private TicketService $tickets;

        public function __construct() {
            $this->tickets = new TicketService();
        }

        // POST /admin/tickets/generer   (?format=csv pour télécharger un fichier à imprimer)
        public function generate(Request $request): void {
            $tickets = $this->tickets->generate(
                (int) $request->input('id_forfait', 0),
                (int) $request->input('quantite', 0),
                (int) $_SESSION['admin_id']
            );

            if ($request->query('format') === 'csv') {
                $rows = [['identifiant', 'mot_de_passe', 'forfait']];
                foreach ($tickets as $ticket) {
                    $rows[] = [$ticket['identifiant'], $ticket['mot_de_passe'], $ticket['forfait']];
                }
                Response::csv('tickets.csv', $rows);
            }

            Response::json(['data' => $tickets], 201);
        }

        // GET /admin/tickets?statut=actif&q=AB12&page=1&per_page=20
        public function index(Request $request): void {
            $statut = $request->query('statut');
            $statut = is_string($statut) && $statut !== '' ? $statut : null;
            $recherche = $request->query('q');
            $recherche = is_string($recherche) && trim($recherche) !== '' ? trim($recherche) : null;

            Response::json($this->tickets->list($statut, $recherche, $request->page(), $request->perPage()));
        }

        public function deactivate(Request $request, array $params): void {
            Response::json(['data' => $this->tickets->deactivate((int) $params['id'])]);
        }
    }
