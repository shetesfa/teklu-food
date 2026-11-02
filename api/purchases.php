<?php
/**
 * የግዢ አስተዳደር API (Purchases API)
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

// 1. የግዢዎች ዝርዝር
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT 
            p.*,
            c.name as supplier_name,
            u.full_name as created_by_name
        FROM purchases p
        LEFT JOIN contacts c ON p.supplier_id = c.id
        JOIN users u ON p.created_by = u.id
        ORDER BY p.purchase_date DESC, p.id DESC
        LIMIT 50
    ");
    $purchases = $stmt->fetchAll();

    // የእያንዳንዱ ግዢ እቃዎች
    $stmtItems = $pdo->query("
        SELECT pi.*, pr.name_am as product_name_am, pr.name_en as product_name_en, loc.name_am as location_name_am
        FROM purchase_items pi
        JOIN products pr ON pi.product_id = pr.id
        JOIN stock_locations loc ON pi.location_id = loc.id
    ");
    $items = $stmtItems->fetchAll();
    $itemsByPurchase = [];
    foreach ($items as $it) {
        $itemsByPurchase[$it['purchase_id']][] = $it;
    }

    foreach ($purchases as &$p) {
        $p['total_amount'] = (float)$p['total_amount'];
        $p['additional_cost'] = (float)$p['additional_cost'];
        $p['grand_total'] = (float)$p['grand_total'];
        $p['items'] = $itemsByPurchase[$p['id']] ?? [];
    }

    send_success('የግዢዎች ዝርዝር', ['purchases' => $purchases]);
}

// 2. አዲስ ግዢ መመዝገብ
if ($action === 'create') {
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('pur-' . bin2hex(random_bytes(8)));
    $supplier_id = !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null;
    $purchase_date = $data['purchase_date'] ?? date('Y-m-d');
    $additional_cost = (float)($data['additional_cost'] ?? 0.0);
    $notes = trim($data['notes'] ?? '');
    $items = $data['items'] ?? [];
    $payments = $data['payments'] ?? []; // [{'account_id': 1, 'amount': 500}]

    if (empty($items) || !is_array($items)) {
        send_error('ቢያንስ አንድ የተገዛ ዕቃ ማስገባት ያስፈልጋል');
    }

    // የንኡስ ድምር ስሌት
    $total_items_amount = 0.0;
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        if ($qty <= 0 || $price < 0) {
            send_error('የተገዛው ዕቃ መጠን ወይም ዋጋ ትክክል አይደለም');
        }
        $total_items_amount += ($qty * $price);
    }
    $grand_total = $total_items_amount + $additional_cost;

    // የተከፈለ ድምር
    $total_paid = 0.0;
    foreach ($payments as $pay) {
        $p_amt = (float)($pay['amount'] ?? 0);
        if ($p_amt > 0) {
            $total_paid += $p_amt;
        }
    }

    $payment_status = 'credit';
    if ($total_paid >= $grand_total) {
        $payment_status = 'paid';
    } elseif ($total_paid > 0) {
        $payment_status = 'partial';
    }

    $unpaid_balance = max(0.0, $grand_total - $total_paid);

    // በአይደምፖተንሲ ቁልፍ ማረጋገጥ (Idempotency Check)
    $stmtCheck = $pdo->prepare("SELECT id FROM purchases WHERE uuid = :uuid");
    $stmtCheck->execute([':uuid' => $uuid]);
    if ($existing = $stmtCheck->fetch()) {
        send_success('ይህ ግዢ ቀድሞ ተመዝግቧል (Idempotent)', ['purchase_id' => $existing['id'], 'uuid' => $uuid]);
    }

    $pdo->beginTransaction();
    try {
        // 1. ዋናውን የግዢ መዝገብ ማስገባት
        $stmtPur = $pdo->prepare("
            INSERT INTO purchases (
                uuid, supplier_id, purchase_date, total_amount, additional_cost,
                grand_total, payment_status, notes, created_by, created_at
            ) VALUES (
                :uuid, :sup_id, :pdate, :tot, :add_cost,
                :grand, :p_status, :notes, :created_by, NOW()
            )
        ");
        $stmtPur->execute([
            ':uuid'       => $uuid,
            ':sup_id'     => $supplier_id,
            ':pdate'      => $purchase_date,
            ':tot'        => $total_items_amount,
            ':add_cost'   => $additional_cost,
            ':grand'      => $grand_total,
            ':p_status'   => $payment_status,
            ':notes'      => $notes,
            ':created_by' => $user['id']
        ]);
        $purchase_id = (int)$pdo->lastInsertId();

        // 2. የተገዙትን ዕቃዎች ማስገባትና ክምችት መጨመር
        foreach ($items as $item) {
            $item_uuid = bin2hex(random_bytes(16));
            $product_id = (int)$item['product_id'];
            $location_id = (int)($item['location_id'] ?? 1); // ነባሪ: የእርሻ መጋዘን ወይም ዋና ማከማቻ
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
                ':loc_id'  => $location_id,
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
                $location_id,
                $unit_price,
                $uuid,
                'የውጭ ግዢ (External Purchase)',
                $user['id']
            );

            // የተስተካከለ አማካይ ዋጋ ማደስ
            update_weighted_average_cost($pdo, $product_id, $qty, $unit_price);
        }

        // 3. ክፍያዎችን መመዝገብ እና የፋይናንስ ሂሳቦችን መቀነስ
        foreach ($payments as $pay) {
            $p_amt = (float)($pay['amount'] ?? 0);
            $acc_id = (int)($pay['account_id'] ?? 0);
            if ($p_amt > 0 && $acc_id > 0) {
                record_payment(
                    $pdo,
                    'purchase',
                    $uuid,
                    $acc_id,
                    $p_amt,
                    $purchase_date . ' ' . date('H:i:s'),
                    'የግዢ ክፍያ #' . $purchase_id,
                    $user['id'],
                    false // ወጪ ስለሆነ የፋይናንስ ሂሳቡ ይቀንሳል
                );
            }
        }

        // 4. የቀረ ዕዳ ካለ የአቅራቢውን balance መጨመር
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
        log_audit($user['id'], 'PURCHASE_CREATE', 'purchases', (string)$purchase_id, null, $data);

        send_success('ግዢው በተሳካ ሁኔታ ተመዝግቧል', [
            'purchase_id'    => $purchase_id,
            'uuid'           => $uuid,
            'grand_total'    => $grand_total,
            'payment_status' => $payment_status,
            'unpaid_balance' => $unpaid_balance
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ግዢውን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
