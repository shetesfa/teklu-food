<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * ሪፖርቶች እና የትርፍና ኪሳራ ስሌት API (Reports & P&L API)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

$user = get_authenticated_user();
$pdo = get_db_connection();

if (!check_permission($user, 'can_view_pl')) {
    send_error('የትርፍና ኪሳራ ሪፖርት የማየት ፈቃድ የለዎትም', 403);
}

$type = $_GET['type'] ?? 'pl';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// 1. የትርፍና ኪሳራ ሪፖርት (Profit and Loss Statement)
if ($type === 'pl') {
    // 1.1 የሽያጭ ጠቅላላ ገቢ (Gross Revenue)
    $stmtSales = $pdo->prepare("
        SELECT 
            COALESCE(SUM(grand_total), 0) as total_revenue,
            COALESCE(SUM(discount), 0) as total_discounts
        FROM sales 
        WHERE DATE(sale_date) BETWEEN :start_d AND :end_d 
          AND status = 'completed'
    ");
    $stmtSales->execute([':start_d' => $start_date, ':end_d' => $end_date]);
    $revData = $stmtSales->fetch();
    $total_revenue = (float)$revData['total_revenue'];
    $total_discounts = (float)$revData['total_discounts'];

    // 1.2 የተሸጡ ዕቃዎች ወጪ (Cost of Goods Sold - COGS)
    $stmtCogs = $pdo->prepare("
        SELECT COALESCE(SUM(si.quantity * si.unit_cost), 0) as cogs
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        WHERE DATE(s.sale_date) BETWEEN :start_d AND :end_d 
          AND s.status = 'completed'
    ");
    $stmtCogs->execute([':start_d' => $start_date, ':end_d' => $end_date]);
    $cogs = (float)$stmtCogs->fetchColumn();

    // ጠቅላላ ትርፍ (Gross Profit = Revenue - COGS)
    $gross_profit = round($total_revenue - $cogs, 2);
    $gross_margin_pct = $total_revenue > 0 ? round(($gross_profit / $total_revenue) * 100, 2) : 0.0;

    // 1.3 የስራ ማስኬጃ ወጪዎች (Operating Expenses በየምድቡ)
    $stmtExp = $pdo->prepare("
        SELECT c.name_am as category_name, COALESCE(SUM(e.amount), 0) as total_amount
        FROM expenses e
        JOIN categories c ON e.category_id = c.id
        WHERE DATE(e.expense_date) BETWEEN :start_d AND :end_d
        GROUP BY e.category_id, c.id, c.name_am
        ORDER BY total_amount DESC
    ");
    $stmtExp->execute([':start_d' => $start_date, ':end_d' => $end_date]);
    $expenses_by_category = $stmtExp->fetchAll();
    $total_operating_expenses = 0.0;
    foreach ($expenses_by_category as &$ec) {
        $ec['total_amount'] = (float)$ec['total_amount'];
        $total_operating_expenses += $ec['total_amount'];
    }

    // 1.4 የተጣራ ትርፍ (Net Profit = Gross Profit - Operating Expenses)
    $net_profit = round($gross_profit - $total_operating_expenses, 2);
    $net_margin_pct = $total_revenue > 0 ? round(($net_profit / $total_revenue) * 100, 2) : 0.0;

    // 1.5 የባለቤት መውጣት (Owner Withdrawals) - ከስራ ማስኬጃ ወጪ ውጭ!
    $stmtWth = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM withdrawals
        WHERE DATE(withdrawal_date) BETWEEN :start_d AND :end_d
    ");
    $stmtWth->execute([':start_d' => $start_date, ':end_d' => $end_date]);
    $total_withdrawals = (float)$stmtWth->fetchColumn();

    send_success('የትርፍና ኪሳራ ሪፖርት', [
        'period' => [
            'start_date' => $start_date,
            'end_date'   => $end_date
        ],
        'statement' => [
            'gross_revenue'            => $total_revenue,
            'discounts'                => $total_discounts,
            'net_revenue'              => $total_revenue,
            'cost_of_goods_sold'       => $cogs,
            'gross_profit'             => $gross_profit,
            'gross_margin_percentage'  => $gross_margin_pct,
            'operating_expenses'       => $total_operating_expenses,
            'expenses_breakdown'       => $expenses_by_category,
            'net_profit'               => $net_profit,
            'net_margin_percentage'    => $net_margin_pct,
            'owner_withdrawals'        => $total_withdrawals
        ]
    ]);
}

// 2. የምርቶች አትራፊነት ሪፖርት (Product Profitability)
if ($type === 'product_profitability') {
    $stmt = $pdo->prepare("
        SELECT 
            p.id, p.name_am, p.name_en, p.unit,
            COALESCE(SUM(si.quantity), 0) as sold_qty,
            COALESCE(SUM(si.total_price), 0) as total_sales,
            COALESCE(SUM(si.quantity * si.unit_cost), 0) as total_cost,
            COALESCE(SUM(si.total_price - (si.quantity * si.unit_cost)), 0) as profit
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        JOIN products p ON si.product_id = p.id
        WHERE DATE(s.sale_date) BETWEEN :start_d AND :end_d AND s.status = 'completed'
        GROUP BY p.id, p.name_am, p.name_en, p.unit
        ORDER BY profit DESC
    ");
    $stmt->execute([':start_d' => $start_date, ':end_d' => $end_date]);
    $products = $stmt->fetchAll();
    foreach ($products as &$pr) {
        $pr['sold_qty'] = (float)$pr['sold_qty'];
        $pr['total_sales'] = (float)$pr['total_sales'];
        $pr['total_cost'] = (float)$pr['total_cost'];
        $pr['profit'] = (float)$pr['profit'];
    }

    send_success('የምርቶች አትራፊነት ሪፖርት', ['product_profitability' => $products]);
}

// 3. የጥሬ ገንዘብ ፍሰት ሪፖርት (Cash Flow)
if ($type === 'cashflow') {
    $stmtIn = $pdo->prepare("
        SELECT p.transaction_type, fa.name_am as account_name, COALESCE(SUM(p.amount), 0) as total_amount
        FROM payments p
        JOIN financial_accounts fa ON p.account_id = fa.id
        WHERE DATE(p.payment_date) BETWEEN :start_d AND :end_d
          AND p.transaction_type IN ('sale', 'customer_credit_settlement')
        GROUP BY p.transaction_type, p.account_id, fa.id, fa.name_am
    ");
    $stmtIn->execute([':start_d' => $start_date, ':end_d' => $end_date]);
    $inflows = $stmtIn->fetchAll();

    $stmtOut = $pdo->prepare("
        SELECT p.transaction_type, fa.name_am as account_name, COALESCE(SUM(p.amount), 0) as total_amount
        FROM payments p
        JOIN financial_accounts fa ON p.account_id = fa.id
        WHERE DATE(p.payment_date) BETWEEN :start_d AND :end_d
          AND p.transaction_type IN ('purchase', 'expense', 'withdrawal', 'supplier_debt_settlement')
        GROUP BY p.transaction_type, p.account_id, fa.id, fa.name_am
    ");
    $stmtOut->execute([':start_d' => $start_date, ':end_d' => $end_date]);
    $outflows = $stmtOut->fetchAll();

    $total_in = array_sum(array_column($inflows, 'total_amount'));
    $total_out = array_sum(array_column($outflows, 'total_amount'));

    send_success('የገንዘብ ፍሰት ሪፖርት', [
        'period'         => ['start_date' => $start_date, 'end_date' => $end_date],
        'total_inflow'   => (float)$total_in,
        'total_outflow'  => (float)$total_out,
        'net_cash_flow'  => (float)($total_in - $total_out),
        'inflows'        => $inflows,
        'outflows'       => $outflows
    ]);
}

send_error('ያልታወቀ የሪፖርት ዓይነት', 404);
