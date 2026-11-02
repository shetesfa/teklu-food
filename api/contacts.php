<?php
/**
 * ደንበኞች እና አቅራቢዎች API (Contacts API)
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

// 1. ዝርዝር
if ($action === 'list') {
    $type = $_GET['type'] ?? null;
    $sql = "SELECT * FROM contacts";
    $params = [];
    if ($type && in_array($type, ['customer', 'supplier'], true)) {
        $sql .= " WHERE type = :type";
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

// 2. አዲስ መመዝገብ
if ($action === 'create') {
    $data = get_request_data();
    $name = trim($data['name'] ?? '');
    $type = $data['type'] ?? 'customer';
    $phone = trim($data['phone'] ?? '');
    $address = trim($data['address'] ?? '');

    if (empty($name) || !in_array($type, ['customer', 'supplier'], true)) {
        send_error('ስም እና ዓይነት (ደንበኛ ወይም አቅራቢ) መምረጥ ግዴታ ነው');
    }

    $uuid = 'cnt-' . bin2hex(random_bytes(8));
    $stmt = $pdo->prepare("
        INSERT INTO contacts (uuid, type, name, phone, address, outstanding_balance, created_at)
        VALUES (:uuid, :type, :name, :phone, :address, 0.00, NOW())
    ");
    $stmt->execute([
        ':uuid'    => $uuid,
        ':type'    => $type,
        ':name'    => $name,
        ':phone'   => $phone,
        ':address' => $address
    ]);

    $new_id = (int)$pdo->lastInsertId();
    log_audit($user['id'], 'CONTACT_CREATE', 'contacts', (string)$new_id, null, $data);

    send_success('እውቂያው በተሳካ ሁኔታ ተመዝግቧል', ['contact_id' => $new_id, 'uuid' => $uuid], 201);
}

// 3. የዕዳ ክፍያ ማስተናገድ (Debt Settlement)
if ($action === 'settle_debt') {
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

    $uuid = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try {
        if ($contact['type'] === 'customer') {
            // ደንበኛ ዕዳውን ሲከፍል -> ወደ ድርጅቱ ገንዘብ ይገባል (Inflow)፣ የደንበኛው ዕዳ ይቀንሳል
            record_payment(
                $pdo,
                'customer_credit_settlement',
                $uuid,
                $account_id,
                $amount,
                date('Y-m-d H:i:s'),
                'የደንበኛ ዕዳ ክፍያ ከ ' . $contact['name'] . ': ' . $notes,
                $user['id'],
                true
            );

            $stmtDebt = $pdo->prepare("
                UPDATE contacts
                SET outstanding_balance = GREATEST(0, outstanding_balance - :amt), updated_at = NOW()
                WHERE id = :id
            ");
            $stmtDebt->execute([':amt' => $amount, ':id' => $contact_id]);
        } else {
            // ለአቅራቢ ዕዳ ሲከፈል -> ከድርጅቱ ገንዘብ ይወጣል (Outflow)፣ የአቅራቢው ዕዳ ይቀንሳል
            record_payment(
                $pdo,
                'supplier_debt_settlement',
                $uuid,
                $account_id,
                $amount,
                date('Y-m-d H:i:s'),
                'ለአቅራቢ የተከፈለ ዕዳ ለ ' . $contact['name'] . ': ' . $notes,
                $user['id'],
                false
            );

            $stmtDebt = $pdo->prepare("
                UPDATE contacts
                SET outstanding_balance = GREATEST(0, outstanding_balance - :amt), updated_at = NOW()
                WHERE id = :id
            ");
            $stmtDebt->execute([':amt' => $amount, ':id' => $contact_id]);
        }

        $pdo->commit();
        log_audit($user['id'], 'DEBT_SETTLEMENT', 'contacts', (string)$contact_id, null, $data);

        send_success('የዕዳ ክፍያው በተሳካ ሁኔታ ተመዝግቧል');
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('የዕዳ ክፍያውን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
