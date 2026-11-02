<?php
/**
 * የሬስቶራንት እና የምርት ሽያጭ API (Restaurant POS & Sales API)
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

// 1. የሽያጮች ዝርዝር
if ($action === 'list') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            c.name as customer_name,
            u.full_name as seller_name
        FROM sales s
        LEFT JOIN contacts c ON s.customer_id = c.id
        JOIN users u ON s.created_by = u.id
        WHERE DATE(s.sale_date) = :d
        ORDER BY s.sale_date DESC, s.id DESC
    ");
    $stmt->execute([':d' => $date]);
    $sales = $stmt->fetchAll();

    // እቃዎች
    $stmtItems = $pdo->prepare("
        SELECT si.*, pr.name_am as product_name_am, pr.name_en as product_name_en, pr.unit
        FROM sale_items si
        JOIN products pr ON si.product_id = pr.id
        JOIN sales s ON si.sale_id = s.id
        WHERE DATE(s.sale_date) = :d
    ");
    $stmtItems->execute([':d' => $date]);
    $items = $stmtItems->fetchAll();
    $itemsBySale = [];
    foreach ($items as $it) {
        $itemsBySale[$it['sale_id']][] = $it;
    }

    // ክፍያዎች
    $stmtPay = $pdo->prepare("
        SELECT p.*, fa.code as account_code, fa.name_am as account_name_am
        FROM payments p
        JOIN financial_accounts fa ON p.account_id = fa.id
        WHERE p.transaction_type = 'sale' AND DATE(p.payment_date) = :d
    ");
    $stmtPay->execute([':d' => $date]);
    $pays = $stmtPay->fetchAll();
    $paysByRef = [];
    foreach ($pays as $py) {
        $paysByRef[$py['reference_id']][] = $py;
    }

    foreach ($sales as &$s) {
        $s['subtotal'] = (float)$s['subtotal'];
        $s['discount'] = (float)$s['discount'];
        $s['grand_total'] = (float)$s['grand_total'];
        $s['items'] = $itemsBySale[$s['id']] ?? [];
        $s['payments'] = $paysByRef[$s['uuid']] ?? [];
    }

    send_success('የሽያጮች ዝርዝር', ['sales' => $sales]);
}

// 2. አዲስ ሽያጭ መመዝገብ (Process Sale)
if ($action === 'create') {
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('sal-' . bin2hex(random_bytes(8)));
    $customer_id = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
    $sale_date = $data['sale_date'] ?? date('Y-m-d H:i:s');
    $discount = (float)($data['discount'] ?? 0.0);
    $items = $data['items'] ?? [];
    $payments = $data['payments'] ?? []; // [{'account_id': 1, 'amount': 250}, {'account_id': 3, 'amount': 150}]

    if (empty($items) || !is_array($items)) {
        send_error('ቢያንስ አንድ የሚሸጥ ዕቃ ያስገቡ');
    }

    // የቅናሽ ፈቃድ ምርመራ
    if ($discount > 0 && !check_permission($user, 'can_discount')) {
        send_error('የዋጋ ቅናሽ የማድረግ ፈቃድ የለዎትም', 403);
    }

    // ንዑስ ድምር እና የእቃዎች ዋጋ
    $subtotal = 0.0;
    $prepared_items = [];

    // የምርቶች ዋጋዎችን ከዳታቤዝ እናገኝ
    $prodIds = array_map(fn($it) => (int)$it['product_id'], $items);
    $placeholders = implode(',', array_fill(0, count($prodIds), '?'));
    $stmtProd = $pdo->prepare("SELECT id, name_am, selling_price, purchase_cost, production_cost, source FROM products WHERE id IN ($placeholders)");
    $stmtProd->execute($prodIds);
    $dbProducts = [];
    foreach ($stmtProd->fetchAll() as $dp) {
        $dbProducts[$dp['id']] = $dp;
    }

    foreach ($items as $it) {
        $pid = (int)$it['product_id'];
        $qty = (float)($it['quantity'] ?? 0);
        if ($qty <= 0 || !isset($dbProducts[$pid])) {
            send_error('የተመረጠው ምርት ወይም መጠን ትክክል አይደለም');
        }

        $prodInfo = $dbProducts[$pid];
        $u_price = isset($it['unit_price']) ? (float)$it['unit_price'] : (float)$prodInfo['selling_price'];
        $item_total = round($qty * $u_price, 2);
        $subtotal += $item_total;

        // የክፍያ ወጪ (Cost)
        $cost = (float)$prodInfo['purchase_cost'];
        if ($cost <= 0) {
            $cost = (float)$prodInfo['production_cost'];
        }

        $prepared_items[] = [
            'product_id' => $pid,
            'quantity'   => $qty,
            'unit_price' => $u_price,
            'total_price'=> $item_total,
            'unit_cost'  => $cost
        ];
    }

    $grand_total = max(0.0, $subtotal - $discount);

    // የተከፈለ ድምር
    $total_paid = 0.0;
    foreach ($payments as $pay) {
        $p_amt = (float)($pay['amount'] ?? 0);
        if ($p_amt > 0) {
            $total_paid += $p_amt;
        }
    }

    $payment_status = ($total_paid >= $grand_total) ? 'paid' : 'credit';
    $unpaid_balance = max(0.0, $grand_total - $total_paid);

    if ($payment_status === 'credit' && empty($customer_id)) {
        send_error('በብድር ለመሸጥ ደንበኛ መምረጥ ግዴታ ነው (Customer Required for Credit Sale)');
    }

    // በአይደምፖተንሲ ቁልፍ ማረጋገጥ (Idempotency)
    $stmtCheck = $pdo->prepare("SELECT id, receipt_number FROM sales WHERE uuid = :uuid");
    $stmtCheck->execute([':uuid' => $uuid]);
    if ($existing = $stmtCheck->fetch()) {
        send_success('ይህ ሽያጭ ቀድሞ ተመዝግቧል (Idempotent)', [
            'sale_id'        => $existing['id'],
            'receipt_number' => $existing['receipt_number'],
            'uuid'           => $uuid
        ]);
    }

    // የደረሰኝ ቁጥር ማመንጨት
    $receipt_number = 'REC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    $pdo->beginTransaction();
    try {
        // 1. የሽያጭ ዋና መዝገብ ማስገባት
        $stmtSale = $pdo->prepare("
            INSERT INTO sales (
                uuid, receipt_number, sale_date, customer_id, subtotal,
                discount, grand_total, payment_status, status, created_by, created_at
            ) VALUES (
                :uuid, :rec, :sdate, :cust_id, :subtot,
                :disc, :grand, :p_status, 'completed', :created_by, NOW()
            )
        ");
        $stmtSale->execute([
            ':uuid'       => $uuid,
            ':rec'        => $receipt_number,
            ':sdate'      => $sale_date,
            ':cust_id'    => $customer_id,
            ':subtot'     => $subtotal,
            ':disc'       => $discount,
            ':grand'      => $grand_total,
            ':p_status'   => $payment_status,
            ':created_by' => $user['id']
        ]);
        $sale_id = (int)$pdo->lastInsertId();

        // 2. የተሸጡ እቃዎች እና የክምችት ቅነሳ (Recipe ድጋፍን ጨምሮ)
        // የሬስቶራንት መጋዘን መለያ (Restaurant Stock Location ID)
        $stmtRestLoc = $pdo->query("SELECT id FROM stock_locations WHERE code = 'restaurant_stock' LIMIT 1");
        $restaurant_loc_id = (int)($stmtRestLoc->fetchColumn() ?: 2);

        // የምግብ አዘገጃጀቶችን (Recipes) መፈተሽ
        $stmtAllRecipes = $pdo->query("SELECT * FROM recipes");
        $allRecipes = $stmtAllRecipes->fetchAll();
        $recipesByParent = [];
        foreach ($allRecipes as $rc) {
            $recipesByParent[$rc['parent_product_id']][] = $rc;
        }

        foreach ($prepared_items as $pItem) {
            $item_uuid = bin2hex(random_bytes(16));
            $stmtItem = $pdo->prepare("
                INSERT INTO sale_items (uuid, sale_id, product_id, quantity, unit_price, total_price, unit_cost)
                VALUES (:uuid, :sale_id, :prod_id, :qty, :u_price, :tot, :u_cost)
            ");
            $stmtItem->execute([
                ':uuid'    => $item_uuid,
                ':sale_id' => $sale_id,
                ':prod_id' => $pItem['product_id'],
                ':qty'     => $pItem['quantity'],
                ':u_price' => $pItem['unit_price'],
                ':tot'     => $pItem['total_price'],
                ':u_cost'  => $pItem['unit_cost']
            ]);

            // Recipe ካለው ንጥረ-ነገሮችን (Ingredients) መቀነስ
            if (isset($recipesByParent[$pItem['product_id']])) {
                foreach ($recipesByParent[$pItem['product_id']] as $ingredient) {
                    $ing_qty = (float)$ingredient['quantity_needed'] * $pItem['quantity'];
                    record_stock_movement(
                        $pdo,
                        (int)$ingredient['ingredient_product_id'],
                        'sale',
                        $ing_qty,
                        $restaurant_loc_id,
                        null,
                        0.00,
                        $uuid,
                        'የምግብ ዝግጅት ግብዓት ቅነሳ ለሽያጭ #' . $receipt_number,
                        $user['id']
                    );
                }
            } else {
                // Recipe ከሌለው ምርቱን ራሱን በቀጥታ ከሬስቶራንት ክምችት መቀነስ
                record_stock_movement(
                    $pdo,
                    $pItem['product_id'],
                    'sale',
                    $pItem['quantity'],
                    $restaurant_loc_id,
                    null,
                    $pItem['unit_cost'],
                    $uuid,
                    'ቀጥታ ሽያጭ #' . $receipt_number,
                    $user['id']
                );
            }
        }

        // 3. ክፍያዎችን መመዝገብ እና የፋይናንስ ሂሳቦችን መጨመር
        foreach ($payments as $pay) {
            $p_amt = (float)($pay['amount'] ?? 0);
            $acc_id = (int)($pay['account_id'] ?? 0);
            if ($p_amt > 0 && $acc_id > 0) {
                record_payment(
                    $pdo,
                    'sale',
                    $uuid,
                    $acc_id,
                    $p_amt,
                    $sale_date,
                    'የሽያጭ ክፍያ #' . $receipt_number,
                    $user['id'],
                    true // ገቢ ስለሆነ የፋይናንስ ሂሳቡ ይጨምራል
                );
            }
        }

        // 4. በብድር የተሸጠ ከሆነ የደንበኛውን ዕዳ መጨመር
        if ($unpaid_balance > 0 && $customer_id !== null) {
            $stmtCustDebt = $pdo->prepare("
                UPDATE contacts
                SET outstanding_balance = outstanding_balance + :bal, updated_at = NOW()
                WHERE id = :c_id
            ");
            $stmtCustDebt->execute([
                ':bal'  => $unpaid_balance,
                ':c_id' => $customer_id
            ]);
        }

        $pdo->commit();
        log_audit($user['id'], 'SALE_CREATE', 'sales', (string)$sale_id, null, [
            'receipt'     => $receipt_number,
            'grand_total' => $grand_total,
            'paid'        => $total_paid
        ]);

        send_success('ሽያጩ በተሳካ ሁኔታ ተጠናቋል', [
            'sale_id'        => $sale_id,
            'uuid'           => $uuid,
            'receipt_number' => $receipt_number,
            'grand_total'    => $grand_total,
            'payment_status' => $payment_status,
            'unpaid_balance' => $unpaid_balance
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ሽያጩን ማጠናቀቅ አልተሳካም: ' . $e->getMessage());
    }
}

// 3. ሽያጭ መሰረዝ/መመለስ (Refund / Cancel Sale via Reversal)
if ($action === 'cancel') {
    $data = get_request_data();
    $sale_id = (int)($data['sale_id'] ?? 0);
    $reason = trim($data['reason'] ?? '');

    if ($sale_id <= 0 || empty($reason)) {
        send_error('የሽያጭ መለያ እና የመሰረዝ ምክንያት ማስገባት ግዴታ ነው');
    }

    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = :id");
    $stmt->execute([':id' => $sale_id]);
    $sale = $stmt->fetch();
    if (!$sale || $sale['status'] !== 'completed') {
        send_error('ይህ ሽያጭ አልተገኘም ወይም ቀድሞ ተሰርዟል');
    }

    $pdo->beginTransaction();
    try {
        // የሽያጭ ሁኔታ መቀየር
        $stmtUpd = $pdo->prepare("UPDATE sales SET status = 'cancelled' WHERE id = :id");
        $stmtUpd->execute([':id' => $sale_id]);

        // የተከፈለውን ገንዘብ መመለስ (Reversal payment)
        $stmtPay = $pdo->prepare("SELECT * FROM payments WHERE transaction_type = 'sale' AND reference_id = :ref");
        $stmtPay->execute([':ref' => $sale['uuid']]);
        $payments = $stmtPay->fetchAll();

        foreach ($payments as $py) {
            record_payment(
                $pdo,
                'sale',
                $sale['uuid'] . '-reversal',
                (int)$py['account_id'],
                (float)$py['amount'],
                date('Y-m-d H:i:s'),
                'የተሰረዘ ሽያጭ መመለሻ #' . $sale['receipt_number'] . ': ' . $reason,
                $user['id'],
                false // ገንዘቡ ስለሚመለስ የአካውንቱ ሚዛን ይቀንሳል
            );
        }

        // የተሸጡትን እቃዎች ወደ ክምችት መመለስ (Stock Return)
        $stmtItems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = :s_id");
        $stmtItems->execute([':s_id' => $sale_id]);
        $saleItems = $stmtItems->fetchAll();

        $stmtRestLoc = $pdo->query("SELECT id FROM stock_locations WHERE code = 'restaurant_stock' LIMIT 1");
        $restaurant_loc_id = (int)($stmtRestLoc->fetchColumn() ?: 2);

        foreach ($saleItems as $sIt) {
            record_stock_movement(
                $pdo,
                (int)$sIt['product_id'],
                'return',
                (float)$sIt['quantity'],
                null,
                $restaurant_loc_id,
                (float)$sIt['unit_cost'],
                $sale['uuid'],
                'የተሰረዘ ሽያጭ መመለሻ #' . $sale['receipt_number'],
                $user['id']
            );
        }

        // በብድር ተሽጦ ከነበረ የደንበኛውን ዕዳ መቀነስ
        if ($sale['payment_status'] === 'credit' && !empty($sale['customer_id'])) {
            $unpaid = (float)$sale['grand_total'] - array_sum(array_column($payments, 'amount'));
            if ($unpaid > 0) {
                $stmtDebtDec = $pdo->prepare("
                    UPDATE contacts
                    SET outstanding_balance = GREATEST(0, outstanding_balance - :bal), updated_at = NOW()
                    WHERE id = :c_id
                ");
                $stmtDebtDec->execute([':bal' => $unpaid, ':c_id' => $sale['customer_id']]);
            }
        }

        $pdo->commit();
        log_audit($user['id'], 'SALE_CANCEL', 'sales', (string)$sale_id, $sale, ['reason' => $reason]);

        send_success('ሽያጩ በተሳካ ሁኔታ ተሰርዞ ገንዘቡና እቃው ተመልሷል');
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ሽያጩን መሰረዝ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
