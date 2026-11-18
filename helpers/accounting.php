<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * የሂሳብ እና የክምችት ቀመሮች ሄልፐር (Accounting & Stock Management Engine)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/audit.php';

/**
 * የክምችት እንቅስቃሴ መመዝገቢያ እና የክምችት ማስተካከያ
 */
function record_stock_movement(
    PDO $pdo,
    int $product_id,
    string $movement_type,
    float $quantity,
    ?int $from_location_id,
    ?int $to_location_id,
    float $unit_cost,
    ?string $reference_id,
    ?string $reason,
    int $authorized_by
): string {
    $uuid = bin2hex(random_bytes(16));
    $total_cost = round($quantity * $unit_cost, 2);

    // 1. የክምችት እንቅስቃሴ መመዝገብ
    $stmt = $pdo->prepare("
        INSERT INTO stock_movements (
            uuid, movement_type, product_id, from_location_id, to_location_id,
            quantity, unit_cost, total_cost, reference_id, reason, authorized_by, created_at
        ) VALUES (
            :uuid, :movement_type, :product_id, :from_loc, :to_loc,
            :qty, :unit_cost, :total_cost, :ref_id, :reason, :auth_by, NOW()
        )
    ");
    $stmt->execute([
        ':uuid'          => $uuid,
        ':movement_type' => $movement_type,
        ':product_id'    => $product_id,
        ':from_loc'      => $from_location_id,
        ':to_loc'        => $to_location_id,
        ':qty'           => $quantity,
        ':unit_cost'     => $unit_cost,
        ':total_cost'    => $total_cost,
        ':ref_id'        => $reference_id,
        ':reason'        => $reason,
        ':auth_by'       => $authorized_by
    ]);

    // 2. ከቦታ የሚቀንስ ከሆነ
    if ($from_location_id !== null) {
        $stmtDec = $pdo->prepare("
            INSERT INTO stock_levels (product_id, location_id, quantity, updated_at)
            VALUES (:prod_id, :loc_id, :neg_qty, NOW())
            ON DUPLICATE KEY UPDATE quantity = quantity - :qty, updated_at = NOW()
        ");
        $stmtDec->execute([
            ':prod_id' => $product_id,
            ':loc_id'  => $from_location_id,
            ':neg_qty' => -$quantity,
            ':qty'     => $quantity
        ]);
    }

    // 3. ወደ ቦታ የሚጨምር ከሆነ
    if ($to_location_id !== null) {
        $stmtInc = $pdo->prepare("
            INSERT INTO stock_levels (product_id, location_id, quantity, updated_at)
            VALUES (:prod_id, :loc_id, :pos_qty, NOW())
            ON DUPLICATE KEY UPDATE quantity = quantity + :qty, updated_at = NOW()
        ");
        $stmtInc->execute([
            ':prod_id' => $product_id,
            ':loc_id'  => $to_location_id,
            ':pos_qty' => $quantity,
            ':qty'     => $quantity
        ]);
    }

    return $uuid;
}

/**
 * የተስተካከለ አማካይ ዋጋ (Weighted-Average Cost) ማደስ
 */
function update_weighted_average_cost(PDO $pdo, int $product_id, float $new_qty, float $new_unit_cost): void {
    if ($new_qty <= 0 || $new_unit_cost <= 0) {
        return;
    }

    $stmt = $pdo->prepare("SELECT purchase_cost FROM products WHERE id = :id");
    $stmt->execute([':id' => $product_id]);
    $prod = $stmt->fetch();
    $current_cost = (float)($prod['purchase_cost'] ?? 0.0);

    $stmtStock = $pdo->prepare("SELECT SUM(quantity) as total_stock FROM stock_levels WHERE product_id = :id");
    $stmtStock->execute([':id' => $product_id]);
    $st = $stmtStock->fetch();
    $existing_stock = max(0.0, (float)($st['total_stock'] ?? 0.0) - $new_qty);

    if ($existing_stock <= 0) {
        $avg_cost = $new_unit_cost;
    } else {
        $total_value = ($existing_stock * $current_cost) + ($new_qty * $new_unit_cost);
        $total_quantity = $existing_stock + $new_qty;
        $avg_cost = $total_value / $total_quantity;
    }

    $stmtUpd = $pdo->prepare("UPDATE products SET purchase_cost = :avg_cost, updated_at = NOW() WHERE id = :id");
    $stmtUpd->execute([
        ':avg_cost' => round($avg_cost, 2),
        ':id'       => $product_id
    ]);
}

/**
 * የፋይናንስ ሂሳብ ክፍያ መመዝገቢያ (Payment Recorder)
 */
function record_payment(
    PDO $pdo,
    string $transaction_type,
    string $reference_id,
    int $account_id,
    float $amount,
    string $payment_date,
    ?string $notes,
    int $created_by,
    bool $is_inflow // true = ገቢ (balance increases), false = ወጪ (balance decreases)
): string {
    $uuid = bin2hex(random_bytes(16));

    // 1. የክፍያ መዝገብ ማስገባት
    $stmt = $pdo->prepare("
        INSERT INTO payments (uuid, transaction_type, reference_id, account_id, amount, payment_date, notes, created_by, created_at)
        VALUES (:uuid, :type, :ref_id, :acc_id, :amount, :pdate, :notes, :created_by, NOW())
    ");
    $stmt->execute([
        ':uuid'       => $uuid,
        ':type'       => $transaction_type,
        ':ref_id'     => $reference_id,
        ':acc_id'     => $account_id,
        ':amount'     => $amount,
        ':pdate'      => $payment_date,
        ':notes'      => $notes,
        ':created_by' => $created_by
    ]);

    // 2. የአካውንት ሚዛን ማስተካከል
    $balance_delta = $is_inflow ? $amount : -$amount;
    $stmtAcc = $pdo->prepare("
        UPDATE financial_accounts
        SET current_balance = current_balance + :delta, updated_at = NOW()
        WHERE id = :acc_id
    ");
    $stmtAcc->execute([
        ':delta'  => $balance_delta,
        ':acc_id' => $account_id
    ]);

    return $uuid;
}

/**
 * የምርት ወይም የምግብ ሬሲፒ ግብዓቶች ክምችት በቂ መሆኑን ማረጋገጥ
 * Prevent sales if there is insufficient ingredient stock
 */
function check_ingredients_stock(PDO $pdo, int $product_id, float $ordered_qty): array {
    // 1. የምርቱ ሬሲፒ መኖሩን መፈተሽ
    $stmtRcp = $pdo->prepare("
        SELECT r.*, ing.name_am as ing_name_am, ing.name_en as ing_name_en, ing.unit as ing_unit,
               COALESCE((SELECT SUM(sl.quantity) FROM stock_levels sl WHERE sl.product_id = r.ingredient_product_id), 0) as current_stock
        FROM recipes r
        JOIN products ing ON r.ingredient_product_id = ing.id
        WHERE r.parent_product_id = :p_id
    ");
    $stmtRcp->execute([':p_id' => $product_id]);
    $ingredients = $stmtRcp->fetchAll();

    if (!empty($ingredients)) {
        // ምግብ ነው - እያንዳንዱ ግብዓት በቂ መሆኑን እንመርምር
        foreach ($ingredients as $ing) {
            $required = round((float)$ing['quantity_needed'] * $ordered_qty, 3);
            $available = (float)$ing['current_stock'];
            if ($available < $required) {
                return [
                    'sufficient'         => false,
                    'is_recipe'          => true,
                    'ingredient_name_am' => $ing['ing_name_am'],
                    'ingredient_name_en' => $ing['ing_name_en'],
                    'required'           => $required,
                    'available'          => $available,
                    'unit'               => $ing['ing_unit'] ?: $ing['unit'],
                    'message'            => sprintf(
                        'በቂ የግብዓት ክምችት የለም! %s (%s) የሚያስፈልገው: %s %s፣ በአሁን ሰዓት ያለው: %s %s ብቻ ነው።',
                        $ing['ing_name_am'],
                        $ing['ing_name_en'],
                        $required,
                        $ing['unit'],
                        $available,
                        $ing['unit']
                    )
                ];
            }
        }
        return ['sufficient' => true, 'is_recipe' => true, 'ingredients' => $ingredients];
    } else {
        // ተራ ምርት ነው (ቀጥታ የሚሸጥ) - የራሱ ክምችት ይፈተሻል
        $stmtProd = $pdo->prepare("
            SELECT p.name_am, p.name_en, p.unit,
                   COALESCE((SELECT SUM(sl.quantity) FROM stock_levels sl WHERE sl.product_id = p.id), 0) as current_stock
            FROM products p
            WHERE p.id = :id
        ");
        $stmtProd->execute([':id' => $product_id]);
        $prod = $stmtProd->fetch();
        if ($prod) {
            $available = (float)$prod['current_stock'];
            if ($available < $ordered_qty) {
                return [
                    'sufficient'         => false,
                    'is_recipe'          => false,
                    'ingredient_name_am' => $prod['name_am'],
                    'ingredient_name_en' => $prod['name_en'],
                    'required'           => $ordered_qty,
                    'available'          => $available,
                    'unit'               => $prod['unit'],
                    'message'            => sprintf(
                        'በቂ ክምችት የለም! %s የሚያስፈልገው: %s %s፣ በአሁን ሰዓት ያለው: %s %s ብቻ ነው።',
                        $prod['name_am'],
                        $ordered_qty,
                        $prod['unit'],
                        $available,
                        $prod['unit']
                    )
                ];
            }
        }
        return ['sufficient' => true, 'is_recipe' => false];
    }
}

/**
 * የሬሲፒ ግብዓቶች ወጪ ስሌት (Calculate Recipe Cost & Cost per Serving)
 */
function calculate_recipe_cost(PDO $pdo, int $product_id): array {
    $stmt = $pdo->prepare("
        SELECT r.*, ing.purchase_cost, ing.selling_price as ing_selling_price
        FROM recipes r
        JOIN products ing ON r.ingredient_product_id = ing.id
        WHERE r.parent_product_id = :p_id
    ");
    $stmt->execute([':p_id' => $product_id]);
    $ingredients = $stmt->fetchAll();

    $total_cost = 0.0;
    foreach ($ingredients as $ing) {
        $qty = (float)$ing['quantity_needed'];
        $cost = (float)$ing['purchase_cost'];
        $total_cost += ($qty * $cost);
    }

    return [
        'cost_per_serving' => round($total_cost, 2),
        'ingredients_count'=> count($ingredients),
        'ingredients'      => $ingredients
    ];
}
