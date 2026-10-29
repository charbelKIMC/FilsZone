<?php
    declare(strict_types=1);

    namespace App\Services;

    use App\Core\Env;
    use App\Core\HttpException;
    use App\Repositories\ForfaitRepository;
    use App\Repositories\SessionRepository;
    use App\Repositories\TicketRepository;

    class TicketService {
        private const STATUTS = ['neuf', 'actif', 'epuise', 'expire', 'desactive'];
        // Sans I, O, 0, 1 pour éviter les confusions à la lecture d'un ticket imprimé
        private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        private const MAX_PAR_LOT = 100;
        private TicketRepository $tickets;
        private ForfaitRepository $forfaits;
        private SessionRepository $sessions;

        public function __construct() {
            $this->tickets = new TicketRepository();
            $this->forfaits = new ForfaitRepository();
            $this->sessions = new SessionRepository();
        }

        // Retourne les identifiants et mots de passe EN CLAIR : c'est la seule fois où ils existent
        // (en base, seul le hash est conservé)
        public function generate(int $forfaitId, int $quantite, int $adminId): array
        {
            $forfait = $this->forfaits->find($forfaitId) ?? throw new HttpException('Forfait introuvable', 404);

            if ($quantite < 1 || $quantite > self::MAX_PAR_LOT) {
                throw new HttpException('La quantité doit être comprise entre 1 et ' . self::MAX_PAR_LOT, 422);
            }

            $rows = [];
            $clear = [];
            $used = [];

            while (count($rows) < $quantite) {
                $identifiant = $this->random(8);
                if (isset($used[$identifiant]) || $this->tickets->identifiantExists($identifiant)) {
                    continue;
                }
                $used[$identifiant] = true;

                $password = $this->random(6);
                $rows[] = [
                    'identifiant'    => $identifiant,
                    'mot_de_passe'   => password_hash($password, PASSWORD_DEFAULT),
                    'volume_restant' => $forfait['volume_go'],
                    'prix_vente'     => $forfait['prix'],
                    'id_forfait'     => $forfaitId,
                    'id_admin'       => $adminId,
                ];
                $clear[] = [
                    'identifiant'  => $identifiant,
                    'mot_de_passe' => $password,
                    'forfait'      => $forfait['nom'],
                ];
            }

            $this->tickets->createMany($rows);

            return $clear;
        }

        public function list(?string $statut, ?string $recherche, int $page, int $perPage): array
        {
            if ($statut !== null && !in_array($statut, self::STATUTS, true)) {
                throw new HttpException('Statut invalide', 422);
            }
            if ($recherche !== null) {
                $recherche = strtoupper($recherche);
                if (!preg_match('/^[A-Z0-9]{1,20}$/', $recherche)) {
                    throw new HttpException('Recherche invalide (lettres et chiffres uniquement)', 422);
                }
            }

            $this->refreshStatuses();

            return [
                'data'     => $this->tickets->list($statut, $recherche, $perPage, ($page - 1) * $perPage),
                'total'    => $this->tickets->count($statut, $recherche),
                'page'     => $page,
                'per_page' => $perPage,
            ];
        }

        public function deactivate(int $id): array
        {
            if ($this->tickets->findById($id) === null) {
                throw new HttpException('Ticket introuvable', 404);
            }

            $this->tickets->setStatut($id, 'desactive');
            $this->sessions->closeOrphans();

            return ['id_ticket' => $id, 'statut' => 'desactive'];
        }

        // Met à jour les statuts (expire / epuise) et ferme les sessions devenues invalides ou inactives
        // (sans la fermeture des inactives, un appareil parti sans se déconnecter bloquerait le ticket)
        public function refreshStatuses(): void
        {
            $this->tickets->refreshStatuses();
            $this->sessions->closeOrphans();
            $this->sessions->closeInactive(max(1, (int) Env::get('SESSION_INACTIVITE_MINUTES', '15')));
        }

        // Lance une exception si le ticket ne peut plus servir (règle : temps ET volume doivent rester)
        public function assertUsable(array $ticket): void
        {
            if ($ticket['statut'] === 'desactive') {
                throw new HttpException('Ticket désactivé', 403);
            }
            if ($ticket['statut'] === 'expire') {
                throw new HttpException('Ticket expiré', 403);
            }
            if ($ticket['statut'] === 'epuise') {
                throw new HttpException('Volume épuisé', 403);
            }
            if ($ticket['secondes_restantes'] !== null && $ticket['secondes_restantes'] <= 0) {
                $this->tickets->setStatut($ticket['id_ticket'], 'expire');
                throw new HttpException('Ticket expiré', 403);
            }
            if ($ticket['volume_restant'] !== null && $ticket['volume_restant'] <= 0) {
                $this->tickets->setStatut($ticket['id_ticket'], 'epuise');
                throw new HttpException('Volume épuisé', 403);
            }
        }

        private function random(int $length): string
        {
            $max = strlen(self::ALPHABET) - 1;
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= self::ALPHABET[random_int(0, $max)];
            }

            return $out;
        }
    }
