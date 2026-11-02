<?php
/**
 * ከእርሻ ወደ ሬስቶራንት የውስጥ ዝውውር API (Internal Transfers API)
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

// 1. የዝውውሮች ዝርዝር
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT 
            t.*,
            p.name_am as product_name_am,
            p.name_en as product_name_en,
            from_loc.name_am as from_location_name_am,
            to_loc.name_am as to_location_name_am,
            sender.full_name as sender_name,
            receiver.full_name as receiver_name
        FROM internal_transfers t
        JOIN products p ON t.product_id = p.id
        JOIN stock_locations from_loc ON t.from_location_id = from_loc.id
        JOIN stock_locations to_loc ON t.to_location_id = to_loc.id
        JOIN users sender ON t.sender_user_id = sender.id
        LEFT JOIN users receiver ON t.receiver_user_id = receiver.id
        ORDER BY t.created_at DESC
        LIMIT 50
    ");
    $transfers = $stmt->fetchAll();
    foreach ($transfers as &$tr) {
        $tr['quantity'] = (float)$tr['quantity'];
        $tr['transfer_value'] = (float)$tr['transfer_value'];
    }

    send_success('የውስጥ ዝውውሮች ዝርዝር', ['transfers' => $transfers]);
}

// 2. አዲስ የውስጥ ዝውውር መላክ (Send Internal Transfer)
if ($action === 'create') {
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('trf-' . bin2hex(random_bytes(8)));
    $product_id = (int)($data['product_id'] ?? 0);
    $quantity = (float)($data['quantity'] ?? 0.0);
    $from_location_id = (int)($data['from_location_id'] ?? 1); // የእርሻ መጋዘን
    $to_location_id = (int)($data['to_location_id'] ?? 2);   // የሬስቶራንት ክምችት
    $transfer_date = $data['transfer_date'] ?? date('Y-m-d H:i:s');
    $notes = trim($data['notes'] ?? '');

    if ($product_id <= 0 || $quantity <= 0 || $from_location_id === $to_location_id) {
        send_error('ምርት፣ መጠን፣ እና የተለያዩ መጋዘኖችን በትክክል ይምረጡ');
    }

    // የሚልከው መጋዘን ውስጥ በቂ ክምችት መኖሩን ማረጋገጥ
    $stmtStock = $pdo->prepare("SELECT quantity FROM stock_levels WHERE product_id = :p_id AND location_id = :loc_id");
    $stmtStock->execute([':p_id' => $product_id, ':loc_id' => $from_location_id]);
    $current_avail = (float)($stmtStock->fetchColumn() ?: 0.0);

    if ($current_avail < $quantity) {
        send_error("በቂ ክምችት የለም! በመጋዘኑ ያለው ክምችት: {$current_avail} ብቻ ነው");
    }

    // የምርቱን ዋጋ ማግኘት
    $stmtCost = $pdo->prepare("SELECT purchase_cost, production_cost, unit FROM products WHERE id = :id");
    $stmtCost->execute([':id' => $product_id]);
    $prod = $stmtCost->fetch();
    $unit = $prod['unit'] ?? 'piece';
    $cost = (float)$prod['production_cost'] > 0 ? (float)$prod['production_cost'] : (float)$prod['purchase_cost'];
    $transfer_value = round($quantity * $cost, 2);

    $stmt = $pdo->prepare("
        INSERT INTO internal_transfers (
            uuid, product_id, quantity, unit, transfer_value,
            from_location_id, to_location_id, transfer_date, status,
            notes, sender_user_id, created_at
        ) VALUES (
            :uuid, :prod_id, :qty, :unit, :val,
            :from_loc, :to_loc, :tdate, 'pending',
            :notes, :sender_id, NOW()
        )
    ");
    $stmt->execute([
        ':uuid'       => $uuid,
        ':prod_id'    => $product_id,
        ':qty'        => $quantity,
        ':unit'       => $unit,
        ':val'        => $transfer_value,
        ':from_loc'   => $from_location_id,
        ':to_loc'     => $to_location_id,
        ':tdate'      => $transfer_date,
        ':notes'      => $notes,
        ':sender_id'  => $user['id']
    ]);
    $transfer_id = (int)$pdo->lastInsertId();

    log_audit($user['id'], 'TRANSFER_DISPATCH', 'internal_transfers', (string)$transfer_id, null, $data);

    send_success('ዝውውሩ ተልኳል፤ ተቀባዩ እስኪያረጋግጥ ይጠብቃል (Pending Receipt)', [
        'transfer_id' => $transfer_id,
        'uuid'        => $uuid
    ], 201);
}

// 3. ዝውውር መቀበል እና ማረጋገጥ (Receive & Confirm Transfer)
if ($action === 'receive') {
    $data = get_request_data();
    $transfer_id = (int)($data['transfer_id'] ?? 0);

    if ($transfer_id <= 0) {
        send_error('ትክክለኛ የዝውውር መለያ አልተገለጸም');
    }

    $stmt = $pdo->prepare("SELECT * FROM internal_transfers WHERE id = :id");
    $stmt->execute([':id' => $transfer_id]);
    $tr = $stmt->fetch();

    if (!$tr || $tr['status'] !== 'pending') {
        send_error('ይህ ዝውውር አልተገኘም ወይም ቀድሞ ተጠናቋል');
    }

    $pdo->beginTransaction();
    try {
        // 1. ከላኪው መጋዘን ክምችት መቀነስ (Transfer Out)
        record_stock_movement(
            $pdo,
            (int)$tr['product_id'],
            'transfer_out',
            (float)$tr['quantity'],
            (int)$tr['from_location_id'],
            null,
            (float)$tr['transfer_value'] / (float)$tr['quantity'],
            $tr['uuid'],
            'ከእርሻ ወደ ሬስቶራንት የተላከ ዝውውር #' . $tr['id'],
            $user['id']
        );

        // 2. ወደ ተቀባዩ መጋዘን ክምችት መጨመር (Transfer In)
        record_stock_movement(
            $pdo,
            (int)$tr['product_id'],
            'transfer_in',
            (float)$tr['quantity'],
            null,
            (int)$tr['to_location_id'],
            (float)$tr['transfer_value'] / (float)$tr['quantity'],
            $tr['uuid'],
            'ከእርሻ ወደ ሬስቶራንት የገባ ዝውውር #' . $tr['id'],
            $user['id']
        );

        // 3. የዝውውሩን ሁኔታ ወደ 'received' መቀየር
        $stmtUpd = $pdo->prepare("
            UPDATE internal_transfers
            SET status = 'received', receiver_user_id = :rec_id, received_at = NOW()
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':rec_id' => $user['id'],
            ':id'     => $transfer_id
        ]);

        $pdo->commit();
        log_audit($user['id'], 'TRANSFER_RECEIVE', 'internal_transfers', (string)$transfer_id, $tr, ['status' => 'received']);

        send_success('ዝውውሩ ተቀባይነት አግኝቷል፤ የእርሻ ክምችት ቀንሶ የሬስቶራንት ክምችት ጨምሯል');
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ዝውውሩን መቀበል አልተሳካም: ' . $e->getMessage());
    }
}

// 4. ዝውውር ውድቅ ማድረግ (Reject Transfer)
if ($action === 'reject') {
    $data = get_request_data();
    $transfer_id = (int)($data['transfer_id'] ?? 0);
    $reason = trim($data['reason'] ?? 'ተቀባይነት አላገኘም');

    $stmt = $pdo->prepare("UPDATE internal_transfers SET status = 'rejected', notes = CONCAT(COALESCE(notes,''), ' | ውድቅ የተደረገበት ምክንያት: ', :reason) WHERE id = :id AND status = 'pending'");
    $stmt->execute([':reason' => $reason, ':id' => $transfer_id]);

    log_audit($user['id'], 'TRANSFER_REJECT', 'internal_transfers', (string)$transfer_id, null, ['reason' => $reason]);
    send_success('ዝውውሩ ውድቅ ተደርጓል');
}

send_error('ያልታወቀ ክንውን', 404);
