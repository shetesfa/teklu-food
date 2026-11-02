<?php
/**
 * የኦዲት መዝገብ ሄልፐር (Audit Log Helper)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function log_audit(
    ?int $user_id,
    string $action,
    string $entity_type,
    string $entity_id,
    ?array $old_values = null,
    ?array $new_values = null
): void {
    try {
        $pdo = get_db_connection();
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (uuid, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, created_at)
            VALUES (:uuid, :user_id, :action, :entity_type, :entity_id, :old_values, :new_values, :ip_address, NOW())
        ");
        
        $uuid = bin2hex(random_bytes(16));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt->execute([
            ':uuid'        => $uuid,
            ':user_id'     => $user_id,
            ':action'      => $action,
            ':entity_type' => $entity_type,
            ':entity_id'   => $entity_id,
            ':old_values'  => $old_values !== null ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null,
            ':new_values'  => $new_values !== null ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null,
            ':ip_address'  => $ip,
        ]);
    } catch (Exception $e) {
        // የኦዲት ስህተት ዋናውን ግብይት እንዳያቋርጠው በ error_log እንይዛለን
        error_log("Audit Log Failure: " . $e->getMessage());
    }
}
