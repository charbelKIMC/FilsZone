<?php
    declare(strict_types=1);
    namespace App\Core;
    
    class Response {
        public static function json(mixed $data, int $status = 200): never {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        // Export CSV 
        public static function csv(string $filename, array $rows): never {
            http_response_code(200);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'csvCell'], $row), ';', '"', '\\');
            }
            fclose($out);
            exit;
        }

        // Une cellule commençant par = + - @ serait exécutée comme formule par Excel : on la neutralise
        private static function csvCell(mixed $value): mixed {
            if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'" . $value;
            }
            return $value;
        }
    }
