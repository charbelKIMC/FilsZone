<?php
    declare(strict_types=1);
    namespace App\Controllers;
    use App\Core\Request;
    use App\Core\Response;
    use App\Services\ForfaitService;

    class ForfaitController {
        private ForfaitService $forfaits;

        public function __construct() {
            $this->forfaits = new ForfaitService();
        }

        public function index(Request $request): void {
            Response::json(['data' => $this->forfaits->list()]);
        }

        // GET /forfaits (public) : la page client affiche l'offre avant toute connexion
        public function publicIndex(Request $request): void {
            header('Cache-Control: public, max-age=60');
            Response::json(['data' => $this->forfaits->publicList()]);
        }

        public function store(Request $request): void {
            $forfait = $this->forfaits->create($request->all(), (int) $_SESSION['admin_id']);
            Response::json(['data' => $forfait], 201);
        }

        public function update(Request $request, array $params): void {
            $forfait = $this->forfaits->update((int) $params['id'], $request->all());
            Response::json(['data' => $forfait]);
        }

        public function destroy(Request $request, array $params): void {
            $this->forfaits->delete((int) $params['id']);
            Response::json(['message' => 'Forfait supprimé']);
        }
    }
