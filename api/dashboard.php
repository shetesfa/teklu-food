<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * የዳሽቦርድ መረጃዎች API (Clean Dashboard API)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

$user = get_authenticated_user();
$pdo = get_db_connection();

$filter = $_GET['filter'] ?? 'today';
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if ($filter === 'today') {
    $start_date = date('Y-m-d');
    $end_date = date('Y-m-d');
} elseif ($filter === 'yesterday') {
    $start_date = date('Y-m-d', strtotime('-1 day'));
    $end_date = date('Y-m-d', strtotime('-1 day'));
} elseif ($filter === 'week') {
    $start_date = date('Y-m-d', strtotime('-6 days'));
    $end_date = date('Y-m-d');
} elseif ($filter === 'month') {
    $start_date = date('Y-m-01');
    $end_date = date('Y-m-d');
} elseif ($filter === 'last_month') {
    $start_date = date('Y-m-01', strtotime('first day of last month'));
    $end_date = date('Y-m-t', strtotime('last day of last month'));
} elseif ($filter === 'year') {
    $start_date = date('Y-01-01');
    $end_date = date('Y-m-d');
} elseif ($filter === 'custom') {
    $start_date = !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $end_date = !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
}

// 1. የፋይናንስ ሂሳቦች ሚዛን (Financial Liquid Balances)
$stmtAcc = $pdo->query("SELECT id, code, name_en, name_am, current_balance FROM financial_accounts WHERE is_active = 1");
$accounts = $stmtAcc->fetchAll();
$cash_balance = 0.0;
$cbe_balance = 0.0;
$telebirr_balance = 0.0;
$other_banks_balance = 0.0;

foreach ($accounts as $acc) {
    $bal = (float)$acc['current_balance'];
    if ($acc['code'] === 'cash') $cash_balance += $bal;
    elseif ($acc['code'] === 'cbe') $cbe_balance += $bal;
    elseif ($acc['code'] === 'telebirr') $telebirr_balance += $bal;
    else $other_banks_balance += $bal;
}
$total_liquid = $cash_balance + $cbe_balance + $telebirr_balance + $other_banks_balance;

// 2. ሽያጭ እና COGS (Sales & Cost of Goods Sold)
$stmtSales = $pdo->prepare("
    SELECT 
        COUNT(s.id) as sales_count,
        COALESCE(SUM(s.grand_total), 0) as total_sales,
        COALESCE(SUM(s.discount), 0) as total_discounts
    FROM sales s
    WHERE DATE(s.sale_date) BETWEEN :start_d AND :end_d
      AND s.status = 'completed'
");
$stmtSales->execute([':start_d' => $start_date, ':end_d' => $end_date]);
$salesData = $stmtSales->fetch();
$total_sales = (float)$salesData['total_sales'];

// COGS (የተሸጡ ዕቃዎች ወጪ)
$stmtCogs = $pdo->prepare("
    SELECT COALESCE(SUM(si.quantity * si.unit_cost), 0) as total_cogs
    FROM sale_items si
    JOIN sales s ON si.sale_id = s.id
    WHERE DATE(s.sale_date) BETWEEN :start_d AND :end_d
      AND s.status = 'completed'
");
$stmtCogs->execute([':start_d' => $start_date, ':end_d' => $end_date]);
$total_cogs = (float)$stmtCogs->fetchColumn();
$gross_profit = round($total_sales - $total_cogs, 2);

// 3. ግዢዎች (Purchases)
$stmtPurchases = $pdo->prepare("
    SELECT COALESCE(SUM(grand_total), 0) as total_purchases
    FROM purchases
    WHERE DATE(purchase_date) BETWEEN :start_d AND :end_d
");
$stmtPurchases->execute([':start_d' => $start_date, ':end_d' => $end_date]);
$total_purchases = (float)$stmtPurchases->fetchColumn();

// 4. የስራ ማስኬጃ ወጪዎች (Operating Expenses)
$stmtExpenses = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_expenses
    FROM expenses
    WHERE DATE(expense_date) BETWEEN :start_d AND :end_d
");
$stmtExpenses->execute([':start_d' => $start_date, ':end_d' => $end_date]);
$total_expenses = (float)$stmtExpenses->fetchColumn();

// የተጣራ ትርፍ (Net Profit = Gross Profit - Operating Expenses)
$net_profit = round($gross_profit - $total_expenses, 2);

// 5. የባለቤት መውጣት (Withdrawals) - ከስራ ማስኬጃ ወጪ ውጭ!
$stmtWithdrawals = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0) as total_withdrawals
    FROM withdrawals
    WHERE DATE(withdrawal_date) BETWEEN :start_d AND :end_d
");
$stmtWithdrawals->execute([':start_d' => $start_date, ':end_d' => $end_date]);
$total_withdrawals = (float)$stmtWithdrawals->fetchColumn();

// 6. ጠቅላላ የክምችት ዋጋ (Total Inventory Valuation)
$stmtStockVal = $pdo->query("
    SELECT COALESCE(SUM(sl.quantity * p.purchase_cost), 0) as stock_value
    FROM stock_levels sl
    JOIN products p ON sl.product_id = p.id
    WHERE sl.quantity > 0
");
$total_stock_value = (float)$stmtStockVal->fetchColumn();

// 7. አነስተኛ ክምችት ያላቸው ምርቶች (Low-Stock Alert)
$stmtLowStock = $pdo->query("
    SELECT 
        p.id, p.name_en, p.name_am, p.unit, p.min_stock_level,
        COALESCE(SUM(sl.quantity), 0) as current_stock
    FROM products p
    LEFT JOIN stock_levels sl ON p.id = sl.product_id
    WHERE p.is_active = 1 AND p.min_stock_level > 0
    GROUP BY p.id, p.name_en, p.name_am, p.unit, p.min_stock_level
    HAVING current_stock <= p.min_stock_level
    ORDER BY current_stock ASC
    LIMIT 15
");
$low_stock_products = $stmtLowStock->fetchAll();
foreach ($low_stock_products as &$lsp) {
    $lsp['min_stock_level'] = (float)$lsp['min_stock_level'];
    $lsp['current_stock'] = (float)$lsp['current_stock'];
}

// 8. ያልተከፈሉ የአቅራቢ እና የደንበኛ ዕዳዎች
$stmtDebt = $pdo->query("
    SELECT 
        COALESCE(SUM(CASE WHEN type = 'supplier' THEN outstanding_balance ELSE 0 END), 0) as supplier_debt,
        COALESCE(SUM(CASE WHEN type = 'customer' THEN outstanding_balance ELSE 0 END), 0) as customer_debt
    FROM contacts
");
$debtData = $stmtDebt->fetch();
$supplier_debt = (float)$debtData['supplier_debt'];
$customer_debt = (float)$debtData['customer_debt'];

send_success('ዳሽቦርድ መረጃ', [
    'period' => [
        'filter'     => $filter,
        'start_date' => $start_date,
        'end_date'   => $end_date,
    ],
    'actual_balances' => [
        'cash'         => round($cash_balance, 2),
        'cbe'          => round($cbe_balance, 2),
        'telebirr'     => round($telebirr_balance, 2),
        'other_banks'  => round($other_banks_balance, 2),
        'total_liquid' => round($total_liquid, 2),
    ],
    'operations' => [
        'total_sales'       => round($total_sales, 2),
        'total_purchases'   => round($total_purchases, 2),
        'total_expenses'    => round($total_expenses, 2),
        'total_withdrawals' => round($total_withdrawals, 2),
        'total_cogs'        => round($total_cogs, 2),
        'gross_profit'      => $gross_profit,
        'net_profit'        => $net_profit,
    ],
    'stock' => [
        'total_value'        => round($total_stock_value, 2),
        'low_stock_count'    => count($low_stock_products),
        'low_stock_products' => $low_stock_products,
    ],
    'debts' => [
        'supplier_debt' => round($supplier_debt, 2),
        'customer_debt' => round($customer_debt, 2),
    ],
    'accounts' => $accounts
]);
