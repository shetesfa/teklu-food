<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * የምግብ ዝርዝር እና ማዘጋጃ API (Food Menu & Food Preparation API)
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

// 1. የምግቦች እና የሬሲፒዎች ዝርዝር
if ($action === 'list') {
    // ሬሲፒ ያላቸው ምግቦች ወይም በ'የተዘጋጁ ምግቦች' ምድብ ውስጥ ያሉ
    $stmtFoods = $pdo->query("
        SELECT p.*, c.name_am as category_name_am, c.name_en as category_name_en
        FROM products p
        JOIN categories c ON p.category_id = c.id
        WHERE p.is_active = 1 AND (
            p.category_id = 4 
            OR EXISTS (SELECT 1 FROM recipes r WHERE r.parent_product_id = p.id)
        )
        ORDER BY p.name_am ASC
    ");
    $foods = $stmtFoods->fetchAll();

    // ሬሲፒዎችን ማምጣት
    $stmtRecipes = $pdo->query("
        SELECT 
            r.*,
            ing.name_am as ingredient_name_am,
            ing.name_en as ingredient_name_en,
            ing.unit as ingredient_unit,
            ing.purchase_cost as ingredient_purchase_cost,
            COALESCE((SELECT SUM(sl.quantity) FROM stock_levels sl WHERE sl.product_id = ing.id), 0) as current_stock
        FROM recipes r
        JOIN products ing ON r.ingredient_product_id = ing.id
        ORDER BY r.parent_product_id ASC, ing.name_am ASC
    ");
    $allRecipes = $stmtRecipes->fetchAll();
    $recipesByParent = [];
    foreach ($allRecipes as $r) {
        $r['quantity_needed'] = (float)$r['quantity_needed'];
        $r['ingredient_purchase_cost'] = (float)$r['ingredient_purchase_cost'];
        $r['current_stock'] = (float)$r['current_stock'];
        $r['line_cost'] = round($r['quantity_needed'] * $r['ingredient_purchase_cost'], 2);
        $recipesByParent[$r['parent_product_id']][] = $r;
    }

    $formattedFoods = [];
    foreach ($foods as $f) {
        $fid = (int)$f['id'];
        $ings = $recipesByParent[$fid] ?? [];
        $sellingPrice = (float)$f['selling_price'];
        
        $costPerServing = 0.0;
        $maxServings = null;

        foreach ($ings as $ing) {
            $costPerServing += $ing['line_cost'];
            if ($ing['quantity_needed'] > 0) {
                $possible = floor($ing['current_stock'] / $ing['quantity_needed']);
                if ($maxServings === null || $possible < $maxServings) {
                    $maxServings = (int)$possible;
                }
            }
        }
        if ($maxServings === null) {
            $maxServings = 0;
        }

        $estimatedProfit = max(0.0, round($sellingPrice - $costPerServing, 2));

        $formattedFoods[] = [
            'id'                 => $fid,
            'uuid'               => $f['uuid'],
            'name_am'            => $f['name_am'],
            'name_en'            => $f['name_en'],
            'category_id'        => (int)$f['category_id'],
            'category_name_am'   => $f['category_name_am'],
            'category_name_en'   => $f['category_name_en'],
            'unit'               => $f['unit'],
            'selling_price'      => $sellingPrice,
            'cost_per_serving'   => round($costPerServing, 2),
            'estimated_profit'   => $estimatedProfit,
            'max_servings'       => max(0, $maxServings),
            'ingredients'        => $ings,
            'ingredients_count'  => count($ings)
        ];
    }

    // ለግብዓትነት የሚሆኑ የተመዘገቡ ምርቶች (ጥሬ እቃዎች)
    $stmtIngredients = $pdo->query("
        SELECT p.id, p.uuid, p.name_am, p.name_en, p.unit, p.purchase_cost,
               COALESCE(SUM(sl.quantity), 0) as current_stock
        FROM products p
        LEFT JOIN stock_levels sl ON p.id = sl.product_id
        WHERE p.is_active = 1
        GROUP BY p.id
        ORDER BY p.name_am ASC
    ");
    $availableIngredients = $stmtIngredients->fetchAll();
    foreach ($availableIngredients as &$ai) {
        $ai['purchase_cost'] = (float)$ai['purchase_cost'];
        $ai['current_stock'] = (float)$ai['current_stock'];
    }

    send_success('የምግቦች እና ሬሲፒዎች ዝርዝር', [
        'foods'                 => $formattedFoods,
        'available_ingredients' => $availableIngredients
    ]);
}

// 2. አዲስ ምግብ መፍጠር ወይም ማደስ (Save Food Dish)
if ($action === 'save_recipe') {
    $data = get_request_data();
    $food_id = !empty($data['food_id']) ? (int)$data['food_id'] : 0;
    $name_am = trim($data['name_am'] ?? '');
    $name_en = trim($data['name_en'] ?? '');
    if (empty($name_en)) {
        $name_en = $name_am; // አማርኛውን ለእንግሊዝኛው መመደብ
    }
    $selling_price = (float)($data['selling_price'] ?? 0.0);
    $unit = trim($data['unit'] ?? 'plate');
    $category_id = !empty($data['category_id']) ? (int)$data['category_id'] : 4; // ነባሪ: የተዘጋጁ ምግቦች
    $ingredients = $data['ingredients'] ?? []; // [{ingredient_product_id: 1, quantity_needed: 0.2, unit: 'kg'}, ...]

    if (empty($name_am) || $selling_price < 0) {
        send_error('እባክዎ የምግብ ስም እና የመሸጫ ዋጋ በትክክል ያስገቡ');
    }

    if (empty($ingredients) || !is_array($ingredients)) {
        send_error('ቢያንስ አንድ ጥሬ ግብዓት (Ingredient) መምረጥ ግዴታ ነው');
    }

    // የግብዓቶችን ትክክለኛነት ማጣራት
    $cleanIngredients = [];
    $total_production_cost = 0.0;

    foreach ($ingredients as $ing) {
        $ing_id = (int)($ing['ingredient_product_id'] ?? 0);
        $qty = (float)($ing['quantity_needed'] ?? 0);
        $ing_unit = trim($ing['unit'] ?? 'kg');

        if ($ing_id <= 0 || $qty <= 0) {
            send_error('እባክዎ ለእያንዳንዱ ግብዓት ትክክለኛ ምርትና መጠን ያስገቡ');
        }

        // የግዢ ወጪውን እናገኝ
        $stmtCost = $pdo->prepare("SELECT purchase_cost, unit FROM products WHERE id = :id");
        $stmtCost->execute([':id' => $ing_id]);
        $costRow = $stmtCost->fetch();
        $ing_cost = (float)($costRow['purchase_cost'] ?? 0.0);
        $line_cost = round($qty * $ing_cost, 2);
        $total_production_cost += $line_cost;

        $cleanIngredients[] = [
            'ingredient_product_id' => $ing_id,
            'quantity_needed'       => $qty,
            'unit'                  => $ing_unit ?: ($costRow['unit'] ?? 'kg')
        ];
    }

    $pdo->beginTransaction();
    try {
        if ($food_id > 0) {
            // ነባር ምግብን ማደስ
            $stmtUpd = $pdo->prepare("
                UPDATE products
                SET name_am = :name_am, name_en = :name_en, selling_price = :sprice,
                    production_cost = :prod_cost, unit = :unit, category_id = :cat_id, updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':name_am'   => $name_am,
                ':name_en'   => $name_en,
                ':sprice'    => $selling_price,
                ':prod_cost' => round($total_production_cost, 2),
                ':unit'      => $unit,
                ':cat_id'    => $category_id,
                ':id'        => $food_id
            ]);
            $parent_id = $food_id;

            // የድሮ ሬሲፒ ረድፎችን ማስወገድ
            $pdo->prepare("DELETE FROM recipes WHERE parent_product_id = :pid")->execute([':pid' => $parent_id]);
        } else {
            // አዲስ ምግብ በ products ውስጥ መመዝገብ
            $uuid = 'food-' . bin2hex(random_bytes(8));
            $stmtIns = $pdo->prepare("
                INSERT INTO products (
                    uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost,
                    selling_price, min_stock_level, source, has_expiry, is_active, created_at
                ) VALUES (
                    :uuid, :name_en, :name_am, :cat_id, :unit, 0.00, :prod_cost,
                    :sprice, 0.00, 'company', 0, 1, NOW()
                )
            ");
            $stmtIns->execute([
                ':uuid'      => $uuid,
                ':name_en'   => $name_en,
                ':name_am'   => $name_am,
                ':cat_id'    => $category_id,
                ':unit'      => $unit,
                ':prod_cost' => round($total_production_cost, 2),
                ':sprice'    => $selling_price
            ]);
            $parent_id = (int)$pdo->lastInsertId();
        }

        // አዲሶቹን ሬሲፒ ግብዓቶች ማስገባት
        $stmtRcp = $pdo->prepare("
            INSERT INTO recipes (uuid, parent_product_id, ingredient_product_id, quantity_needed, unit, created_at)
            VALUES (:uuid, :p_id, :ing_id, :qty, :unit, NOW())
        ");

        foreach ($cleanIngredients as $cleanIng) {
            $rcp_uuid = 'rcp-' . bin2hex(random_bytes(8));
            $stmtRcp->execute([
                ':uuid'   => $rcp_uuid,
                ':p_id'   => $parent_id,
                ':ing_id' => $cleanIng['ingredient_product_id'],
                ':qty'    => $cleanIng['quantity_needed'],
                ':unit'   => $cleanIng['unit']
            ]);
        }

        $pdo->commit();
        log_audit($user['id'], 'RECIPE_SAVE', 'recipes', (string)$parent_id, null, $data);

        send_success('የምግብ አዘገጃጀቱ (Recipe) በተሳካ ሁኔታ ተቀምጧል', [
            'food_id'          => $parent_id,
            'name_am'          => $name_am,
            'name_en'          => $name_en,
            'cost_per_serving' => round($total_production_cost, 2),
            'selling_price'    => $selling_price,
            'estimated_profit' => max(0.0, round($selling_price - $total_production_cost, 2))
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('የምግብ አዘገጃጀቱን ማስቀመጥ አልተሳካም: ' . $e->getMessage());
    }
}

// 3. ሬሲፒ መሰረዝ
if ($action === 'delete') {
    $data = get_request_data();
    $food_id = (int)($data['food_id'] ?? 0);
    if ($food_id > 0) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM recipes WHERE parent_product_id = :pid")->execute([':pid' => $food_id]);
            $pdo->commit();
            log_audit($user['id'], 'RECIPE_DELETE', 'recipes', (string)$food_id, null, $data);
            send_success('የምግብ አዘገጃጀቱ ተሰርዟል');
        } catch (Exception $e) {
            $pdo->rollBack();
            send_error('መሰረዝ አልተሳካም: ' . $e->getMessage());
        }
    }
    send_error('የምግብ መለያ አልተገለጸም');
}

send_error('ያልታወቀ ክንውን', 404);
