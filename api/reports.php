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

// 4. ዘመናዊ ሪፖርት — ብዙ ማጣሪያዎች (Modern Reports with filters)
// ?type=modern&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
//   &category_id=&product_id=&account_id=&payment_method=all|cash|credit|mixed&staff_id=
// super_admin + admin ብቻ (can_view_pl ከላይ ተፈትኗል)። የድሮዎቹ 3 tabs አልተነኩም።
if ($type === 'modern') {
    $f_category = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
    $f_product  = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : null;
    $f_account  = isset($_GET['account_id']) && $_GET['account_id'] !== '' ? (int)$_GET['account_id'] : null;
    $f_staff    = isset($_GET['staff_id']) && $_GET['staff_id'] !== '' ? (int)$_GET['staff_id'] : null;
    $f_payment  = $_GET['payment_method'] ?? 'all';
    if (!in_array($f_payment, ['all', 'cash', 'credit', 'mixed'], true)) $f_payment = 'all';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        send_error('ትክክለኛ ቀን ያስገቡ (YYYY-MM-DD)');
    }
    if ($start_date > $end_date) {
        send_error('የሚጀምርበት ቀን ከሚያልቀው ቀን መብለጥ የለበትም');
    }

    // የሽያጭ ስፋት (scope) — ሁሉም የሽያጭ ጥያቄዎች ይጠቀማሉ
    $where = ["s.status = 'completed'", "DATE(s.sale_date) BETWEEN :start_d AND :end_d"];
    $params = [':start_d' => $start_date, ':end_d' => $end_date];
    if ($f_staff !== null) { $where[] = "s.created_by = :staff"; $params[':staff'] = $f_staff; }
    if ($f_account !== null) {
        $where[] = "EXISTS (SELECT 1 FROM payments p WHERE p.transaction_type = 'sale' AND p.reference_id = s.uuid AND p.account_id = :account)";
        $params[':account'] = $f_account;
    }
    // የክፍያ ዘዴ: cash = የተከፈለ + ክፍያዎቹ ሁሉ በ Cash account፤ mixed = የተከፈለ + Cash ብቻ ያልሆነ
    $nonCashPay = "EXISTS (SELECT 1 FROM payments p JOIN financial_accounts fa ON fa.id = p.account_id WHERE p.transaction_type = 'sale' AND p.reference_id = s.uuid AND fa.code <> 'cash')";
    if ($f_payment === 'cash') {
        $where[] = "s.payment_status = 'paid' AND NOT ($nonCashPay)";
    } elseif ($f_payment === 'credit') {
        $where[] = "s.payment_status = 'credit'";
    } elseif ($f_payment === 'mixed') {
        $where[] = "s.payment_status = 'paid' AND ($nonCashPay)";
    }
    // ምርት/ምድብ ማጣሪያ (በ sale_items በኩል) — category እዚህ የምግብ/ምርት ምድብ ነው
    $itemFilter = '';
    if ($f_product !== null) { $itemFilter .= " AND pr.id = :product"; $params[':product'] = $f_product; }
    if ($f_category !== null) { $itemFilter .= " AND pr.category_id = :category"; $params[':category'] = $f_category; }
    if ($itemFilter !== '') {
        $where[] = "EXISTS (SELECT 1 FROM sale_items si2 JOIN products pr ON pr.id = si2.product_id WHERE si2.sale_id = s.id$itemFilter)";
    }
    $scope = implode(' AND ', $where);
    $itemFiltered = ($f_product !== null || $f_category !== null);

    // 4.1 ማጠቃለያ: ገቢ + COGS + የሽያጭ ብዛት + ቅናሽ
    if ($itemFiltered) {
        // በምርት/ምድብ ሲጣራ ገቢ ከተጣሩት items ብቻ ነው (ቅናሽ አልተከፋፈለም)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(si.total_price), 0) as revenue,
                   COALESCE(SUM(si.quantity * si.unit_cost), 0) as cogs,
                   COUNT(DISTINCT s.id) as sales_count
            FROM sales s
            JOIN sale_items si ON si.sale_id = s.id
            JOIN products pr ON pr.id = si.product_id
            WHERE $scope$itemFilter
        ");
        $stmt->execute($params);
        $row = $stmt->fetch();
        $revenue = (float)$row['revenue'];
        $cogs = (float)$row['cogs'];
        $salesCount = (int)$row['sales_count'];
    } else {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(s.grand_total), 0) as revenue, COUNT(*) as sales_count FROM sales s WHERE $scope");
        $stmt->execute($params);
        $row = $stmt->fetch();
        $revenue = (float)$row['revenue'];
        $salesCount = (int)$row['sales_count'];
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(si.quantity * si.unit_cost), 0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE $scope");
        $stmt->execute($params);
        $cogs = (float)$stmt->fetchColumn();
    }
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(s.discount), 0) FROM sales s WHERE $scope");
    $stmt->execute($params);
    $discounts = (float)$stmt->fetchColumn();

    $grossProfit = round($revenue - $cogs, 2);

    // 4.2 ወጪዎች (በ account/ሠራተኛ/ቀን ብቻ — category እዚህ የወጪ ምድብ አይደለም)
    $expWhere = ["DATE(e.expense_date) BETWEEN :start_d AND :end_d"];
    $expParams = [':start_d' => $start_date, ':end_d' => $end_date];
    if ($f_account !== null) { $expWhere[] = "e.account_id = :account"; $expParams[':account'] = $f_account; }
    if ($f_staff !== null) { $expWhere[] = "e.created_by = :staff"; $expParams[':staff'] = $f_staff; }
    $expScope = implode(' AND ', $expWhere);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(e.amount), 0) FROM expenses e WHERE $expScope");
    $stmt->execute($expParams);
    $totalExpenses = (float)$stmt->fetchColumn();

    // 4.3 መውጣት (withdrawals)
    $wthWhere = ["DATE(w.withdrawal_date) BETWEEN :start_d AND :end_d"];
    $wthParams = [':start_d' => $start_date, ':end_d' => $end_date];
    if ($f_account !== null) { $wthWhere[] = "w.account_id = :account"; $wthParams[':account'] = $f_account; }
    if ($f_staff !== null) { $wthWhere[] = "w.created_by = :staff"; $wthParams[':staff'] = $f_staff; }
    $wthScope = implode(' AND ', $wthWhere);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(w.amount), 0) FROM withdrawals w WHERE $wthScope");
    $stmt->execute($wthParams);
    $totalWithdrawals = (float)$stmt->fetchColumn();

    $netProfit = round($grossProfit - $totalExpenses, 2);

    // 4.4 በቀን ሽያጭ
    if ($itemFiltered) {
        $stmt = $pdo->prepare("
            SELECT DATE(s.sale_date) as day, COALESCE(SUM(si.total_price), 0) as revenue, COUNT(DISTINCT s.id) as sales_count
            FROM sales s
            JOIN sale_items si ON si.sale_id = s.id
            JOIN products pr ON pr.id = si.product_id
            WHERE $scope$itemFilter
            GROUP BY DATE(s.sale_date) ORDER BY day ASC
        ");
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare("
            SELECT DATE(s.sale_date) as day, COALESCE(SUM(s.grand_total), 0) as revenue, COUNT(*) as sales_count
            FROM sales s WHERE $scope GROUP BY DATE(s.sale_date) ORDER BY day ASC
        ");
        $stmt->execute($params);
    }
    $salesByDay = $stmt->fetchAll();
    foreach ($salesByDay as &$d) { $d['revenue'] = (float)$d['revenue']; $d['sales_count'] = (int)$d['sales_count']; }

    // 4.5 ከፍተኛ ምርቶች በገቢ (top 10)
    $stmt = $pdo->prepare("
        SELECT p.id, p.name_am, p.name_en, p.unit,
               COALESCE(SUM(si.quantity), 0) as sold_qty,
               COALESCE(SUM(si.total_price), 0) as total_sales,
               COALESCE(SUM(si.quantity * si.unit_cost), 0) as total_cost,
               COALESCE(SUM(si.total_price - (si.quantity * si.unit_cost)), 0) as profit
        FROM sale_items si
        JOIN sales s ON s.id = si.sale_id
        JOIN products p ON p.id = si.product_id
        WHERE $scope
        GROUP BY p.id, p.name_am, p.name_en, p.unit
        ORDER BY total_sales DESC LIMIT 10
    ");
    $stmt->execute($params);
    $topProducts = $stmt->fetchAll();
    foreach ($topProducts as &$tp) {
        $tp['sold_qty'] = (float)$tp['sold_qty'];
        $tp['total_sales'] = (float)$tp['total_sales'];
        $tp['total_cost'] = (float)$tp['total_cost'];
        $tp['profit'] = (float)$tp['profit'];
    }

    // 4.6 ወጪ በምድብ
    $stmt = $pdo->prepare("
        SELECT c.id, c.name_am as category_name, COALESCE(SUM(e.amount), 0) as total_amount, COUNT(*) as cnt
        FROM expenses e
        JOIN categories c ON c.id = e.category_id
        WHERE $expScope
        GROUP BY c.id, c.name_am ORDER BY total_amount DESC
    ");
    $stmt->execute($expParams);
    $expensesByCategory = $stmt->fetchAll();
    foreach ($expensesByCategory as &$ec) { $ec['total_amount'] = (float)$ec['total_amount']; $ec['cnt'] = (int)$ec['cnt']; }

    // 4.7 የሽያጭ ዝርዝር (የቅርብ 50)
    $stmt = $pdo->prepare("
        SELECT s.receipt_number, s.sale_date, s.grand_total, s.discount, s.payment_status,
               c.name as customer_name, u.full_name as staff_name,
               CASE WHEN s.payment_status = 'credit' THEN 'credit'
                    WHEN NOT ($nonCashPay) THEN 'cash' ELSE 'mixed' END as pay_method
        FROM sales s
        LEFT JOIN contacts c ON c.id = s.customer_id
        JOIN users u ON u.id = s.created_by
        WHERE $scope
        ORDER BY s.sale_date DESC LIMIT 50
    ");
    $stmt->execute($params);
    $salesList = $stmt->fetchAll();
    foreach ($salesList as &$sl) {
        $sl['grand_total'] = (float)$sl['grand_total'];
        $sl['discount'] = (float)$sl['discount'];
    }

    send_success('ዘመናዊ ሪፖርት', [
        'period' => ['start_date' => $start_date, 'end_date' => $end_date],
        'filters' => [
            'category_id' => $f_category, 'product_id' => $f_product,
            'account_id' => $f_account, 'payment_method' => $f_payment, 'staff_id' => $f_staff
        ],
        'summary' => [
            'revenue'       => $revenue,
            'discounts'     => $discounts,
            'cogs'          => $cogs,
            'gross_profit'  => $grossProfit,
            'expenses'      => $totalExpenses,
            'withdrawals'   => $totalWithdrawals,
            'net_profit'    => $netProfit,
            'sales_count'   => $salesCount
        ],
        'sales_by_day'         => $salesByDay,
        'top_products'         => $topProducts,
        'expenses_by_category' => $expensesByCategory,
        'sales_list'           => $salesList
    ]);
}

send_error('ያልታወቀ የሪፖርት ዓይነት', 404);
