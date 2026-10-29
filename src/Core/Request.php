<?php
    declare(strict_types=1);
    namespace App\Core;

    class Request {
        public string $method;
        public string $path;
        public array $query = [];
        private array $body = [];

        public function __construct() {
            $this->method = $_SERVER['REQUEST_METHOD'];
            $this->query = $_GET;

            $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

            // Retire le chemin du dossier (ex. /wifi-zone/public) pour ne garder que la route (/ping)
            $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            if ($base !== '' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }
            $this->path = '/' . trim($uri, '/');

            // Un front-end moderne envoie du JSON dans le corps, pas $_POST
            $decoded = json_decode(file_get_contents('php://input') ?: '', true);
            if (is_array($decoded)) {
                $this->body = $decoded;
            }
        }

        public function input(string $key, mixed $default = null): mixed {
            return $this->body[$key] ?? $default;
        }

        public function all(): array {
            return $this->body;
        }

        public function query(string $key, mixed $default = null): mixed {
            return $this->query[$key] ?? $default;
        }

        // Adresse vue par le serveur. ⚠️ Derrière un proxy inverse ou un NAT, ce sera la même adresse pour
        // TOUS les clients (l'anti force brute bloquerait tout le monde) : voir LISMOI.md avant ce passage
        public function ip(): string {
            return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }

        // Pagination : ?page=2&per_page=20 (par_page limité à 100)
        public function page(): int {
            return max(1, (int) ($this->query['page'] ?? 1));
        }

        public function perPage(): int {
            return min(100, max(1, (int) ($this->query['per_page'] ?? 20)));
        }
    }
