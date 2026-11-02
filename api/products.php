<?php
/**
 * የምርቶች አስተዳደር API (Products API)
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

// 1. የምርቶች ዝርዝር (List Products with Categories and Stock Levels)
if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT 
            p.*,
            c.name_en as category_name_en,
            c.name_am as category_name_am,
            COALESCE(SUM(sl.quantity), 0) as total_stock
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN stock_levels sl ON p.id = sl.product_id
        WHERE p.is_active = 1
        GROUP BY p.id
        ORDER BY p.category_id ASC, p.name_am ASC
    ");
    $products = $stmt->fetchAll();

    // የእያንዳንዱ መጋዘን ክምችት ዝርዝር
    $stmtLocations = $pdo->query("
        SELECT sl.product_id, sl.location_id, loc.code as location_code, loc.name_am as location_name_am, sl.quantity
        FROM stock_levels sl
        JOIN stock_locations loc ON sl.location_id = loc.id
    ");
    $locData = $stmtLocations->fetchAll();
    $stockByLoc = [];
    foreach ($locData as $row) {
        $stockByLoc[$row['product_id']][] = $row;
    }

    foreach ($products as &$p) {
        $p['total_stock'] = (float)$p['total_stock'];
        $p['purchase_cost'] = (float)$p['purchase_cost'];
        $p['production_cost'] = (float)$p['production_cost'];
        $p['selling_price'] = (float)$p['selling_price'];
        $p['min_stock_level'] = (float)$p['min_stock_level'];
        $p['stock_by_location'] = $stockByLoc[$p['id']] ?? [];
    }

    // ምድቦች እና መጋዘኖች
    $categories = $pdo->query("SELECT * FROM categories WHERE type = 'product'")->fetchAll();
    $locations = $pdo->query("SELECT * FROM stock_locations")->fetchAll();

    send_success('የምርቶች ዝርዝር', [
        'products'   => $products,
        'categories' => $categories,
        'locations'  => $locations
    ]);
}

// 2. አዲስ ምርት መመዝገብ (Create Product)
// ማስታወሻ: ምርት መመዝገብ በራሱ ክምችት አይጨምርም!
if ($action === 'create') {
    $data = get_request_data();
    $name_en = trim($data['name_en'] ?? '');
    $name_am = trim($data['name_am'] ?? '');
    $category_id = (int)($data['category_id'] ?? 0);
    $unit = $data['unit'] ?? 'piece';
    $purchase_cost = (float)($data['purchase_cost'] ?? 0.0);
    $production_cost = (float)($data['production_cost'] ?? 0.0);
    $selling_price = (float)($data['selling_price'] ?? 0.0);
    $min_stock_level = (float)($data['min_stock_level'] ?? 0.0);
    $source = $data['source'] ?? 'external';
    $has_expiry = !empty($data['has_expiry']) ? 1 : 0;

    if (empty($name_en) || empty($name_am) || $category_id <= 0 || $selling_price < 0) {
        send_error('እባክዎ የምርት ስም (አማርኛና እንግሊዝኛ)፣ ምድብ እና የመሸጫ ዋጋ በትክክል ያስገቡ');
    }

    $uuid = 'prd-' . bin2hex(random_bytes(8));

    $stmt = $pdo->prepare("
        INSERT INTO products (
            uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost,
            selling_price, min_stock_level, source, has_expiry, is_active, created_at
        ) VALUES (
            :uuid, :name_en, :name_am, :cat_id, :unit, :pcost, :prodcost,
            :sprice, :min_stock, :source, :has_exp, 1, NOW()
        )
    ");
    $stmt->execute([
        ':uuid'      => $uuid,
        ':name_en'   => $name_en,
        ':name_am'   => $name_am,
        ':cat_id'    => $category_id,
        ':unit'      => $unit,
        ':pcost'     => $purchase_cost,
        ':prodcost'  => $production_cost,
        ':sprice'    => $selling_price,
        ':min_stock' => $min_stock_level,
        ':source'    => $source,
        ':has_exp'   => $has_expiry
    ]);

    $new_id = (int)$pdo->lastInsertId();
    log_audit($user['id'], 'PRODUCT_CREATE', 'products', (string)$new_id, null, $data);

    send_success('ምርቱ በተሳካ ሁኔታ ተመዝግቧል (ክምችት አልተጨመረም)', ['product_id' => $new_id, 'uuid' => $uuid], 201);
}

// 3. የመክፈቻ ክምችት ማስገቢያ (Opening Stock Entry)
if ($action === 'opening_stock') {
    $data = get_request_data();
    $product_id = (int)($data['product_id'] ?? 0);
    $location_id = (int)($data['location_id'] ?? 0);
    $quantity = (float)($data['quantity'] ?? 0.0);
    $unit_cost = (float)($data['unit_cost'] ?? 0.0);

    if ($product_id <= 0 || $location_id <= 0 || $quantity <= 0) {
        send_error('ትክክለኛ ምርት፣ መጋዘን እና መጠን ያስገቡ');
    }

    $pdo->beginTransaction();
    try {
        record_stock_movement(
            $pdo,
            $product_id,
            'opening',
            $quantity,
            null,
            $location_id,
            $unit_cost,
            null,
            'የመጀመሪያ መክፈቻ ክምችት (Opening Stock)',
            $user['id']
        );

        if ($unit_cost > 0) {
            update_weighted_average_cost($pdo, $product_id, $quantity, $unit_cost);
        }

        $pdo->commit();
        log_audit($user['id'], 'STOCK_OPENING', 'products', (string)$product_id, null, $data);
        send_success('የመክፈቻ ክምችት በተሳካ ሁኔታ ገብቷል');
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('የመክፈቻ ክምችት ምዝገባ አልተሳካም: ' . $e->getMessage());
    }
}

// 4. በእጅ የክምችት ማስተካከያ (Manual Stock Adjustment)
if ($action === 'adjust_stock') {
    if (!check_permission($user, 'can_adjust_stock')) {
        send_error('የክምችት ማስተካከያ የማድረግ ፈቃድ የለዎትም', 403);
    }

    $data = get_request_data();
    $product_id = (int)($data['product_id'] ?? 0);
    $location_id = (int)($data['location_id'] ?? 0);
    $adjustment_type = $data['adjustment_type'] ?? 'increase'; // 'increase' ወይም 'decrease' ወይም 'wastage'
    $quantity = (float)($data['quantity'] ?? 0.0);
    $reason = trim($data['reason'] ?? '');

    if ($product_id <= 0 || $location_id <= 0 || $quantity <= 0 || empty($reason)) {
        send_error('ምርት፣ መጋዘን፣ መጠን እና የማስተካከያ ምክንያት ማስገባት ግዴታ ነው');
    }

    $pdo->beginTransaction();
    try {
        $from_loc = ($adjustment_type === 'increase') ? null : $location_id;
        $to_loc   = ($adjustment_type === 'increase') ? $location_id : null;
        $mov_type = ($adjustment_type === 'wastage') ? 'wastage' : 'adjustment';

        record_stock_movement(
            $pdo,
            $product_id,
            $mov_type,
            $quantity,
            $from_loc,
            $to_loc,
            0.00,
            null,
            $reason,
            $user['id']
        );

        $pdo->commit();
        log_audit($user['id'], 'STOCK_ADJUSTMENT', 'products', (string)$product_id, null, $data);
        send_success('የክምችት ማስተካከያው ተመዝግቧል');
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ማስተካከያው አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
