<?php
    declare(strict_types=1);
    namespace App\Controllers;
    use App\Core\Request;
    use App\Core\Response;
    use App\Services\SessionService;

    class SessionController {
        private SessionService $sessions;

        public function __construct() {
            $this->sessions = new SessionService();
        }

        // GET /admin/sessions?en_cours=1
        public function index(Request $request): void {
            $enCours = $request->query('en_cours') === '1';
            Response::json($this->sessions->list($enCours, $request->page(), $request->perPage()));
        }

        public function close(Request $request, array $params): void {
            Response::json(['data' => $this->sessions->close((int) $params['id'])]);
        }
    }
