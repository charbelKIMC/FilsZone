<?php
    declare(strict_types=1);
    namespace App\Core;

    use RuntimeException;

    class HttpException extends RuntimeException {
        private int $status;

        public function __construct(string $message, int $status = 400) {
            parent::__construct($message);
            $this->status = $status;
        }

        public function status(): int {
            return $this->status;
        }
    }
