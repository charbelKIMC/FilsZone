<?php
    declare(strict_types=1);

    use App\Config\Database;
    use App\Controllers\AuthController;
    use App\Controllers\ClientController;
    use App\Controllers\ForfaitController;
    use App\Controllers\SessionController;
    use App\Controllers\StatsController;
    use App\Controllers\TicketController;
    use App\Core\Env;
    use App\Core\HttpException;
    use App\Core\Request;
    use App\Core\Response;
    use App\Core\Router;
    use App\Middleware\AdminAuth;
    use App\Middleware\UserAuth;

    define('BASE_PATH', dirname(__DIR__));

    // Chargement automatique des classes : App\Core\Router => src/Core/Router.php
    spl_autoload_register(function (string $class): void {
        $prefix = 'App\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = BASE_PATH . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    Env::load(BASE_PATH . '/.env');

    // Toute exception devient une réponse JSON : HttpException = erreur prévue, le reste = erreur 500
    set_exception_handler(function (Throwable $e): void {
        if ($e instanceof HttpException) {
            Response::json(['error' => $e->getMessage()], $e->status());
        }
        $debug = Env::get('APP_DEBUG', 'false') === 'true';
        Response::json(['error' => $debug ? $e->getMessage() : 'Erreur interne du serveur'], 500);
    });

    // CORS : autorise un front-end hébergé ailleurs à appeler l'API
    // (avec les sessions/cookies, CORS_ORIGIN doit être l'adresse exacte du front, pas "*")
    $origin = Env::get('CORS_ORIGIN', '*');
    header('Access-Control-Allow-Origin: ' . $origin);
    if ($origin !== '*') {
        header('Access-Control-Allow-Credentials: true');
    }
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204); // requête "preflight" du navigateur, pas de contenu
        exit;
    }

    $router = new Router();

    $admin = [AdminAuth::class, 'handle'];
    $user = [UserAuth::class, 'handle'];

    $auth = new AuthController();
    $forfaits = new ForfaitController();
    $tickets = new TicketController();
    $sessions = new SessionController();
    $stats = new StatsController();
    $client = new ClientController();

    // --- Tests du socle
    $router->get('/ping', function () {
        Response::json(['status' => 'ok']);
    });
    // /db-test n'existe qu'en mode debug : en production, il ne doit rien révéler de la base
    if (Env::get('APP_DEBUG', 'false') === 'true') {
        $router->get('/db-test', function () {
            $total = Database::connexion()->query('SELECT COUNT(*) FROM forfait')->fetchColumn();
            Response::json(['forfaits' => (int) $total]);
        });
    }

    // --- Administrateur
    $router->post('/admin/login', [$auth, 'loginAdmin']);
    $router->post('/admin/logout', [$auth, 'logoutAdmin'], $admin);
    $router->get('/admin/me', [$auth, 'meAdmin'], $admin);

    $router->get('/admin/forfaits', [$forfaits, 'index'], $admin);
    $router->post('/admin/forfaits', [$forfaits, 'store'], $admin);
    $router->patch('/admin/forfaits/{id}', [$forfaits, 'update'], $admin);
    $router->delete('/admin/forfaits/{id}', [$forfaits, 'destroy'], $admin);

    $router->post('/admin/tickets/generer', [$tickets, 'generate'], $admin);
    $router->get('/admin/tickets', [$tickets, 'index'], $admin);
    $router->patch('/admin/tickets/{id}/desactiver', [$tickets, 'deactivate'], $admin);

    $router->get('/admin/sessions', [$sessions, 'index'], $admin);
    $router->patch('/admin/sessions/{id}/fermer', [$sessions, 'close'], $admin);
    $router->get('/admin/stats', [$stats, 'index'], $admin);

    // --- Utilisateur (client du Wi-Fi)
    $router->get('/forfaits', [$forfaits, 'publicIndex']);
    $router->post('/login', [$client, 'login']);
    $router->get('/me', [$client, 'dashboard'], $user);
    $router->post('/usage', [$client, 'usage'], $user);
    $router->post('/logout', [$client, 'logout'], $user);

    $router->dispatch(new Request());
