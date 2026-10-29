<?php
    declare(strict_types=1);
    namespace App\Controllers;

    use App\Core\Request;
    use App\Core\Response;
    use App\Core\Session;
    use App\Services\ClientService;

    class ClientController {
        private ClientService $client;

        public function __construct() {
            $this->client = new ClientService();
        }

        // POST /login   { identifiant, password, mac }
        // Sur un vrai hotspot, la MAC est fournie par le routeur ; pour tester, envoyer une MAC fictive.
        public function login(Request $request): void {
            $identifiant = strtoupper(trim((string) $request->input('identifiant', '')));
            $password = strtoupper(trim((string) $request->input('password', '')));
            $mac = (string) $request->input('mac', '');

            if ($identifiant === '' || $password === '' || $mac === '') {
                Response::json(['error' => 'Identifiant, mot de passe et adresse MAC requis'], 422);
            }
            $ids = $this->client->login($identifiant, $password, $mac, $request->ip());

            Session::start();
            session_regenerate_id(true);
            $_SESSION['ticket_id'] = $ids['ticket_id'];
            $_SESSION['session_id'] = $ids['session_id'];

            Response::json([
                'message' => 'Connexion réussie',
                'data'    => $this->client->dashboard($ids['ticket_id'], $ids['session_id']),
            ]);
        }

        public function dashboard(Request $request): void {
            Response::json([
                'data' => $this->client->dashboard((int) $_SESSION['ticket_id'], (int) $_SESSION['session_id']),
            ]);
        }

        //
        public function usage(Request $request): void {
            $go = (float) $request->input('go', 0);
            Response::json([
                'data' => $this->client->usage((int) $_SESSION['ticket_id'], (int) $_SESSION['session_id'], $go),
            ]);
        }

        public function logout(Request $request): void {
            $this->client->logout((int) $_SESSION['session_id']);
            unset($_SESSION['ticket_id'], $_SESSION['session_id']);
            Response::json(['message' => 'Déconnecté']);
        }
    }
