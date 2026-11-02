<?php
/**
 * የባለቤት እና የተፈቀዱ መውጣቶች API (Withdrawals API)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/accounting.php';
require_once __DIR__ . '/../helpers/audit.php';

$user = get_authenticated_user();
$pdo = get_db_connection();
$action = $_GET['action'] ?? 'list';

// 1. የመውጣቶች ዝርዝር
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT 
            w.*,
            fa.name_am as account_name_am,
            fa.code as account_code,
            u.full_name as created_by_name
        FROM withdrawals w
        JOIN financial_accounts fa ON w.account_id = fa.id
        JOIN users u ON w.created_by = u.id
        ORDER BY w.withdrawal_date DESC, w.id DESC
        LIMIT 50
    ");
    $withdrawals = $stmt->fetchAll();
    foreach ($withdrawals as &$wd) {
        $wd['amount'] = (float)$wd['amount'];
    }

    $accounts = $pdo->query("SELECT * FROM financial_accounts WHERE is_active = 1")->fetchAll();

    send_success('የመውጣቶች ዝርዝር', [
        'withdrawals' => $withdrawals,
        'accounts'    => $accounts
    ]);
}

// 2. አዲስ መውጣት መመዝገብ
if ($action === 'create') {
    // የማናጀር ፍቃድ ምርመራ
    if (!check_permission($user, 'can_record_withdrawal')) {
        send_error('የባለቤት ወይም ሌላ ገንዘብ ማውጣት የመመዝገብ ፈቃድ የለዎትም', 403);
    }

    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('wth-' . bin2hex(random_bytes(8)));
    $account_id = (int)($data['account_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0.0);
    $withdrawal_date = $data['withdrawal_date'] ?? date('Y-m-d');
    $recipient_name = trim($data['recipient_name'] ?? '');
    $reason = trim($data['reason'] ?? '');

    if ($account_id <= 0 || $amount <= 0 || empty($recipient_name) || empty($reason)) {
        send_error('አካውንት፣ መጠን፣ ተቀባይ እና ምክንያት ማስገባት ግዴታ ነው');
    }

    // በአካውንቱ ውስጥ በቂ ገንዘብ መኖሩን ማረጋገጥ
    $stmtBal = $pdo->prepare("SELECT current_balance FROM financial_accounts WHERE id = :id");
    $stmtBal->execute([':id' => $account_id]);
    $currBal = (float)($stmtBal->fetchColumn() ?: 0.0);

    if ($currBal < $amount) {
        send_error("በቂ ሂሳብ የለም! በአካውንቱ ያለው ገንዘብ: {$currBal} ETB ብቻ ነው");
    }

    // በአይደምፖተንሲ ቁልፍ ማረጋገጥ
    $stmtCheck = $pdo->prepare("SELECT id FROM withdrawals WHERE uuid = :uuid");
    $stmtCheck->execute([':uuid' => $uuid]);
    if ($existing = $stmtCheck->fetch()) {
        send_success('ይህ መውጣት ቀድሞ ተመዝግቧል (Idempotent)', ['withdrawal_id' => $existing['id'], 'uuid' => $uuid]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO withdrawals (uuid, account_id, amount, withdrawal_date, reason, recipient_name, created_by, created_at)
            VALUES (:uuid, :acc_id, :amt, :wdate, :reason, :rec, :created_by, NOW())
        ");
        $stmt->execute([
            ':uuid'       => $uuid,
            ':acc_id'     => $account_id,
            ':amt'        => $amount,
            ':wdate'      => $withdrawal_date,
            ':reason'     => $reason,
            ':rec'        => $recipient_name,
            ':created_by' => $user['id']
        ]);
        $withdrawal_id = (int)$pdo->lastInsertId();

        // የፋይናንስ ሂሳብ መቀነስ (ነገር ግን እንደ operating expense አይቆጠርም!)
        record_payment(
            $pdo,
            'withdrawal',
            $uuid,
            $account_id,
            $amount,
            $withdrawal_date . ' ' . date('H:i:s'),
            'የባለቤት/የተፈቀደ ገንዘብ መውጣት ለ: ' . $recipient_name . ' (' . $reason . ')',
            $user['id'],
            false
        );

        $pdo->commit();
        log_audit($user['id'], 'WITHDRAWAL_CREATE', 'withdrawals', (string)$withdrawal_id, null, $data);

        send_success('የገንዘብ መውጣቱ ተመዝግቧል (የስራ ማስኬጃ ወጪ ተደርጎ አልተቆጠረም)', ['withdrawal_id' => $withdrawal_id, 'uuid' => $uuid], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('መውጣቱን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
