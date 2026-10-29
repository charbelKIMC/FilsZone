<?php
    declare(strict_types=1);
    namespace App\Services;
    use App\Core\HttpException;
    use App\Repositories\SessionRepository;
    use App\Repositories\TicketRepository;

    class ClientService {
        private const USAGE_MAX_GO = 1000;
        private const MAX_ECHECS = 10;

        private TicketRepository $tickets;
        private SessionRepository $sessions;
        private TicketService $ticketService;
        private TentativeService $tentatives;

        public function __construct() {
            $this->tickets = new TicketRepository();
            $this->sessions = new SessionRepository();
            $this->ticketService = new TicketService();
            $this->tentatives = new TentativeService();
        }

        public function login(string $identifiant, string $password, string $mac, string $ip): array {
            $mac = strtoupper(str_replace('-', ':', trim($mac)));
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                throw new HttpException('Adresse MAC invalide', 422);
            }

            $cle = 'client:' . $ip;
            $this->tentatives->assertNotBlocked($cle, self::MAX_ECHECS);

            $this->ticketService->refreshStatuses();

            $ticket = $this->tickets->findByIdentifiant($identifiant);
            if ($ticket === null || !password_verify($password, $ticket['mot_de_passe'])) {
                $this->tentatives->fail($cle);
                throw new HttpException('Identifiant ou mot de passe incorrect', 401);
            }
            $this->tentatives->reset($cle);

            $this->ticketService->assertUsable($ticket);

            // Un seul appareil à la fois par ticket
            $open = $this->sessions->findOpenByTicket($ticket['id_ticket']);
            if ($open !== null) {
                if ($open['adresse_mac'] !== $mac) {
                    throw new HttpException('Ce ticket est déjà utilisé sur un autre appareil', 409);
                }
                $this->sessions->close($open['id_session']);
            }

            // Première connexion : démarre le décompte de la durée
            if ($ticket['date_premiere_connexion'] === null) {
                $this->tickets->startFirstUse($ticket['id_ticket'], $ticket['duree_validite']);
            }

            $sessionId = $this->sessions->open($ticket['id_ticket'], $mac, $ip);

            return ['ticket_id' => $ticket['id_ticket'], 'session_id' => $sessionId];
        }

        public function dashboard(int $ticketId, int $sessionId): array {
            $ticket = $this->usableTicket($ticketId, $sessionId);
            $session = $this->sessions->findById($sessionId) ?? throw new HttpException('Session introuvable', 404);

            $totaux = $this->sessions->totalsByTicket($ticketId);

            return [
                'identifiant'             => $ticket['identifiant'],
                'forfait'                 => $ticket['forfait'],
                'statut'                  => $ticket['statut'],
                'prix'                    => $ticket['prix_vente'],
                'volume_total_go'         => $ticket['volume_total'],
                'volume_restant_go'       => $ticket['volume_restant'],
                'volume_consomme_go'      => $totaux['volume_utilise'],
                'duree_totale_secondes'   => $ticket['duree_validite'] === null ? null : $ticket['duree_validite'] * 3600,
                'temps_restant_secondes'  => $ticket['secondes_restantes'],
                'date_premiere_connexion' => $ticket['date_premiere_connexion'],
                'date_expiration'         => $ticket['date_expiration'],
                'nb_connexions'           => $totaux['nb_sessions'],
                'session_debut'           => $session['date_debut'],
                'session_duree_secondes'  => $session['duree_secondes'],
                'session_volume_go'       => $session['volume_utilise'],
                'adresse_mac'             => $session['adresse_mac'],
            ];
        }

        // Enregistre une consommation (en Go). Sert au prototype ; en production, ces chiffres
        // viendront de la comptabilité RADIUS (accounting).
        public function usage(int $ticketId, int $sessionId, float $go): array {
            // Borne haute : évite qu'une valeur énorme (ou INF) fasse échouer la requête SQL
            if ($go <= 0 || $go > self::USAGE_MAX_GO) {
                throw new HttpException('Volume invalide (entre 0 et ' . self::USAGE_MAX_GO . ' Go)', 422);
            }

            $ticket = $this->usableTicket($ticketId, $sessionId);

            if ($ticket['volume_restant'] !== null) {
                $go = min($go, $ticket['volume_restant']);
            }
            $this->sessions->addUsage($sessionId, $go);

            if ($ticket['volume_restant'] !== null) {
                $this->tickets->consumeVolume($ticketId, $go);
                $ticket = $this->tickets->findById($ticketId);

                if ($ticket['volume_restant'] <= 0) {
                    $this->tickets->setStatut($ticketId, 'epuise');
                    $this->sessions->close($sessionId);

                    return [
                        'volume_restant_go' => 0.0,
                        'session_terminee'  => true,
                        'message'           => 'Volume épuisé, vous êtes déconnecté',
                    ];
                }
            }

            return $this->dashboard($ticketId, $sessionId) + ['session_terminee' => false];
        }

        // Déconnexion : on ferme la session, le volume restant est conservé
        public function logout(int $sessionId): void {
            $this->sessions->close($sessionId);
        }

        private function usableTicket(int $ticketId, int $sessionId): array {
            $this->ticketService->refreshStatuses();

            $ticket = $this->tickets->findById($ticketId) ?? throw new HttpException('Ticket introuvable', 404);

            try {
                $this->ticketService->assertUsable($ticket);
            } catch (HttpException $e) {
                $this->sessions->close($sessionId);
                throw $e;
            }

            return $ticket;
        }
    }
