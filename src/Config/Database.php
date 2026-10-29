<?php
    declare(strict_types=1);
    
    namespace App\Config;
    use App\Core\Env;
    use PDO;
    use Throwable;

    class Database {
        private static ?PDO $pdo = null;
        public static function connexion(): PDO {
            if (self::$pdo == null) {
                $connect = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', 
                    Env::get('DB_HOST'), Env::get('DB_PORT'), Env::get('DB_NAME')
                );
                self::$pdo = new PDO( $connect, Env::get('DB_USER'), Env::get('DB_PASS'), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            }
            return self::$pdo;
        }

        // Exécute $fn dans une transaction : tout est validé, ou tout est annulé si une exception survient
        // (tous les repositories partagent cette connexion, ils sont donc tous dans la transaction)
        public static function transaction(callable $fn): mixed {
            $pdo = self::connexion();
            $pdo->beginTransaction();
            try {
                $result = $fn();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
?>