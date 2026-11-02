<?php
/**
 * የእርሻ እና ወተት ምርት ማቀነባበሪያ API (Farm & Dairy Production API)
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

// 1. የምርት መዝገቦች ዝርዝር
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT 
            fp.*,
            p.name_am as product_name_am,
            p.name_en as product_name_en,
            loc.name_am as location_name_am,
            u.full_name as created_by_name
        FROM farm_productions fp
        JOIN products p ON fp.product_id = p.id
        JOIN stock_locations loc ON fp.destination_location_id = loc.id
        JOIN users u ON fp.created_by = u.id
        ORDER BY fp.production_date DESC, fp.id DESC
        LIMIT 50
    ");
    $productions = $stmt->fetchAll();
    foreach ($productions as &$pr) {
        $pr['quantity'] = (float)$pr['quantity'];
        $pr['production_cost'] = (float)$pr['production_cost'];
    }

    // የእርሻ ምርቶች ዝርዝር (Dairy and Farm Produce products)
    $farmProducts = $pdo->query("
        SELECT id, name_am, name_en, unit, production_cost, has_expiry
        FROM products
        WHERE source = 'farm' AND is_active = 1
    ")->fetchAll();

    send_success('የእርሻ ምርቶች ዝርዝር', [
        'productions'   => $productions,
        'farm_products' => $farmProducts
    ]);
}

// 2. አዲስ ምርት መመዝገብ (Record Dairy/Farm Production)
if ($action === 'create') {
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('fprd-' . bin2hex(random_bytes(8)));
    $product_id = (int)($data['product_id'] ?? 0);
    $quantity = (float)($data['quantity'] ?? 0.0);
    $production_cost = (float)($data['production_cost'] ?? 0.0);
    $production_date = $data['production_date'] ?? date('Y-m-d');
    $expiry_date = !empty($data['expiry_date']) ? $data['expiry_date'] : null;
    $batch_number = trim($data['batch_number'] ?? ('BATCH-' . date('Ymd-Hi')));
    $destination_location_id = (int)($data['destination_location_id'] ?? 1); // የእርሻ መጋዘን

    if ($product_id <= 0 || $quantity <= 0) {
        send_error('ትክክለኛ ምርት እና የተመረተ መጠን ያስገቡ');
    }

    $stmtProd = $pdo->prepare("SELECT unit, production_cost FROM products WHERE id = :id");
    $stmtProd->execute([':id' => $product_id]);
    $prod = $stmtProd->fetch();
    $unit = $prod['unit'] ?? 'liter';
    if ($production_cost <= 0) {
        $production_cost = (float)($prod['production_cost'] ?? 0.0);
    }

    // በአይደምፖተንሲ ቁልፍ ማረጋገጥ
    $stmtCheck = $pdo->prepare("SELECT id FROM farm_productions WHERE uuid = :uuid");
    $stmtCheck->execute([':uuid' => $uuid]);
    if ($existing = $stmtCheck->fetch()) {
        send_success('ይህ የምርት መዝገብ ቀድሞ ገብቷል (Idempotent)', ['production_id' => $existing['id'], 'uuid' => $uuid]);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO farm_productions (
                uuid, product_id, quantity, unit, production_cost,
                production_date, expiry_date, batch_number, destination_location_id,
                created_by, created_at
            ) VALUES (
                :uuid, :prod_id, :qty, :unit, :cost,
                :pdate, :exp, :batch, :dest_loc,
                :created_by, NOW()
            )
        ");
        $stmt->execute([
            ':uuid'       => $uuid,
            ':prod_id'    => $product_id,
            ':qty'        => $quantity,
            ':unit'       => $unit,
            ':cost'       => $production_cost,
            ':pdate'      => $production_date,
            ':exp'        => $expiry_date,
            ':batch'      => $batch_number,
            ':dest_loc'   => $destination_location_id,
            ':created_by' => $user['id']
        ]);
        $production_id = (int)$pdo->lastInsertId();

        // የእርሻ መጋዘን ክምችት መጨመር (Stock Production)
        record_stock_movement(
            $pdo,
            $product_id,
            'production',
            $quantity,
            null,
            $destination_location_id,
            $production_cost,
            $uuid,
            'የእርሻ/ወተት ምርት ባች: ' . $batch_number,
            $user['id']
        );

        $pdo->commit();
        log_audit($user['id'], 'FARM_PRODUCTION_CREATE', 'farm_productions', (string)$production_id, null, $data);

        send_success('የእርሻ/ወተት ምርቱ ተመዝግቦ ወደ መጋዘን ገብቷል', [
            'production_id' => $production_id,
            'uuid'          => $uuid,
            'quantity'      => $quantity,
            'unit'          => $unit
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ምርቱን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
