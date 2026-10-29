<?php
    declare(strict_types=1);

    namespace App\Services;

    use App\Core\HttpException;
    use App\Repositories\ForfaitRepository;
    use PDOException;

    class ForfaitService
    {
        private const FIELDS = ['nom', 'prix', 'volume_go', 'duree_validite'];
        private const PRIX_MAX = 99999999.99;
        private const VOLUME_MAX = 999999.99;
        private const DUREE_MAX = 87600; // 10 ans en heures

        private ForfaitRepository $forfaits;

        public function __construct()
        {
            $this->forfaits = new ForfaitRepository();
        }

        public function list(): array
        {
            return $this->forfaits->all();
        }

        // Liste publique (page client) servie depuis un fichier JSON : la base n'est interrogée
        // que lorsque le cache n'existe pas, c'est-à-dire après un ajout, une modification ou une suppression
        public function publicList(): array
        {
            $file = self::cacheFile();
            if (is_file($file)) {
                $cached = json_decode((string) file_get_contents($file), true);
                if (is_array($cached)) {
                    return $cached;
                }
            }

            $list = array_map(
                fn (array $f): array => array_diff_key($f, ['nb_tickets' => true]),
                $this->forfaits->all()
            );

            // Écriture dans un fichier temporaire puis renommage : un lecteur ne voit jamais un fichier à moitié écrit
            if (is_dir(dirname($file)) || mkdir(dirname($file), 0775, true)) {
                $tmp = $file . '.' . uniqid('', true) . '.tmp';
                if (file_put_contents($tmp, json_encode($list, JSON_UNESCAPED_UNICODE)) !== false) {
                    rename($tmp, $file);
                }
            }

            return $list;
        }

        private static function cacheFile(): string
        {
            return BASE_PATH . '/storage/cache/forfaits.json';
        }

        private function clearCache(): void
        {
            if (is_file(self::cacheFile())) {
                unlink(self::cacheFile());
            }
        }

        public function create(array $input, int $adminId): array
        {
            $data = $this->validate(array_merge(
                ['volume_go' => null, 'duree_validite' => null],
                array_intersect_key($input, array_flip(self::FIELDS))
            ));
            $data['id_admin'] = $adminId;

            try {
                $id = $this->forfaits->create($data);
            } catch (PDOException $e) {
                $this->rethrow($e);
            }
            $this->clearCache();

            return $this->forfaits->find($id);
        }

        // PATCH : on part du forfait existant et on remplace seulement les champs envoyés
        public function update(int $id, array $input): array
        {
            $existing = $this->forfaits->find($id) ?? throw new HttpException('Forfait introuvable', 404);

            $data = $this->validate(array_merge(
                $existing,
                array_intersect_key($input, array_flip(self::FIELDS))
            ));

            // Les tickets déjà générés ont été vendus avec ces limites : on ne les change plus
            $limitesModifiees = $data['volume_go'] !== $existing['volume_go']
                || $data['duree_validite'] !== $existing['duree_validite'];
            if ($limitesModifiees && $this->forfaits->countTickets($id) > 0) {
                throw new HttpException(
                    'Ce forfait a déjà des tickets : son volume et sa durée ne peuvent plus être modifiés (créez un nouveau forfait)',
                    409
                );
            }

            try {
                $this->forfaits->update($id, $data);
            } catch (PDOException $e) {
                $this->rethrow($e);
            }
            $this->clearCache();

            return $this->forfaits->find($id);
        }

        public function delete(int $id): void
        {
            if ($this->forfaits->find($id) === null) {
                throw new HttpException('Forfait introuvable', 404);
            }
            if ($this->forfaits->countTickets($id) > 0) {
                throw new HttpException('Ce forfait a déjà des tickets, il ne peut pas être supprimé', 409);
            }
            $this->forfaits->delete($id);
            $this->clearCache();
        }

        private function validate(array $in): array
        {
            $nom = trim((string) ($in['nom'] ?? ''));
            if ($nom === '' || mb_strlen($nom) > 50) {
                throw new HttpException('Nom requis (50 caractères maximum)', 422);
            }

            // Les maximums suivent les types SQL (DECIMAL(10,2), DECIMAL(8,2)) : au-delà, MySQL refuserait
            if (!isset($in['prix']) || !is_numeric($in['prix'])
                || (float) $in['prix'] < 0 || (float) $in['prix'] > self::PRIX_MAX) {
                throw new HttpException('Prix invalide', 422);
            }

            $volume = $in['volume_go'] ?? null;
            if ($volume !== null && (!is_numeric($volume) || (float) $volume <= 0 || (float) $volume > self::VOLUME_MAX)) {
                throw new HttpException('Volume invalide (nombre supérieur à 0 ou vide)', 422);
            }

            $duree = $in['duree_validite'] ?? null;
            if ($duree !== null) {
                $duree = filter_var($duree, FILTER_VALIDATE_INT);
                if ($duree === false || $duree < 1 || $duree > self::DUREE_MAX) {
                    throw new HttpException('Durée invalide (heures, entier supérieur à 0 ou vide)', 422);
                }
            }

            if ($volume === null && $duree === null) {
                throw new HttpException('Un forfait doit avoir un volume ou une durée', 422);
            }

            return [
                'nom'            => $nom,
                'prix'           => (float) $in['prix'],
                'volume_go'      => $volume === null ? null : (float) $volume,
                'duree_validite' => $duree,
            ];
        }

        // 23000 = violation de contrainte (ici : nom déjà utilisé)
        private function rethrow(PDOException $e): never
        {
            if ($e->getCode() === '23000') {
                throw new HttpException('Un forfait porte déjà ce nom', 409);
            }
            throw $e;
        }
    }
