<?php
/**
 * ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
 * የዳታቤዝ ግንኙነት ቅንብር (Database Connection)
 */

declare(strict_types=1);

// የኢትዮጵያ ሰዓት አቆጣጠር (Default Timezone)
date_default_timezone_set('Africa/Addis_Ababa');

// የዳታቤዝ መረጃዎች
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'teklu_business_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// የሲስተም ቋሚዎች
define('APP_NAME_EN', 'Teklu Food & Business Management');
define('APP_NAME_AM', 'ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት');
define('CURRENCY_CODE', 'ETB');

function get_db_connection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error'   => 'የዳታቤዝ ግንኙነት አልተሳካም (Database Connection Failed): ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $pdo;
}
