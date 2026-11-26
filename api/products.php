<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * የምርቶች እና ክምችት አስተዳደር API (Products & Inventory API)
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

// 1. የምርቶች እና የክምችት ዝርዝር
if ($action === 'list') {
    $category_id = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $search = trim($_GET['search'] ?? '');
    $low_stock_only = !empty($_GET['low_stock_only']) ? true : false;

    $sql = "
        SELECT 
            p.*,
            c.name_en as category_name_en,
            c.name_am as category_name_am,
            COALESCE(SUM(sl.quantity), 0) as total_stock
        FROM products p
        JOIN categories c ON p.category_id = c.id
        LEFT JOIN stock_levels sl ON p.id = sl.product_id
        WHERE p.is_active = 1
    ";
    $params = [];

    if ($category_id) {
        $sql .= " AND p.category_id = :cat_id";
        $params[':cat_id'] = $category_id;
    }
    if (!empty($search)) {
        $sql .= " AND (p.name_am LIKE :srch OR p.name_en LIKE :srch OR c.name_am LIKE :srch OR c.name_en LIKE :srch)";
        $params[':srch'] = '%' . $search . '%';
    }

    $sql .= " GROUP BY p.id, c.id, c.name_en, c.name_am";

    if ($low_stock_only) {
        $sql .= " HAVING total_stock <= p.min_stock_level AND p.min_stock_level > 0";
    }

    $sql .= " ORDER BY p.category_id ASC, p.name_am ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    foreach ($products as &$p) {
        $p['total_stock'] = (float)$p['total_stock'];
        $p['purchase_cost'] = (float)$p['purchase_cost'];
        $p['production_cost'] = (float)$p['production_cost'];
        $p['selling_price'] = (float)$p['selling_price'];
        $p['min_stock_level'] = (float)$p['min_stock_level'];
        $p['is_low_stock'] = ($p['min_stock_level'] > 0 && $p['total_stock'] <= $p['min_stock_level']);
    }

    $categories = $pdo->query("SELECT * FROM categories WHERE type = 'product' ORDER BY name_am ASC")->fetchAll();

    send_success('የምርቶች ዝርዝር', [
        'products'   => $products,
        'categories' => $categories
    ]);
}

// 2. አዲስ ምርት ወይም ጥሬ ዕቃ መመዝገብ (Register Product / Raw Material)
if ($action === 'create') {
    $data = get_request_data();
    $name_am = trim($data['name_am'] ?? '');
    $name_en = trim($data['name_en'] ?? '');
    if (empty($name_en)) {
        $name_en = $name_am;
    }
    $category_id = (int)($data['category_id'] ?? 0);
    $unit = trim($data['unit'] ?? 'piece');
    $purchase_cost = (float)($data['purchase_cost'] ?? 0.0);
    $selling_price = (float)($data['selling_price'] ?? 0.0);
    $min_stock_level = (float)($data['min_stock_level'] ?? 0.0);
    $opening_stock = (float)($data['opening_stock'] ?? 0.0);
    $has_expiry = !empty($data['has_expiry']) ? 1 : 0;

    if (empty($name_am) || $category_id <= 0) {
        send_error('እባክዎ የምርት ስም እና ምድብ በትክክል ያስገቡ');
    }

    $uuid = 'prd-' . bin2hex(random_bytes(8));
    $company_loc_id = 1;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO products (
                uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost,
                selling_price, min_stock_level, source, has_expiry, is_active, created_at
            ) VALUES (
                :uuid, :name_en, :name_am, :cat_id, :unit, :pcost, 0.00,
                :sprice, :min_stock, 'company', :has_exp, 1, NOW()
            )
        ");
        $stmt->execute([
            ':uuid'      => $uuid,
            ':name_en'   => $name_en,
            ':name_am'   => $name_am,
            ':cat_id'    => $category_id,
            ':unit'      => $unit,
            ':pcost'     => $purchase_cost,
            ':sprice'    => $selling_price,
            ':min_stock' => $min_stock_level,
            ':has_exp'   => $has_expiry
        ]);
        $new_id = (int)$pdo->lastInsertId();

        // የመክፈቻ ክምችት ካለው በራስ-ሰር መጨመር
        if ($opening_stock > 0) {
            record_stock_movement(
                $pdo,
                $new_id,
                'opening',
                $opening_stock,
                null,
                $company_loc_id,
                $purchase_cost,
                null,
                'የመክፈቻ ክምችት ምዝገባ (Initial Opening Stock)',
                $user['id']
            );
        }

        $pdo->commit();
        log_audit($user['id'], 'PRODUCT_CREATE', 'products', (string)$new_id, null, $data);

        send_success('ምርቱ በተሳካ ሁኔታ ተመዝግቧል', ['product_id' => $new_id, 'uuid' => $uuid], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ምርቱን መመዝገብ አልተሳካም: ' . $e->getMessage());
    }
}

// 3. የምርት መረጃ ማደስ (Update Product)
if ($action === 'update') {
    $data = get_request_data();
    $id = (int)($data['id'] ?? 0);
    $name_am = trim($data['name_am'] ?? '');
    $name_en = trim($data['name_en'] ?? '');
    if (empty($name_en)) {
        $name_en = $name_am;
    }
    $category_id = (int)($data['category_id'] ?? 0);
    $unit = trim($data['unit'] ?? 'piece');
    $purchase_cost = (float)($data['purchase_cost'] ?? 0.0);
    $selling_price = (float)($data['selling_price'] ?? 0.0);
    $min_stock_level = (float)($data['min_stock_level'] ?? 0.0);

    if ($id <= 0 || empty($name_am) || $category_id <= 0) {
        send_error('ትክክለኛ የምርት መረጃ ያስገቡ');
    }

    $stmt = $pdo->prepare("
        UPDATE products
        SET name_am = :name_am, name_en = :name_en, category_id = :cat_id,
            unit = :unit, purchase_cost = :pcost, selling_price = :sprice,
            min_stock_level = :min_stock, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':name_am'   => $name_am,
        ':name_en'   => $name_en,
        ':cat_id'    => $category_id,
        ':unit'      => $unit,
        ':pcost'     => $purchase_cost,
        ':sprice'    => $selling_price,
        ':min_stock' => $min_stock_level,
        ':id'        => $id
    ]);

    log_audit($user['id'], 'PRODUCT_UPDATE', 'products', (string)$id, null, $data);
    send_success('የምርቱ መረጃ ተሻሽሏል');
}

// 4. የክምችት ማስተካከያ (Manual Stock Adjustment)
if ($action === 'adjust_stock') {
    $data = get_request_data();
    $product_id = (int)($data['product_id'] ?? 0);
    $adjustment_type = $data['adjustment_type'] ?? 'increase'; // 'increase', 'decrease', 'wastage'
    $quantity = (float)($data['quantity'] ?? 0.0);
    $reason = trim($data['reason'] ?? '');

    if ($product_id <= 0 || $quantity <= 0 || empty($reason)) {
        send_error('እባክዎ ምርት፣ መጠን እና የማስተካከያ ምክንያት ማስገባት ግዴታ ነው');
    }

    $company_loc_id = 1;
    $pdo->beginTransaction();
    try {
        $from_loc = ($adjustment_type === 'increase') ? null : $company_loc_id;
        $to_loc   = ($adjustment_type === 'increase') ? $company_loc_id : null;
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
        send_success('የክምችት ማስተካከያው በተሳካ ሁኔታ ተመዝግቧል');
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ማስተካከያው አልተሳካም: ' . $e->getMessage());
    }
}

// 5. የምርት ክምችት ታሪክ (Stock Movement History)
if ($action === 'stock_history') {
    $product_id = (int)($_GET['product_id'] ?? 0);
    if ($product_id <= 0) {
        send_error('የምርት መለያ አልተገለጸም');
    }

    $stmt = $pdo->prepare("
        SELECT sm.*, u.full_name as author_name
        FROM stock_movements sm
        JOIN users u ON sm.authorized_by = u.id
        WHERE sm.product_id = :pid
        ORDER BY sm.created_at DESC, sm.id DESC
        LIMIT 100
    ");
    $stmt->execute([':pid' => $product_id]);
    $movements = $stmt->fetchAll();

    foreach ($movements as &$m) {
        $m['quantity'] = (float)$m['quantity'];
        $m['unit_cost'] = (float)$m['unit_cost'];
        $m['total_cost'] = (float)$m['total_cost'];
    }

    send_success('የክምችት ታሪክ', ['movements' => $movements]);
}

send_error('ያልታወቀ ክንውን', 404);
