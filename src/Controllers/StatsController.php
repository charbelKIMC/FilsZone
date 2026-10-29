<?php
    declare(strict_types=1);
    namespace App\Controllers;
    use App\Core\Request;
    use App\Core\Response;
    use App\Services\StatsService;

    class StatsController {
        private StatsService $stats;

        public function __construct() {
            $this->stats = new StatsService();
        }

        public function index(Request $request): void {
            Response::json(['data' => $this->stats->overview()]);
        }
    }