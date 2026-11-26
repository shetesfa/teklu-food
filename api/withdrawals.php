<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * ገንዘብ መውጣት አስተዳደር API (Withdrawals API)
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

// 1. የመውጣቶች ዝርዝር ከነማጣሪያቸው (List Withdrawals with Filters)
if ($action === 'list') {
    $recipient = trim($_GET['recipient'] ?? '');
    $account_id = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : null;
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;

    $sql = "
        SELECT 
            w.*,
            fa.name_am as account_name_am,
            fa.name_en as account_name_en,
            fa.code as account_code,
            u.full_name as created_by_name
        FROM withdrawals w
        JOIN financial_accounts fa ON w.account_id = fa.id
        JOIN users u ON w.created_by = u.id
        WHERE 1=1
    ";
    $params = [];

    if (!empty($recipient)) {
        $sql .= " AND w.recipient_name LIKE :rec";
        $params[':rec'] = '%' . $recipient . '%';
    }
    if ($account_id) {
        $sql .= " AND w.account_id = :acc_id";
        $params[':acc_id'] = $account_id;
    }
    if ($start_date && $end_date) {
        $sql .= " AND w.withdrawal_date BETWEEN :sd AND :ed";
        $params[':sd'] = $start_date;
        $params[':ed'] = $end_date;
    }

    $sql .= " ORDER BY w.withdrawal_date DESC, w.id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $withdrawals = $stmt->fetchAll();

    $totalAmount = 0.0;
    foreach ($withdrawals as &$wd) {
        $wd['amount'] = (float)$wd['amount'];
        $totalAmount += $wd['amount'];
    }

    $accounts = $pdo->query("SELECT * FROM financial_accounts WHERE is_active = 1")->fetchAll();

    send_success('የመውጣቶች ዝርዝር', [
        'withdrawals'   => $withdrawals,
        'total_amount'  => round($totalAmount, 2),
        'accounts'      => $accounts
    ]);
}

// 2. አዲስ ገንዘብ መውጣት መመዝገብ (Record New Withdrawal) — Admin ብቻ
if ($action === 'create') {
    if (!check_permission($user, 'withdrawals')) {
        send_error('ገንዘብ ለመውጣት ፈቃድ የለዎትም', 403);
    }
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('wth-' . bin2hex(random_bytes(8)));
    $account_id = (int)($data['account_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0.0);
    $withdrawal_date = $data['withdrawal_date'] ?? date('Y-m-d H:i:s');
    $recipient_name = trim($data['recipient_name'] ?? '');
    $reason = trim($data['reason'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if ($account_id <= 0 || $amount <= 0 || empty($recipient_name) || empty($reason)) {
        send_error('እባክዎ አካውንት፣ መጠን፣ ተቀባይ ሰው እና ምክንያት በትክክል ያስገቡ');
    }

    // በአካውንቱ ውስጥ በቂ ገንዘብ መኖሩን ማረጋገጥ
    $stmtBal = $pdo->prepare("SELECT current_balance, name_am FROM financial_accounts WHERE id = :id");
    $stmtBal->execute([':id' => $account_id]);
    $accRow = $stmtBal->fetch();
    $currBal = (float)($accRow['current_balance'] ?? 0.0);

    if ($currBal < $amount) {
        send_error(sprintf('በተመረጠው %s አካውንት ውስጥ በቂ ሂሳብ የለም! ያለው: %s ETB ብቻ ነው', $accRow['name_am'] ?? 'አካውንት', $currBal));
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
            INSERT INTO withdrawals (uuid, account_id, amount, withdrawal_date, reason, recipient_name, notes, created_by, created_at)
            VALUES (:uuid, :acc_id, :amt, :wdate, :reason, :rec, :notes, :created_by, NOW())
        ");
        $stmt->execute([
            ':uuid'       => $uuid,
            ':acc_id'     => $account_id,
            ':amt'        => $amount,
            ':wdate'      => $withdrawal_date,
            ':reason'     => $reason,
            ':rec'        => $recipient_name,
            ':notes'      => $notes,
            ':created_by' => $user['id']
        ]);
        $withdrawal_id = (int)$pdo->lastInsertId();

        // የፋይናንስ ሂሳብ መቀነስ (እንደ operating expense አይቆጠርም!)
        record_payment(
            $pdo,
            'withdrawal',
            $uuid,
            $account_id,
            $amount,
            $withdrawal_date,
            'ገንዘብ መውጣት ለ ' . $recipient_name . ' (' . $reason . ')',
            $user['id'],
            false
        );

        $pdo->commit();
        log_audit($user['id'], 'WITHDRAWAL_CREATE', 'withdrawals', (string)$withdrawal_id, null, $data);

        send_success('ገንዘብ መውጣቱ በተሳካ ሁኔታ ተመዝግቧል፤ የአካውንቱ ሂሳብ ቀንሷል', [
            'withdrawal_id' => $withdrawal_id,
            'uuid'          => $uuid,
            'amount'        => $amount
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('መውጣቱን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
