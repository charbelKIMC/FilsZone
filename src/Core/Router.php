<?php
    declare(strict_types=1);

    namespace App\Core;

    class Router
    {
        private array $routes = [];

        public function get(string $path, callable $handler, ?callable $middleware = null): void {
            $this->add('GET', $path, $handler, $middleware);
        }

        public function post(string $path, callable $handler, ?callable $middleware = null): void {
            $this->add('POST', $path, $handler, $middleware);
        }

        public function patch(string $path, callable $handler, ?callable $middleware = null): void {
            $this->add('PATCH', $path, $handler, $middleware);
        }

        public function delete(string $path, callable $handler, ?callable $middleware = null): void {
            $this->add('DELETE', $path, $handler, $middleware);
        }

        private function add(string $method, string $path, callable $handler, ?callable $middleware): void {
            $this->routes[] = [ 'method' => $method, 'path' => $path, 'handler' => $handler,'middleware' => $middleware,];
        }

        public function dispatch(Request $request): void {
            $allowed = [];
            foreach ($this->routes as $route) {
                // /admin/tickets/{id}/desactiver  =>  regex avec un groupe nommé "id"
                $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $route['path']);
                if (preg_match('#^' . $pattern . '$#', $request->path, $matches)) {
                    // Bonne route, mauvais verbe : on le retient pour répondre 405 plutôt que 404
                    if ($route['method'] !== $request->method) {
                        $allowed[] = $route['method'];
                        continue;
                    }
                    // On garde uniquement les groupes nommés (les paramètres d'URL)
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                    if ($route['middleware'] !== null) {
                        ($route['middleware'])($request);
                    }

                    ($route['handler'])($request, $params);
                    return;
                }
            }
            if ($allowed !== []) {
                header('Allow: ' . implode(', ', array_unique($allowed)));
                Response::json(['error' => 'Méthode non autorisée'], 405);
            }
            Response::json(['error' => 'Route introuvable'], 404);
        }
    }
