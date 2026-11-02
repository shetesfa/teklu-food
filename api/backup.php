<?php
/**
 * የመረጃ ምትኬ እና ዳታ ወደ ውጭ መላኪያ API (Backup & Export API - Super Admin Only)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/audit.php';

$user = get_authenticated_user();
require_super_admin($user);

$pdo = get_db_connection();
$action = $_GET['action'] ?? 'export_json';

if ($action === 'export_json') {
    $tables = [
        'users', 'categories', 'stock_locations', 'products', 'recipes',
        'financial_accounts', 'contacts', 'stock_levels', 'stock_movements',
        'purchases', 'purchase_items', 'sales', 'sale_items', 'payments',
        'expenses', 'withdrawals', 'internal_transfers', 'farm_productions',
        'daily_closings', 'audit_logs'
    ];

    $backupData = [
        'app_name'    => APP_NAME_AM,
        'exported_at' => date('Y-m-d H:i:s'),
        'exported_by' => $user['username'],
        'tables'      => []
    ];

    foreach ($tables as $tbl) {
        $stmt = $pdo->query("SELECT * FROM `$tbl`");
        $backupData['tables'][$tbl] = $stmt->fetchAll();
    }

    log_audit($user['id'], 'BACKUP_EXPORT', 'system', 'all');

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="teklu_enterprise_backup_' . date('Ymd_His') . '.json"');
    echo json_encode($backupData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

send_error('ያልታወቀ ክንውን', 404);
