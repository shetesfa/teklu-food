<?php
/**
 * የምግብ አዘገጃጀት እና ግብዓቶች API (Recipes & Ingredients API)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/audit.php';

$user = get_authenticated_user();
$pdo = get_db_connection();
$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
    $stmt = $pdo->query("
        SELECT 
            r.*,
            p.name_am as parent_product_name_am,
            p.name_en as parent_product_name_en,
            ing.name_am as ingredient_name_am,
            ing.name_en as ingredient_name_en
        FROM recipes r
        JOIN products p ON r.parent_product_id = p.id
        JOIN products ing ON r.ingredient_product_id = ing.id
        ORDER BY p.name_am ASC
    ");
    $recipes = $stmt->fetchAll();
    foreach ($recipes as &$r) {
        $r['quantity_needed'] = (float)$r['quantity_needed'];
    }

    $restaurantMeals = $pdo->query("SELECT id, name_am, name_en FROM products WHERE category_id = 4 AND is_active = 1")->fetchAll();
    $rawIngredients = $pdo->query("SELECT id, name_am, name_en, unit FROM products WHERE category_id IN (1, 2, 3, 6) AND is_active = 1")->fetchAll();

    send_success('የምግብ አዘገጃጀቶች ዝርዝር', [
        'recipes'          => $recipes,
        'restaurant_meals' => $restaurantMeals,
        'raw_ingredients'  => $rawIngredients
    ]);
}

if ($action === 'create') {
    $data = get_request_data();
    $parent_product_id = (int)($data['parent_product_id'] ?? 0);
    $ingredient_product_id = (int)($data['ingredient_product_id'] ?? 0);
    $quantity_needed = (float)($data['quantity_needed'] ?? 0.0);
    $unit = trim($data['unit'] ?? 'kg');

    if ($parent_product_id <= 0 || $ingredient_product_id <= 0 || $quantity_needed <= 0) {
        send_error('ምግብ፣ ግብዓት እና አስፈላጊ መጠን በትክክል ያስገቡ');
    }

    $uuid = 'rcp-' . bin2hex(random_bytes(8));

    $stmt = $pdo->prepare("
        INSERT INTO recipes (uuid, parent_product_id, ingredient_product_id, quantity_needed, unit, created_at)
        VALUES (:uuid, :p_id, :ing_id, :qty, :unit, NOW())
        ON DUPLICATE KEY UPDATE quantity_needed = VALUES(quantity_needed), unit = VALUES(unit)
    ");
    $stmt->execute([
        ':uuid'   => $uuid,
        ':p_id'   => $parent_product_id,
        ':ing_id' => $ingredient_product_id,
        ':qty'    => $quantity_needed,
        ':unit'   => $unit
    ]);

    log_audit($user['id'], 'RECIPE_CREATE_OR_UPDATE', 'recipes', (string)$parent_product_id, null, $data);
    send_success('የምግብ አዘገጃጀቱ ተቀምጧል');
}

if ($action === 'delete') {
    $data = get_request_data();
    $id = (int)($data['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("DELETE FROM recipes WHERE id = :id")->execute([':id' => $id]);
        send_success('ግብዓቱ ተሰርዟል');
    }
    send_error('መለያ አልተገለጸም');
}

send_error('ያልታወቀ ክንውን', 404);
