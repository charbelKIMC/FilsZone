<?php
    declare(strict_types=1);

    namespace App\Services;

    use App\Core\HttpException;
    use App\Repositories\TentativeRepository;

    // Anti force brute : au-delà de $max échecs en FENETRE_MINUTES, la clé (ex. "admin:<ip>") est bloquée
    class TentativeService
    {
        private const FENETRE_MINUTES = 15;

        private TentativeRepository $tentatives;

        public function __construct()
        {
            $this->tentatives = new TentativeRepository();
        }

        public function assertNotBlocked(string $cle, int $max): void
        {
            $this->tentatives->purge(self::FENETRE_MINUTES);

            if ($this->tentatives->countRecent($cle, self::FENETRE_MINUTES) >= $max) {
                throw new HttpException(
                    'Trop de tentatives échouées, réessayez dans ' . self::FENETRE_MINUTES . ' minutes',
                    429
                );
            }
        }

        public function fail(string $cle): void
        {
            $this->tentatives->add($cle);
        }

        public function reset(string $cle): void
        {
            $this->tentatives->clear($cle);
        }
    }
