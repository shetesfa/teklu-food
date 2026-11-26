<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * እቃ መረከቢያ እና ግዢ አስተዳደር API (Goods Receiving & Purchases API)
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

// 1. የርክክቦች ዝርዝር
if ($action === 'list') {
    $supplier_id = !empty($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : null;
    $status = $_GET['status'] ?? null; // 'all', 'paid', 'partial', 'credit'
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;

    $sql = "
        SELECT 
            p.*,
            c.name as supplier_name,
            c.phone as supplier_phone,
            u.full_name as receiver_name,
            fa.name_am as payment_account_name_am
        FROM purchases p
        LEFT JOIN contacts c ON p.supplier_id = c.id
        LEFT JOIN financial_accounts fa ON p.payment_account_id = fa.id
        JOIN users u ON p.created_by = u.id
        WHERE 1=1
    ";
    $params = [];

    if ($supplier_id) {
        $sql .= " AND p.supplier_id = :sid";
        $params[':sid'] = $supplier_id;
    }
    if ($status && in_array($status, ['paid', 'partial', 'credit'], true)) {
        $sql .= " AND p.payment_status = :st";
        $params[':st'] = $status;
    }
    if ($start_date && $end_date) {
        $sql .= " AND p.purchase_date BETWEEN :sd AND :ed";
        $params[':sd'] = $start_date;
        $params[':ed'] = $end_date;
    }

    $sql .= " ORDER BY p.purchase_date DESC, p.id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $purchases = $stmt->fetchAll();

    $purchaseIds = array_column($purchases, 'id');
    $itemsByPurchase = [];

    if (!empty($purchaseIds)) {
        $placeholders = implode(',', array_fill(0, count($purchaseIds), '?'));
        $stmtItems = $pdo->prepare("
            SELECT pi.*, pr.name_am as product_name_am, pr.name_en as product_name_en, pr.unit as product_unit
            FROM purchase_items pi
            JOIN products pr ON pi.product_id = pr.id
            WHERE pi.purchase_id IN ($placeholders)
        ");
        $stmtItems->execute($purchaseIds);
        foreach ($stmtItems->fetchAll() as $it) {
            $it['quantity'] = (float)$it['quantity'];
            $it['unit_price'] = (float)$it['unit_price'];
            $it['total_price'] = (float)$it['total_price'];
            $itemsByPurchase[$it['purchase_id']][] = $it;
        }
    }

    foreach ($purchases as &$p) {
        $p['total_amount'] = (float)$p['total_amount'];
        $p['additional_cost'] = (float)$p['additional_cost'];
        $p['grand_total'] = (float)$p['grand_total'];
        $p['paid_amount'] = (float)$p['paid_amount'];
        $p['unpaid_balance'] = (float)$p['unpaid_balance'];
        $p['items'] = $itemsByPurchase[$p['id']] ?? [];
    }

    send_success('የርክክቦች ዝርዝር', ['purchases' => $purchases]);
}

// 2. አዲስ እቃ መረከቢያ መመዝገብ (Receive Goods from Supplier) — worker አይችልም
if ($action === 'create') {
    if (!check_permission($user, 'receiving')) {
        send_error('እቃ ለመረከብ ፈቃድ የለዎትም', 403);
    }
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('pur-' . bin2hex(random_bytes(8)));
    $supplier_id = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;
    $purchase_date = $data['purchase_date'] ?? date('Y-m-d H:i:s');
    $additional_cost = (float)($data['additional_cost'] ?? 0.0);
    $paid_amount = (float)($data['paid_amount'] ?? 0.0);
    $payment_account_id = !empty($data['payment_account_id']) ? (int)$data['payment_account_id'] : null;
    $notes = trim($data['notes'] ?? '');
    $items = $data['items'] ?? [];

    // አዲስ አቅራቢ እዚያው ለመመዝገብ ከፈለገ
    if (empty($supplier_id) && !empty($data['new_supplier_name'])) {
        $sup_name = trim($data['new_supplier_name']);
        $sup_phone = trim($data['new_supplier_phone'] ?? '');
        $sup_address = trim($data['new_supplier_address'] ?? '');
        $sup_notes = trim($data['new_supplier_notes'] ?? '');

        $sup_uuid = 'sup-' . bin2hex(random_bytes(8));
        $stmtSup = $pdo->prepare("
            INSERT INTO contacts (uuid, type, name, phone, address, notes, outstanding_balance, created_at)
            VALUES (:uuid, 'supplier', :name, :phone, :address, :notes, 0.00, NOW())
        ");
        $stmtSup->execute([
            ':uuid'    => $sup_uuid,
            ':name'    => $sup_name,
            ':phone'   => $sup_phone,
            ':address' => $sup_address,
            ':notes'   => $sup_notes
        ]);
        $supplier_id = (int)$pdo->lastInsertId();
    }

    if (empty($items) || !is_array($items)) {
        send_error('ቢያንስ አንድ የተረከቡት ዕቃ ማስገባት ያስፈልጋል');
    }

    // የንዑስ ድምር ስሌት
    $total_items_amount = 0.0;
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        if ($qty <= 0 || $price < 0) {
            send_error('የተረከቡት ዕቃ መጠን ወይም ዋጋ ትክክል አይደለም');
        }
        $total_items_amount += round($qty * $price, 2);
    }
    $grand_total = round($total_items_amount + $additional_cost, 2);

    // የክፍያ እና የዕዳ ስሌት
    $unpaid_balance = max(0.0, round($grand_total - $paid_amount, 2));

    $payment_status = 'credit';
    if ($paid_amount >= $grand_total) {
        $payment_status = 'paid';
    } elseif ($paid_amount > 0) {
        $payment_status = 'partial';
    }

    if ($paid_amount > 0 && empty($payment_account_id)) {
        send_error('የተከፈለ ገንዘብ ካለ የተከፈለበትን የፋይናንስ አካውንት መምረጥ ግዴታ ነው');
    }

    if ($unpaid_balance > 0 && empty($supplier_id)) {
        send_error('ያልተከፈለ ዕዳ ያለበትን እቃ ለመረከብ አቅራቢ መምረጥ ወይም መመዝገብ ግዴታ ነው');
    }

    // አይደምፖተንሲ ምርመራ
    $stmtCheck = $pdo->prepare("SELECT id FROM purchases WHERE uuid = :uuid");
    $stmtCheck->execute([':uuid' => $uuid]);
    if ($existing = $stmtCheck->fetch()) {
        send_success('ይህ እቃ ርክክብ ቀድሞ ተመዝግቧል (Idempotent)', ['purchase_id' => $existing['id'], 'uuid' => $uuid]);
    }

    $company_loc_id = 1; // ማዕከላዊ የድርጅት ክምችት

    $pdo->beginTransaction();
    try {
        // 1. ዋናውን የእቃ ርክክብ መዝገብ ማስገባት
        $stmtPur = $pdo->prepare("
            INSERT INTO purchases (
                uuid, supplier_id, purchase_date, total_amount, additional_cost,
                grand_total, paid_amount, unpaid_balance, payment_status, payment_account_id,
                notes, created_by, created_at
            ) VALUES (
                :uuid, :sup_id, :pdate, :tot, :add_cost,
                :grand, :paid, :unpaid, :p_status, :acc_id,
                :notes, :created_by, NOW()
            )
        ");
        $stmtPur->execute([
            ':uuid'       => $uuid,
            ':sup_id'     => $supplier_id,
            ':pdate'      => $purchase_date,
            ':tot'        => $total_items_amount,
            ':add_cost'   => $additional_cost,
            ':grand'      => $grand_total,
            ':paid'       => $paid_amount,
            ':unpaid'     => $unpaid_balance,
            ':p_status'   => $payment_status,
            ':acc_id'     => $payment_account_id,
            ':notes'      => $notes,
            ':created_by' => $user['id']
        ]);
        $purchase_id = (int)$pdo->lastInsertId();

        // 2. የተረከቡትን ዕቃዎች ማስገባትና ክምችት መጨመር
        foreach ($items as $item) {
            $item_uuid = bin2hex(random_bytes(16));
            $product_id = (int)$item['product_id'];
            $qty = (float)$item['quantity'];
            $unit = $item['unit'] ?? 'piece';
            $unit_price = (float)$item['unit_price'];
            $item_total = round($qty * $unit_price, 2);
            $expiry_date = !empty($item['expiry_date']) ? $item['expiry_date'] : null;

            // Purchase item ማስገባት
            $stmtItem = $pdo->prepare("
                INSERT INTO purchase_items (uuid, purchase_id, product_id, location_id, quantity, unit, unit_price, total_price, expiry_date)
                VALUES (:uuid, :p_id, :prod_id, :loc_id, :qty, :unit, :u_price, :tot, :exp)
            ");
            $stmtItem->execute([
                ':uuid'    => $item_uuid,
                ':p_id'    => $purchase_id,
                ':prod_id' => $product_id,
                ':loc_id'  => $company_loc_id,
                ':qty'     => $qty,
                ':unit'    => $unit,
                ':u_price' => $unit_price,
                ':tot'     => $item_total,
                ':exp'     => $expiry_date
            ]);

            // ክምችት መጨመር
            record_stock_movement(
                $pdo,
                $product_id,
                'purchase',
                $qty,
                null,
                $company_loc_id,
                $unit_price,
                $uuid,
                'እቃ መረከቢያ (Goods Received) #' . $purchase_id,
                $user['id']
            );

            // የተስተካከለ አማካይ ዋጋ ማደስ
            update_weighted_average_cost($pdo, $product_id, $qty, $unit_price);
        }

        // 3. የተከፈለ ገንዘብ ካለ ክፍያ መመዝገብ (የፋይናንስ አካውንት ይቀንሳል)
        if ($paid_amount > 0 && $payment_account_id) {
            record_payment(
                $pdo,
                'purchase',
                $uuid,
                $payment_account_id,
                $paid_amount,
                $purchase_date,
                'የእቃ መረከቢያ ክፍያ #' . $purchase_id,
                $user['id'],
                false // ወጪ ስለሆነ የፋይናንስ ሂሳቡ ይቀንሳል
            );
        }

        // 4. የቀረ ዕዳ ካለ የአቅራቢውን ጠቅላላ ዕዳ መጨመር (ቀድሞ የነበረው ሳይሰረዝ ይደመራል)
        if ($unpaid_balance > 0 && $supplier_id !== null) {
            $stmtDebt = $pdo->prepare("
                UPDATE contacts
                SET outstanding_balance = outstanding_balance + :bal, updated_at = NOW()
                WHERE id = :s_id
            ");
            $stmtDebt->execute([
                ':bal'  => $unpaid_balance,
                ':s_id' => $supplier_id
            ]);
        }

        $pdo->commit();
        log_audit($user['id'], 'GOODS_RECEIVE', 'purchases', (string)$purchase_id, null, $data);

        // የአቅራቢውን አዲስ ጠቅላላ ዕዳ ማምጣት
        $currentSupplierDebt = 0.0;
        if ($supplier_id) {
            $stmtCurr = $pdo->prepare("SELECT outstanding_balance FROM contacts WHERE id = :id");
            $stmtCurr->execute([':id' => $supplier_id]);
            $currentSupplierDebt = (float)$stmtCurr->fetchColumn();
        }

        send_success('እቃው በተሳካ ሁኔታ ተረክቧል፤ ክምችት ጨምሯል', [
            'purchase_id'           => $purchase_id,
            'uuid'                  => $uuid,
            'grand_total'           => $grand_total,
            'paid_amount'           => $paid_amount,
            'unpaid_balance'        => $unpaid_balance,
            'payment_status'        => $payment_status,
            'supplier_total_debt'   => $currentSupplierDebt
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('እቃውን መረከብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
