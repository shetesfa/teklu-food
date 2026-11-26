<?php
/**
 * ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
 * የዳታቤዝ ግንኙነት ቅንብር (Database Connection)
 *
 * Credentials come from environment variables first (Render / TiDB
 * Cloud hosting), falling back to the local XAMPP defaults so local
 * use still works unchanged. No real password is stored in this file.
 *   DB_HOST    TiDB host, e.g. gateway01.ap-northeast-1.prod.aws.tidbcloud.com
 *              (a "host:port" value is also accepted — the port is split out)
 *   DB_PORT    default 3306; TiDB Serverless uses 4000
 *   DB_NAME    default teklu_business_db
 *   DB_USER    default root (local only)
 *   DB_PASS    default '' (local only)
 *   DB_SSL     '1' forces TLS; TLS is also automatic for *.tidbcloud.com
 *   DB_SSL_CA  optional path to a CA bundle for TLS
 */

declare(strict_types=1);

// የኢትዮጵያ ሰዓት አቆጣጠር (Default Timezone)
date_default_timezone_set('Africa/Addis_Ababa');

// የዳታቤዝ መረጃዎች — environment first, local defaults as fallback.
$dbHostEnv = getenv('DB_HOST');
$dbHost = ($dbHostEnv === false || $dbHostEnv === '') ? '127.0.0.1' : $dbHostEnv;
$dbPortEnv = getenv('DB_PORT');
$dbPort = ($dbPortEnv === false || $dbPortEnv === '') ? 3306 : (int) $dbPortEnv;
$dbNameEnv = getenv('DB_NAME');
$dbName = ($dbNameEnv === false || $dbNameEnv === '') ? 'teklu_business_db' : $dbNameEnv;
$dbUserEnv = getenv('DB_USER');
$dbUser = ($dbUserEnv === false || $dbUserEnv === '') ? 'root' : $dbUserEnv;
$dbPassEnv = getenv('DB_PASS');
$dbPass = ($dbPassEnv === false) ? '' : $dbPassEnv;
$dbCharsetEnv = getenv('DB_CHARSET');
$dbCharset = ($dbCharsetEnv === false || $dbCharsetEnv === '') ? 'utf8mb4' : $dbCharsetEnv;

// "host:port" in DB_HOST (e.g. from a Render dashboard value) — split it.
if (str_contains($dbHost, ':')) {
    [$dbHostOnly, $dbPortPart] = explode(':', $dbHost, 2);
    $dbHost = trim($dbHostOnly);
    $dbPort = (int) trim($dbPortPart) ?: $dbPort;
}

define('DB_HOST', $dbHost);
define('DB_PORT', $dbPort);
define('DB_NAME', $dbName);
define('DB_USER', $dbUser);
define('DB_PASS', $dbPass);
define('DB_CHARSET', $dbCharset);

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

        // TiDB Cloud Serverless (and any managed MySQL behind TLS)
        // refuses plain connections, so connect over TLS. A CA bundle
        // turns SSL on; server-cert verification is disabled so a
        // private/unknown CA cannot break the connection — the traffic
        // is still encrypted. Plain local MySQL is untouched.
        $needsTls = str_contains(DB_HOST, 'tidbcloud.com') || getenv('DB_SSL') === '1';
        if ($needsTls) {
            $caFile = getenv('DB_SSL_CA');
            if ($caFile === false || $caFile === '') {
                $caFile = '/etc/ssl/certs/ca-certificates.crt';
            }
            if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
                if (is_string($caFile) && file_exists($caFile)) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = $caFile;
                } elseif (file_exists(__DIR__ . '/cacert.pem')) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = __DIR__ . '/cacert.pem';
                }
            }
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

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
