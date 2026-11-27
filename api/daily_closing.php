<?php
/**
 * ዕለታዊ ሂሳብ መዝጊያ API (Daily Closing API)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/audit.php';

$user = get_authenticated_user();
$pdo = get_db_connection();
$action = $_GET['action'] ?? 'status';

$closing_date = $_GET['date'] ?? date('Y-m-d');

// 1. የዕለቱን የሂሳብ ሁኔታ ማጠቃለያ (Get Daily Status) — የዕለት ሠራተኞች ሁሉ (worker ጭምር)
if ($action === 'status') {
    require_daily_worker($user);
    // የተዘጋ መዝገብ ካለ መመልከት
    $stmtClosed = $pdo->prepare("
        SELECT dc.*, u.full_name as closed_by_name, r.full_name as reopened_by_name
        FROM daily_closings dc
        JOIN users u ON dc.closed_by = u.id
        LEFT JOIN users r ON dc.reopened_by = r.id
        WHERE dc.closing_date = :d
    ");
    $stmtClosed->execute([':d' => $closing_date]);
    $closedRecord = $stmtClosed->fetch();

    // የሲስተም አሁን ያሉ ሚዛኖች
    $stmtAcc = $pdo->query("SELECT code, current_balance FROM financial_accounts WHERE is_active = 1");
    $accs = $stmtAcc->fetchAll();
    $balances = ['cash' => 0.0, 'cbe' => 0.0, 'telebirr' => 0.0, 'other' => 0.0];
    foreach ($accs as $ac) {
        $bal = (float)$ac['current_balance'];
        if (isset($balances[$ac['code']])) {
            $balances[$ac['code']] = $bal;
        } else {
            $balances['other'] += $bal;
        }
    }

    // የዕለቱ ሽያጮች ድምር
    $stmtSales = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE DATE(sale_date) = :d AND status = 'completed'");
    $stmtSales->execute([':d' => $closing_date]);
    $day_sales = (float)$stmtSales->fetchColumn();

    // የዕለቱ ግዢዎች ድምር
    $stmtPur = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM purchases WHERE purchase_date = :d");
    $stmtPur->execute([':d' => $closing_date]);
    $day_purchases = (float)$stmtPur->fetchColumn();

    // የዕለቱ ወጪዎች ድምር
    $stmtExp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = :d");
    $stmtExp->execute([':d' => $closing_date]);
    $day_expenses = (float)$stmtExp->fetchColumn();

    // የዕለቱ መውጣቶች ድምር
    $stmtWth = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE withdrawal_date = :d");
    $stmtWth->execute([':d' => $closing_date]);
    $day_withdrawals = (float)$stmtWth->fetchColumn();

    send_success('የዕለት ሂሳብ ሁኔታ', [
        'date'            => $closing_date,
        'is_closed'       => !empty($closedRecord) && (int)$closedRecord['is_reopened'] === 0,
        'closing_record'  => $closedRecord,
        'system_balances' => $balances,
        'day_summary'     => [
            'total_sales'       => $day_sales,
            'total_purchases'   => $day_purchases,
            'total_expenses'    => $day_expenses,
            'total_withdrawals' => $day_withdrawals,
        ]
    ]);
}

// 2. ዕለታዊ ሂሳብ መዝጋት (Close Day) — የዕለት ሠራተኞች ሁሉ (worker ጭምር)፤ ዳግም መክፈት super_admin ብቻ ነው
if ($action === 'close') {
    require_daily_worker($user);
    $data = get_request_data();
    $actual_cash = (float)($data['actual_cash'] ?? 0.0);
    $actual_cbe = (float)($data['actual_cbe'] ?? 0.0);
    $actual_telebirr = (float)($data['actual_telebirr'] ?? 0.0);
    $discrepancy_reason = trim($data['discrepancy_reason'] ?? '');

    // ሲስተም ላይ ያሉትን ሚዛኖች ማግኘት
    $stmtAcc = $pdo->query("SELECT code, current_balance FROM financial_accounts WHERE is_active = 1");
    $accs = $stmtAcc->fetchAll();
    $sysBalances = ['cash' => 0.0, 'cbe' => 0.0, 'telebirr' => 0.0];
    foreach ($accs as $ac) {
        if (isset($sysBalances[$ac['code']])) {
            $sysBalances[$ac['code']] = (float)$ac['current_balance'];
        }
    }

    $cash_diff = $actual_cash - $sysBalances['cash'];

    if (abs($cash_diff) > 0.01 && empty($discrepancy_reason)) {
        send_error('በሲስተሙ እና በእጅ ባለው ጥሬ ገንዘብ መካከል የ ' . abs($cash_diff) . ' ETB ልዩነት አለ፤ እባክዎ ምክንያቱን ያብራሩ');
    }

    // የዕለቱ ክንውኖች
    $stmtSales = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE DATE(sale_date) = :d AND status = 'completed'");
    $stmtSales->execute([':d' => $closing_date]);
    $day_sales = (float)$stmtSales->fetchColumn();

    $stmtPur = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM purchases WHERE purchase_date = :d");
    $stmtPur->execute([':d' => $closing_date]);
    $day_purchases = (float)$stmtPur->fetchColumn();

    $stmtExp = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = :d");
    $stmtExp->execute([':d' => $closing_date]);
    $day_expenses = (float)$stmtExp->fetchColumn();

    $stmtWth = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE withdrawal_date = :d");
    $stmtWth->execute([':d' => $closing_date]);
    $day_withdrawals = (float)$stmtWth->fetchColumn();

    $uuid = 'cls-' . bin2hex(random_bytes(8));

    $stmt = $pdo->prepare("
        INSERT INTO daily_closings (
            uuid, closing_date, system_cash, actual_cash, cash_difference,
            system_cbe, actual_cbe, system_telebirr, actual_telebirr,
            total_sales, total_purchases, total_expenses, total_withdrawals,
            discrepancy_reason, is_reopened, closed_by, closed_at
        ) VALUES (
            :uuid, :cdate, :scash, :acash, :cdiff,
            :scbe, :acbe, :stele, :atele,
            :sales, :purs, :exps, :wths,
            :reason, 0, :closed_by, NOW()
        )
        ON DUPLICATE KEY UPDATE
            actual_cash = VALUES(actual_cash),
            cash_difference = VALUES(cash_difference),
            actual_cbe = VALUES(actual_cbe),
            actual_telebirr = VALUES(actual_telebirr),
            discrepancy_reason = VALUES(discrepancy_reason),
            is_reopened = 0,
            closed_by = VALUES(closed_by),
            closed_at = NOW()
    ");
    $stmt->execute([
        ':uuid'      => $uuid,
        ':cdate'     => $closing_date,
        ':scash'     => $sysBalances['cash'],
        ':acash'     => $actual_cash,
        ':cdiff'     => $cash_diff,
        ':scbe'      => $sysBalances['cbe'],
        ':acbe'      => $actual_cbe,
        ':stele'     => $sysBalances['telebirr'],
        ':atele'     => $actual_telebirr,
        ':sales'     => $day_sales,
        ':purs'      => $day_purchases,
        ':exps'      => $day_expenses,
        ':wths'      => $day_withdrawals,
        ':reason'    => $discrepancy_reason,
        ':closed_by' => $user['id']
    ]);

    log_audit($user['id'], 'DAILY_CLOSING_LOCK', 'daily_closings', $closing_date, null, $data);

    send_success('የዕለቱ ሂሳብ በተሳካ ሁኔታ ተዘግቷል');
}

// 3. የተዘጋ ሂሳብ ዳግም መክፈት (Reopen - Super Admin Only)
if ($action === 'reopen') {
    require_super_admin($user);

    $data = get_request_data();
    $reopened_reason = trim($data['reopened_reason'] ?? '');

    if (empty($reopened_reason)) {
        send_error('ሂሳቡን ዳግም ለመክፈት ምክንያት ማስገባት ግዴታ ነው');
    }

    $stmt = $pdo->prepare("
        UPDATE daily_closings
        SET is_reopened = 1, reopened_by = :uid, reopened_reason = :reason
        WHERE closing_date = :d
    ");
    $stmt->execute([
        ':uid'    => $user['id'],
        ':reason' => $reopened_reason,
        ':d'      => $closing_date
    ]);

    log_audit($user['id'], 'DAILY_CLOSING_REOPEN', 'daily_closings', $closing_date, null, ['reason' => $reopened_reason]);

    send_success('የዕለቱ ሂሳብ ዳግም ተከፍቷል');
}

send_error('ያልታወቀ ክንውን', 404);
