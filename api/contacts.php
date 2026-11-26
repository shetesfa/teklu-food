<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * አቅራቢዎች፣ ደንበኞች፣ የዱቤ መቆጣጠሪያ እና የሂሳብ መግለጫ API
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

// 1. የእውቂያዎች ዝርዝር (አቅራቢዎች እና ደንበኞች)
if ($action === 'list') {
    $type = $_GET['type'] ?? null;
    $sql = "SELECT * FROM contacts WHERE 1=1";
    $params = [];
    if ($type && in_array($type, ['customer', 'supplier'], true)) {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }
    $sql .= " ORDER BY name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $contacts = $stmt->fetchAll();
    foreach ($contacts as &$c) {
        $c['outstanding_balance'] = (float)$c['outstanding_balance'];
    }

    send_success('የእውቂያዎች ዝርዝር', ['contacts' => $contacts]);
}

// 2. የአቅራቢ ሙሉ የሂሳብ ዝርዝር እና ታሪክ (Supplier Account Details & History / Statement)
if ($action === 'supplier_statement') {
    $supplier_id = (int)($_GET['supplier_id'] ?? 0);
    if ($supplier_id <= 0) {
        send_error('የአቅራቢ መለያ አልተገለጸም');
    }

    // የአቅራቢ መረጃ
    $stmtSup = $pdo->prepare("SELECT * FROM contacts WHERE id = :id AND type = 'supplier'");
    $stmtSup->execute([':id' => $supplier_id]);
    $supplier = $stmtSup->fetch();
    if (!$supplier) {
        send_error('አቅራቢው አልተገኘም');
    }
    $supplier['outstanding_balance'] = (float)$supplier['outstanding_balance'];

    // 1. ሁሉም የእቃ ርክክቦች (Purchases / Goods Receipts)
    $stmtPurchases = $pdo->prepare("
        SELECT 
            p.*,
            u.full_name as receiver_name,
            fa.name_am as payment_account_name_am
        FROM purchases p
        LEFT JOIN financial_accounts fa ON p.payment_account_id = fa.id
        JOIN users u ON p.created_by = u.id
        WHERE p.supplier_id = :sid
        ORDER BY p.purchase_date DESC, p.id DESC
    ");
    $stmtPurchases->execute([':sid' => $supplier_id]);
    $purchases = $stmtPurchases->fetchAll();

    // የእያንዳንዱ ርክክብ እቃዎች
    $purchaseIds = array_column($purchases, 'id');
    $itemsByPurchase = [];
    $totalGoodsUnitsReceived = 0.0;
    $totalPurchaseAmount = 0.0;

    if (!empty($purchaseIds)) {
        $placeholders = implode(',', array_fill(0, count($purchaseIds), '?'));
        $stmtItems = $pdo->prepare("
            SELECT pi.*, pr.name_am as product_name_am, pr.name_en as product_name_en
            FROM purchase_items pi
            JOIN products pr ON pi.product_id = pr.id
            WHERE pi.purchase_id IN ($placeholders)
            ORDER BY pi.id ASC
        ");
        $stmtItems->execute($purchaseIds);
        foreach ($stmtItems->fetchAll() as $it) {
            $it['quantity'] = (float)$it['quantity'];
            $it['unit_price'] = (float)$it['unit_price'];
            $it['total_price'] = (float)$it['total_price'];
            $itemsByPurchase[$it['purchase_id']][] = $it;
            $totalGoodsUnitsReceived += $it['quantity'];
        }
    }

    foreach ($purchases as &$p) {
        $p['total_amount'] = (float)$p['total_amount'];
        $p['additional_cost'] = (float)$p['additional_cost'];
        $p['grand_total'] = (float)$p['grand_total'];
        $p['paid_amount'] = (float)$p['paid_amount'];
        $p['unpaid_balance'] = (float)$p['unpaid_balance'];
        $p['items'] = $itemsByPurchase[$p['id']] ?? [];
        $totalPurchaseAmount += $p['grand_total'];
    }

    // 2. ሁሉም የክፍያ ግብይቶች (Payments)
    // ሀ. በርክክብ ወቅት የተከፈሉ ክፍያዎች
    // ለ. በኋላ ላይ የተፈጸሙ የተናጠል ክፍያዎች (supplier_debt_settlement)
    $stmtPayments = $pdo->prepare("
        SELECT 
            p.*,
            fa.name_am as account_name_am,
            fa.code as account_code,
            u.full_name as paid_by_name
        FROM payments p
        JOIN financial_accounts fa ON p.account_id = fa.id
        JOIN users u ON p.created_by = u.id
        WHERE (
            (p.transaction_type = 'purchase' AND p.reference_id IN (
                SELECT uuid FROM purchases WHERE supplier_id = :sid1
            ))
            OR 
            (p.transaction_type = 'supplier_debt_settlement' AND p.reference_id LIKE :ref_prefix)
        )
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $stmtPayments->execute([
        ':sid1'       => $supplier_id,
        ':ref_prefix' => 'sup-pay-' . $supplier_id . '-%'
    ]);
    $payments = $stmtPayments->fetchAll();

    $totalPaymentsMade = 0.0;
    foreach ($payments as &$py) {
        $py['amount'] = (float)$py['amount'];
        $totalPaymentsMade += $py['amount'];
    }

    send_success('የአቅራቢ ሙሉ የሂሳብ ዝርዝር', [
        'supplier' => $supplier,
        'summary'  => [
            'total_goods_received_units' => $totalGoodsUnitsReceived,
            'total_purchase_amount'      => round($totalPurchaseAmount, 2),
            'total_payments_made'        => round($totalPaymentsMade, 2),
            'outstanding_balance'        => $supplier['outstanding_balance'],
            'total_deliveries_count'     => count($purchases),
            'total_payments_count'       => count($payments)
        ],
        'purchases' => $purchases,
        'payments'  => $payments
    ]);
}

// 2.1 የደንበኛ ሙሉ የዱቤ ዝርዝር እና ታሪክ (Customer Debt Statement & Order History)
if ($action === 'customer_statement') {
    $customer_id = (int)($_GET['customer_id'] ?? 0);
    if ($customer_id <= 0) {
        send_error('የደንበኛ መለያ አልተገለጸም');
    }

    $stmtCust = $pdo->prepare("SELECT * FROM contacts WHERE id = :id AND type = 'customer'");
    $stmtCust->execute([':id' => $customer_id]);
    $customer = $stmtCust->fetch();
    if (!$customer) {
        send_error('ደንበኛው አልተገኘም');
    }
    $customer['outstanding_balance'] = (float)$customer['outstanding_balance'];

    // 1. ሁሉም በብድር የተወሰዱ ወይም የተመዘገቡ የሽያጭ ደረሰኞች
    $stmtSales = $pdo->prepare("
        SELECT 
            s.*,
            u.full_name as seller_name
        FROM sales s
        JOIN users u ON s.created_by = u.id
        WHERE s.customer_id = :cid
        ORDER BY s.sale_date DESC, s.id DESC
    ");
    $stmtSales->execute([':cid' => $customer_id]);
    $sales = $stmtSales->fetchAll();

    $saleIds = array_column($sales, 'id');
    $itemsBySale = [];
    $totalCreditSalesAmount = 0.0;

    if (!empty($saleIds)) {
        $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
        $stmtItems = $pdo->prepare("
            SELECT si.*, 
                   COALESCE(p.name_am, si.product_id) as food_name_am,
                   COALESCE(p.name_en, '') as food_name_en,
                   p.unit
            FROM sale_items si
            LEFT JOIN products p ON si.product_id = p.id
            WHERE si.sale_id IN ($placeholders)
            ORDER BY si.id ASC
        ");
        $stmtItems->execute($saleIds);
        foreach ($stmtItems->fetchAll() as $it) {
            $it['quantity'] = (float)$it['quantity'];
            $it['unit_price'] = (float)$it['unit_price'];
            $it['total_price'] = (float)$it['total_price'];
            $itemsBySale[$it['sale_id']][] = $it;
        }
    }

    foreach ($sales as &$s) {
        $s['subtotal'] = (float)$s['subtotal'];
        $s['discount'] = (float)$s['discount'];
        $s['grand_total'] = (float)$s['grand_total'];
        $s['items'] = $itemsBySale[$s['id']] ?? [];
        $totalCreditSalesAmount += $s['grand_total'];
    }

    // 2. የተፈጸሙ የዱቤ ክፍያዎች (Customer Debt Payments)
    $stmtPayments = $pdo->prepare("
        SELECT 
            p.*,
            fa.name_am as account_name_am,
            fa.code as account_code,
            u.full_name as received_by_name
        FROM payments p
        JOIN financial_accounts fa ON p.account_id = fa.id
        JOIN users u ON p.created_by = u.id
        WHERE (
            (p.transaction_type = 'customer_credit_settlement' AND (p.reference_id LIKE :ref_prefix OR p.reference_id = :cid_str))
            OR
            (p.transaction_type = 'sale' AND p.reference_id IN (
                SELECT uuid FROM sales WHERE customer_id = :cid1
            ))
        )
        ORDER BY p.payment_date DESC, p.id DESC
    ");
    $stmtPayments->execute([
        ':ref_prefix' => 'cust-pay-' . $customer_id . '-%',
        ':cid_str'    => (string)$customer_id,
        ':cid1'       => $customer_id
    ]);
    $payments = $stmtPayments->fetchAll();

    $totalPaymentsReceived = 0.0;
    foreach ($payments as &$py) {
        $py['amount'] = (float)$py['amount'];
        $totalPaymentsReceived += $py['amount'];
    }

    send_success('የደንበኛ ሙሉ የዱቤ ዝርዝር', [
        'customer' => $customer,
        'summary'  => [
            'total_sales_amount'      => round($totalCreditSalesAmount, 2),
            'total_payments_received' => round($totalPaymentsReceived, 2),
            'outstanding_balance'     => $customer['outstanding_balance'],
            'total_orders_count'      => count($sales),
            'total_payments_count'    => count($payments)
        ],
        'sales'    => $sales,
        'payments' => $payments
    ]);
}

// 3. ለአቅራቢ የተናጠል ክፍያ መፈጸም (Make Payment against Supplier Account) — Admin ብቻ
if ($action === 'make_payment') {
    require_admin($user);
    $data = get_request_data();
    $supplier_id = (int)($data['supplier_id'] ?? 0);
    $account_id = (int)($data['account_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0.0);
    $payment_date = $data['payment_date'] ?? date('Y-m-d H:i:s');
    $notes = trim($data['notes'] ?? '');

    if ($supplier_id <= 0 || $account_id <= 0 || $amount <= 0) {
        send_error('እባክዎ አቅራቢ፣ የክፍያ አካውንት እና ትክክለኛ የገንዘብ መጠን ያስገቡ');
    }

    $stmt = $pdo->prepare("SELECT * FROM contacts WHERE id = :id AND type = 'supplier'");
    $stmt->execute([':id' => $supplier_id]);
    $supplier = $stmt->fetch();
    if (!$supplier) {
        send_error('አቅራቢው አልተገኘም');
    }

    $currentDebt = (float)$supplier['outstanding_balance'];
    if ($currentDebt <= 0) {
        send_error('ይህ አቅራቢ ምንም ያልተከፈለ ዕዳ የለበትም');
    }

    // በፋይናንስ አካውንቱ በቂ ገንዘብ መኖሩን ማረጋገጥ
    $stmtBal = $pdo->prepare("SELECT current_balance, name_am FROM financial_accounts WHERE id = :id");
    $stmtBal->execute([':id' => $account_id]);
    $accRow = $stmtBal->fetch();
    $accBal = (float)($accRow['current_balance'] ?? 0.0);
    if ($accBal < $amount) {
        send_error(sprintf('በተመረጠው %s አካውንት ውስጥ በቂ ሂሳብ የለም! ያለው: %s ETB ብቻ ነው', $accRow['name_am'] ?? 'አካውንት', $accBal));
    }

    $ref_id = 'sup-pay-' . $supplier_id . '-' . bin2hex(random_bytes(6));

    $pdo->beginTransaction();
    try {
        // 1. ክፍያውን መመዝገብ (ከፋይናንስ አካውንት ይቀንሳል)
        record_payment(
            $pdo,
            'supplier_debt_settlement',
            $ref_id,
            $account_id,
            $amount,
            $payment_date,
            'ለአቅራቢ ' . $supplier['name'] . ' የተከፈለ ዕዳ: ' . $notes,
            $user['id'],
            false // ወጪ ስለሆነ የፋይናንስ አካውንቱ ይቀንሳል
        );

        // 2. የአቅራቢውን ዕዳ መቀነስ (New balance = Outstanding - Paid)
        $newDebt = max(0.0, round($currentDebt - $amount, 2));
        $stmtDebt = $pdo->prepare("
            UPDATE contacts
            SET outstanding_balance = :new_debt, updated_at = NOW()
            WHERE id = :id
        ");
        $stmtDebt->execute([
            ':new_debt' => $newDebt,
            ':id'       => $supplier_id
        ]);

        $pdo->commit();
        log_audit($user['id'], 'SUPPLIER_PAYMENT', 'contacts', (string)$supplier_id, null, $data);

        send_success('ለአቅራቢው ክፍያው በተሳካ ሁኔታ ተፈጽሟል', [
            'supplier_id'         => $supplier_id,
            'supplier_name'       => $supplier['name'],
            'previous_balance'    => $currentDebt,
            'amount_paid'         => $amount,
            'new_balance'         => $newDebt,
            'payment_reference'   => $ref_id
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ክፍያውን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

// 4. አዲስ እውቂያ መመዝገብ — Admin ብቻ (admin_worker ማየት ብቻ ነው)
if ($action === 'create') {
    if (!check_permission($user, 'contacts_manage')) {
        send_error('አቅራቢ ወይም ደንበኛ ለመመዝገብ ፈቃድ የለዎትም', 403);
    }
    $data = get_request_data();
    $name = trim($data['name'] ?? '');
    $type = $data['type'] ?? 'supplier';
    $phone = trim($data['phone'] ?? '');
    $address = trim($data['address'] ?? '');
    $notes = trim($data['notes'] ?? '');

    if (empty($name) || !in_array($type, ['customer', 'supplier'], true)) {
        send_error('ስም እና ዓይነት (ደንበኛ ወይም አቅራቢ) መምረጥ ግዴታ ነው');
    }

    $uuid = 'cnt-' . bin2hex(random_bytes(8));
    $stmt = $pdo->prepare("
        INSERT INTO contacts (uuid, type, name, phone, address, notes, outstanding_balance, created_at)
        VALUES (:uuid, :type, :name, :phone, :address, :notes, 0.00, NOW())
    ");
    $stmt->execute([
        ':uuid'    => $uuid,
        ':type'    => $type,
        ':name'    => $name,
        ':phone'   => $phone,
        ':address' => $address,
        ':notes'   => $notes
    ]);

    $new_id = (int)$pdo->lastInsertId();
    log_audit($user['id'], 'CONTACT_CREATE', 'contacts', (string)$new_id, null, $data);

    send_success('እውቂያው በተሳካ ሁኔታ ተመዝግቧል', ['contact_id' => $new_id, 'uuid' => $uuid], 201);
}

// 5. የደንበኛ ዕዳ ክፍያ (Customer Credit Settlement) — Admin ብቻ
if ($action === 'settle_debt') {
    require_admin($user);
    $data = get_request_data();
    $contact_id = (int)($data['contact_id'] ?? 0);
    $account_id = (int)($data['account_id'] ?? 0);
    $amount = (float)($data['amount'] ?? 0.0);
    $notes = trim($data['notes'] ?? '');

    if ($contact_id <= 0 || $account_id <= 0 || $amount <= 0) {
        send_error('እውቂያ፣ የክፍያ አካውንት እና መጠን በትክክል ያስገቡ');
    }

    $stmt = $pdo->prepare("SELECT * FROM contacts WHERE id = :id");
    $stmt->execute([':id' => $contact_id]);
    $contact = $stmt->fetch();
    if (!$contact) {
        send_error('እውቂያው አልተገኘም');
    }

    $currentBal = (float)$contact['outstanding_balance'];
    $payment_date = date('Y-m-d H:i:s');
    $ref_id = ($contact['type'] === 'customer')
        ? 'cust-pay-' . $contact_id . '-' . time() . '-' . bin2hex(random_bytes(2))
        : 'sup-pay-' . $contact_id . '-' . time() . '-' . bin2hex(random_bytes(2));

    $pdo->beginTransaction();
    try {
        if ($contact['type'] === 'customer') {
            record_payment(
                $pdo,
                'customer_credit_settlement',
                $ref_id,
                $account_id,
                $amount,
                $payment_date,
                'የደንበኛ ዕዳ ክፍያ ከ ' . $contact['name'] . ': ' . $notes,
                $user['id'],
                true
            );

            $newDebt = max(0.0, round($currentBal - $amount, 2));
            $stmtDebt = $pdo->prepare("
                UPDATE contacts
                SET outstanding_balance = :new_bal, updated_at = NOW()
                WHERE id = :id
            ");
            $stmtDebt->execute([':new_bal' => $newDebt, ':id' => $contact_id]);
        } else {
            record_payment(
                $pdo,
                'supplier_debt_settlement',
                $ref_id,
                $account_id,
                $amount,
                $payment_date,
                'ለአቅራቢ የተከፈለ ዕዳ ለ ' . $contact['name'] . ': ' . $notes,
                $user['id'],
                false
            );

            $newDebt = max(0.0, round($currentBal - $amount, 2));
            $stmtDebt = $pdo->prepare("
                UPDATE contacts
                SET outstanding_balance = :new_bal, updated_at = NOW()
                WHERE id = :id
            ");
            $stmtDebt->execute([':new_bal' => $newDebt, ':id' => $contact_id]);
        }

        $pdo->commit();
        log_audit($user['id'], 'DEBT_SETTLEMENT', 'contacts', (string)$contact_id, null, $data);

        // የአካውንቱን ስም ለማግኘት
        $stmtAcc = $pdo->prepare("SELECT name_am FROM financial_accounts WHERE id = :id");
        $stmtAcc->execute([':id' => $account_id]);
        $accName = $stmtAcc->fetchColumn() ?: 'ጥሬ ገንዘብ';

        send_success('የዕዳ ክፍያው በተሳካ ሁኔታ ተመዝግቧል', [
            'contact_id'        => $contact_id,
            'contact_name'      => $contact['name'],
            'contact_phone'     => $contact['phone'] ?? '',
            'previous_balance'  => $currentBal,
            'amount_paid'       => $amount,
            'new_balance'       => $newDebt,
            'payment_reference' => $ref_id,
            'payment_date'      => $payment_date,
            'account_name_am'   => $accName,
            'notes'             => $notes
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('የዕዳ ክፍያውን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
