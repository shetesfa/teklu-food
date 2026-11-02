<?php
/**
 * የወጪ አስተዳደር API (Expenses API)
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

// 1. የወጪዎች ዝርዝር
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT 
            e.*,
            c.name_am as category_name_am,
            c.name_en as category_name_en,
            fa.name_am as account_name_am,
            fa.code as account_code,
            u.full_name as created_by_name
        FROM expenses e
        JOIN categories c ON e.category_id = c.id
        JOIN financial_accounts fa ON e.account_id = fa.id
        JOIN users u ON e.created_by = u.id
        ORDER BY e.expense_date DESC, e.id DESC
        LIMIT 50
    ");
    $expenses = $stmt->fetchAll();
    foreach ($expenses as &$exp) {
        $exp['amount'] = (float)$exp['amount'];
    }

    $categories = $pdo->query("SELECT * FROM categories WHERE type = 'expense'")->fetchAll();
    $accounts = $pdo->query("SELECT * FROM financial_accounts WHERE is_active = 1")->fetchAll();

    send_success('የወጪዎች ዝርዝር', [
        'expenses'   => $expenses,
        'categories' => $categories,
        'accounts'   => $accounts
    ]);
}

// 2. አዲስ ወጪ መመዝገብ
if ($action === 'create') {
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('exp-' . bin2hex(random_bytes(8)));
    $category_id = (int)($data['category_id'] ?? 0);
    $account_id = (int)($data['account_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0.0);
    $expense_date = $data['expense_date'] ?? date('Y-m-d');
    $department = $data['department'] ?? 'general';
    $description = trim($data['description'] ?? '');

    if ($category_id <= 0 || $account_id <= 0 || $amount <= 0 || empty($description)) {
        send_error('ምድብ፣ የክፍያ አካውንት፣ የገንዘብ መጠን እና ማብራሪያ ማስገባት ግዴታ ነው');
    }

    // በአይደምፖተንሲ ቁልፍ ማረጋገጥ
    $stmtCheck = $pdo->prepare("SELECT id FROM expenses WHERE uuid = :uuid");
    $stmtCheck->execute([':uuid' => $uuid]);
    if ($existing = $stmtCheck->fetch()) {
        send_success('ይህ ወጪ ቀድሞ ተመዝግቧል (Idempotent)', ['expense_id' => $existing['id'], 'uuid' => $uuid]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO expenses (uuid, category_id, account_id, amount, expense_date, department, description, created_by, created_at)
            VALUES (:uuid, :cat_id, :acc_id, :amt, :edate, :dept, :desc, :created_by, NOW())
        ");
        $stmt->execute([
            ':uuid'       => $uuid,
            ':cat_id'     => $category_id,
            ':acc_id'     => $account_id,
            ':amt'        => $amount,
            ':edate'      => $expense_date,
            ':dept'       => $department,
            ':desc'       => $description,
            ':created_by' => $user['id']
        ]);
        $expense_id = (int)$pdo->lastInsertId();

        // የፋይናንስ ሂሳብ መቀነስ
        record_payment(
            $pdo,
            'expense',
            $uuid,
            $account_id,
            $amount,
            $expense_date . ' ' . date('H:i:s'),
            'የወጪ ክፍያ: ' . $description,
            $user['id'],
            false
        );

        $pdo->commit();
        log_audit($user['id'], 'EXPENSE_CREATE', 'expenses', (string)$expense_id, null, $data);

        send_success('ወጪው በተሳካ ሁኔታ ተመዝግቧል', ['expense_id' => $expense_id, 'uuid' => $uuid], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ወጪውን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
