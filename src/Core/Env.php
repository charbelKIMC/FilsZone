<?php
    declare(strict_types=1);
    namespace App\Core;

    class Env {
        private static array $vars = [];

        public static function load(string $file): void {
            if (!is_file($file)) {
                return; 
            } foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                // limite 2 : une valeur peut elle-même contenir un "="
                [$key, $value] = explode('=', $line, 2);
                $value = trim($value);
                // DB_PASS="abc" ou 'abc' => abc
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                    $value = substr($value, 1, -1);
                }
                self::$vars[trim($key)] = $value;
            }
        }

        public static function get(string $key, ?string $default = null): ?string {
            return self::$vars[$key] ?? $default;
        }
    }
