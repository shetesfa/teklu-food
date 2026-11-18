<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * የስራ ማስኬጃ ወጪ አስተዳደር API (Operating Expenses API)
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

// 1. የወጪዎች ዝርዝር ከነማጣሪያቸው
if ($action === 'list') {
    $category_id = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $account_id = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : null;
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    $search = trim($_GET['search'] ?? '');

    $sql = "
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
        WHERE 1=1
    ";
    $params = [];

    if ($category_id) {
        $sql .= " AND e.category_id = :cat_id";
        $params[':cat_id'] = $category_id;
    }
    if ($account_id) {
        $sql .= " AND e.account_id = :acc_id";
        $params[':acc_id'] = $account_id;
    }
    if ($start_date && $end_date) {
        $sql .= " AND e.expense_date BETWEEN :sd AND :ed";
        $params[':sd'] = $start_date;
        $params[':ed'] = $end_date;
    }
    if (!empty($search)) {
        $sql .= " AND (e.description LIKE :srch OR c.name_am LIKE :srch OR c.name_en LIKE :srch)";
        $params[':srch'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY e.expense_date DESC, e.id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll();

    $totalExpenses = 0.0;
    foreach ($expenses as &$exp) {
        $exp['amount'] = (float)$exp['amount'];
        $totalExpenses += $exp['amount'];
    }

    $categories = $pdo->query("SELECT * FROM categories WHERE type = 'expense'")->fetchAll();
    $accounts = $pdo->query("SELECT * FROM financial_accounts WHERE is_active = 1")->fetchAll();

    send_success('የወጪዎች ዝርዝር', [
        'expenses'       => $expenses,
        'total_expenses' => round($totalExpenses, 2),
        'categories'     => $categories,
        'accounts'       => $accounts
    ]);
}

// 2. አዲስ ወጪ መመዝገብ (Record New Expense)
if ($action === 'create') {
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('exp-' . bin2hex(random_bytes(8)));
    $category_id = (int)($data['category_id'] ?? 0);
    $account_id = (int)($data['account_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0.0);
    $expense_date = $data['expense_date'] ?? date('Y-m-d H:i:s');
    $description = trim($data['description'] ?? '');

    if ($category_id <= 0 || $account_id <= 0 || $amount <= 0 || empty($description)) {
        send_error('እባክዎ የወጪ ምድብ፣ የክፍያ አካውንት፣ መጠን እና ማብራሪያ በትክክል ያስገቡ');
    }

    // በፋይናንስ አካውንቱ በቂ ሂሳብ መኖሩን ማረጋገጥ
    $stmtBal = $pdo->prepare("SELECT current_balance, name_am FROM financial_accounts WHERE id = :id");
    $stmtBal->execute([':id' => $account_id]);
    $accRow = $stmtBal->fetch();
    $accBal = (float)($accRow['current_balance'] ?? 0.0);
    if ($accBal < $amount) {
        send_error(sprintf('በተመረጠው %s አካውንት ውስጥ በቂ ሂሳብ የለም! ያለው: %s ETB ብቻ ነው', $accRow['name_am'] ?? 'አካውንት', $accBal));
    }

    // አይደምፖተንሲ ምርመራ
    $stmtCheck = $pdo->prepare("SELECT id FROM expenses WHERE uuid = :uuid");
    $stmtCheck->execute([':uuid' => $uuid]);
    if ($existing = $stmtCheck->fetch()) {
        send_success('ይህ ወጪ ቀድሞ ተመዝግቧል (Idempotent)', ['expense_id' => $existing['id'], 'uuid' => $uuid]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO expenses (uuid, category_id, account_id, amount, expense_date, department, description, created_by, created_at)
            VALUES (:uuid, :cat_id, :acc_id, :amt, :edate, 'general', :desc, :created_by, NOW())
        ");
        $stmt->execute([
            ':uuid'       => $uuid,
            ':cat_id'     => $category_id,
            ':acc_id'     => $account_id,
            ':amt'        => $amount,
            ':edate'      => $expense_date,
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
            $expense_date,
            'የስራ ማስኬጃ ወጪ ክፍያ: ' . $description,
            $user['id'],
            false
        );

        $pdo->commit();
        log_audit($user['id'], 'EXPENSE_CREATE', 'expenses', (string)$expense_id, null, $data);

        send_success('የስራ ማስኬጃ ወጪው በተሳካ ሁኔታ ተመዝግቧል፤ የአካውንቱ ሂሳብ ቀንሷል', [
            'expense_id' => $expense_id,
            'uuid'       => $uuid,
            'amount'     => $amount
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ወጪውን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
