<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * የሽያጭ እና POS አስተዳደር API (Sales & POS API)
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
    $date = $_GET['date'] ?? null;
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;

    $sql = "
        SELECT 
            s.*,
            c.name as customer_name,
            u.full_name as seller_name
        FROM sales s
        LEFT JOIN contacts c ON s.customer_id = c.id
        JOIN users u ON s.created_by = u.id
        WHERE 1=1
    ";
    $params = [];

    if ($date) {
        $sql .= " AND DATE(s.sale_date) = :d";
        $params[':d'] = $date;
    } elseif ($start_date && $end_date) {
        $sql .= " AND DATE(s.sale_date) BETWEEN :sd AND :ed";
        $params[':sd'] = $start_date;
        $params[':ed'] = $end_date;
    }

    $sql .= " ORDER BY s.sale_date DESC, s.id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sales = $stmt->fetchAll();

    // እቃዎች
    $saleIds = array_column($sales, 'id');
    $itemsBySale = [];
    $paysByRef = [];

    if (!empty($saleIds)) {
        $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
        $stmtItems = $pdo->prepare("
            SELECT si.*, pr.name_am as product_name_am, pr.name_en as product_name_en, pr.unit
            FROM sale_items si
            JOIN products pr ON si.product_id = pr.id
            WHERE si.sale_id IN ($placeholders)
        ");
        $stmtItems->execute($saleIds);
        foreach ($stmtItems->fetchAll() as $it) {
            $it['quantity'] = (float)$it['quantity'];
            $it['unit_price'] = (float)$it['unit_price'];
            $it['total_price'] = (float)$it['total_price'];
            $it['unit_cost'] = (float)$it['unit_cost'];
            $itemsBySale[$it['sale_id']][] = $it;
        }

        // ክፍያዎች
        $uuids = array_column($sales, 'uuid');
        $uuidPlaceholders = implode(',', array_fill(0, count($uuids), '?'));
        $stmtPay = $pdo->prepare("
            SELECT p.*, fa.code as account_code, fa.name_am as account_name_am
            FROM payments p
            JOIN financial_accounts fa ON p.account_id = fa.id
            WHERE p.reference_id IN ($uuidPlaceholders)
        ");
        $stmtPay->execute($uuids);
        foreach ($stmtPay->fetchAll() as $py) {
            $py['amount'] = (float)$py['amount'];
            $paysByRef[$py['reference_id']][] = $py;
        }
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

// 2. አዲስ ሽያጭ መመዝገብ (Process POS Sale)
if ($action === 'create') {
    $data = get_request_data();
    $uuid = $data['uuid'] ?? ('sal-' . bin2hex(random_bytes(8)));
    $customer_id = !empty($data['customer_id']) ? (int)$data['customer_id'] : null;
    $sale_date = $data['sale_date'] ?? date('Y-m-d H:i:s');
    $discount = (float)($data['discount'] ?? 0.0);
    $items = $data['items'] ?? [];
    $payments = $data['payments'] ?? []; // [{'account_id': 1, 'amount': 250}, ...]
    $payment_method = $data['payment_method'] ?? 'cash';

    if (empty($items) || !is_array($items)) {
        send_error('ቢያንስ አንድ የሚሸጥ ዕቃ ያስገቡ');
    }

    // የንዑስ ድምር እና የምርቶች ዝርዝር ማዘጋጀት
    $prodIds = array_map(fn($it) => (int)$it['product_id'], $items);
    $placeholders = implode(',', array_fill(0, count($prodIds), '?'));
    $stmtProd = $pdo->prepare("
        SELECT id, name_am, name_en, unit, selling_price, purchase_cost, production_cost
        FROM products WHERE id IN ($placeholders)
    ");
    $stmtProd->execute($prodIds);
    $dbProducts = [];
    foreach ($stmtProd->fetchAll() as $dp) {
        $dbProducts[$dp['id']] = $dp;
    }

    // -------------------------------------------------------------
    // ወሳኝ እርምጃ 1፡ በቂ የግብዓት ወይም የምርት ክምችት መኖሩን ማረጋገጥ!
    // (Prevent sales if there is insufficient ingredient stock)
    // -------------------------------------------------------------
    $subtotal = 0.0;
    $prepared_items = [];

    // በአንድ ጊዜ በቅርጫቱ ውስጥ አንድ ግብዓት በበርካታ ምግቦች ሊጠየቅ ስለሚችል ድምር ፍላጎት እናሰላለን
    $aggregatedIngredientDemand = []; // [ing_id => ['required' => x, 'name' => y, 'unit' => z]]
    $directProductDemand = [];        // [prod_id => ['required' => x, 'name' => y, 'unit' => z]]

    // ሬሲፒዎችን ማምጣት
    $stmtAllRecipes = $pdo->query("
        SELECT r.*, ing.name_am as ing_name_am, ing.name_en as ing_name_en, ing.unit as ing_unit, ing.purchase_cost
        FROM recipes r
        JOIN products ing ON r.ingredient_product_id = ing.id
    ");
    $recipesByParent = [];
    foreach ($stmtAllRecipes->fetchAll() as $rc) {
        $recipesByParent[$rc['parent_product_id']][] = $rc;
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

        // የሬሲፒ ግብዓቶች ካሉት የወጪ ስሌት
        if (isset($recipesByParent[$pid])) {
            $recipeCost = 0.0;
            foreach ($recipesByParent[$pid] as $rcIng) {
                $ing_id = (int)$rcIng['ingredient_product_id'];
                $neededPerUnit = (float)$rcIng['quantity_needed'];
                $totalIngNeeded = round($neededPerUnit * $qty, 3);
                $recipeCost += round($neededPerUnit * (float)$rcIng['purchase_cost'], 2);

                if (!isset($aggregatedIngredientDemand[$ing_id])) {
                    $aggregatedIngredientDemand[$ing_id] = [
                        'required' => 0.0,
                        'name_am'  => $rcIng['ing_name_am'],
                        'name_en'  => $rcIng['ing_name_en'],
                        'unit'     => $rcIng['ing_unit'] ?: $rcIng['unit']
                    ];
                }
                $aggregatedIngredientDemand[$ing_id]['required'] += $totalIngNeeded;
            }
            $calculatedCost = $recipeCost;
        } else {
            // ቀጥታ ምርት
            $calculatedCost = (float)$prodInfo['purchase_cost'];
            if (!isset($directProductDemand[$pid])) {
                $directProductDemand[$pid] = [
                    'required' => 0.0,
                    'name_am'  => $prodInfo['name_am'],
                    'name_en'  => $prodInfo['name_en'],
                    'unit'     => $prodInfo['unit']
                ];
            }
            $directProductDemand[$pid]['required'] += $qty;
        }

        $prepared_items[] = [
            'product_id' => $pid,
            'quantity'   => $qty,
            'unit_price' => $u_price,
            'total_price'=> $item_total,
            'unit_cost'  => $calculatedCost
        ];
    }

    // የግብዓቶችን ክምችት ማረጋገጥ
    foreach ($aggregatedIngredientDemand as $ing_id => $dem) {
        $stmtSt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM stock_levels WHERE product_id = :id");
        $stmtSt->execute([':id' => $ing_id]);
        $avail = (float)$stmtSt->fetchColumn();
        if ($avail < $dem['required']) {
            send_error(sprintf(
                'በቂ የግብዓት ክምችት የለም! %s (%s) የሚያስፈልገው: %s %s፣ በአሁን ሰዓት ያለው: %s %s ብቻ ነው።',
                $dem['name_am'],
                $dem['name_en'],
                $dem['required'],
                $dem['unit'],
                $avail,
                $dem['unit']
            ));
        }
    }

    // የቀጥታ ምርቶችን ክምችት ማረጋገጥ
    foreach ($directProductDemand as $dir_id => $dem) {
        $stmtSt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) FROM stock_levels WHERE product_id = :id");
        $stmtSt->execute([':id' => $dir_id]);
        $avail = (float)$stmtSt->fetchColumn();
        if ($avail < $dem['required']) {
            send_error(sprintf(
                'በቂ ክምችት የለም! %s (%s) የሚያስፈልገው: %s %s፣ በአሁን ሰዓት ያለው: %s %s ብቻ ነው።',
                $dem['name_am'],
                $dem['name_en'],
                $dem['required'],
                $dem['unit'],
                $avail,
                $dem['unit']
            ));
        }
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

    // አይደምፖተንሲ ምርመራ
    $stmtCheck = $pdo->prepare("SELECT id, receipt_number FROM sales WHERE uuid = :uuid");
    $stmtCheck->execute([':uuid' => $uuid]);
    if ($existing = $stmtCheck->fetch()) {
        send_success('ይህ ሽያጭ ቀድሞ ተመዝግቧል (Idempotent)', [
            'sale_id'        => $existing['id'],
            'receipt_number' => $existing['receipt_number'],
            'uuid'           => $uuid
        ]);
    }

    $receipt_number = 'REC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    $company_loc_id = 1; // ማዕከላዊ የድርጅት ክምችት

    $pdo->beginTransaction();
    try {
        // 1. የሽያጭ ዋና መዝገብ
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

        // 2. የተሸጡ እቃዎች እና የክምችት ቅነሳ
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

            // ምግብ ከሆነ (Recipe ያለው) -> ግብዓቶችን ከክምችት መቀነስ
            if (isset($recipesByParent[$pItem['product_id']])) {
                foreach ($recipesByParent[$pItem['product_id']] as $ingredient) {
                    $ing_qty = round((float)$ingredient['quantity_needed'] * $pItem['quantity'], 3);
                    record_stock_movement(
                        $pdo,
                        (int)$ingredient['ingredient_product_id'],
                        'sale',
                        $ing_qty,
                        $company_loc_id,
                        null,
                        (float)$ingredient['purchase_cost'],
                        $uuid,
                        'የምግብ ዝግጅት ግብዓት ቅነሳ ለሽያጭ #' . $receipt_number,
                        $user['id']
                    );
                }
            } else {
                // ተራ ምርት ከሆነ -> ምርቱን ራሱን ከክምችት መቀነስ
                record_stock_movement(
                    $pdo,
                    $pItem['product_id'],
                    'sale',
                    $pItem['quantity'],
                    $company_loc_id,
                    null,
                    $pItem['unit_cost'],
                    $uuid,
                    'ቀጥታ ሽያጭ #' . $receipt_number,
                    $user['id']
                );
            }
        }

        // 3. ክፍያዎችን መመዝገብ (የፋይናንስ አካውንቶች ሚዛን ይጨምራል)
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
                    'የሽያጭ ክፍያ ደረሰኝ #' . $receipt_number,
                    $user['id'],
                    true
                );
            }
        }

        // 4. በብድር የቀረ ካለ የደንበኛውን ዕዳ መጨመር
        if ($unpaid_balance > 0 && $customer_id !== null) {
            $stmtDebt = $pdo->prepare("
                UPDATE contacts
                SET outstanding_balance = outstanding_balance + :bal, updated_at = NOW()
                WHERE id = :c_id
            ");
            $stmtDebt->execute([
                ':bal'  => $unpaid_balance,
                ':c_id' => $customer_id
            ]);
        }

        $pdo->commit();
        log_audit($user['id'], 'SALE_CREATE', 'sales', (string)$sale_id, null, $data);

        send_success('ሽያጩ በተሳካ ሁኔታ ተጠናቋል', [
            'sale_id'        => $sale_id,
            'receipt_number' => $receipt_number,
            'uuid'           => $uuid,
            'subtotal'       => $subtotal,
            'discount'       => $discount,
            'grand_total'    => $grand_total,
            'total_paid'     => $total_paid,
            'unpaid_balance' => $unpaid_balance,
            'payment_status' => $payment_status
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ሽያጩን ማጠናቀቅ አልተሳካም: ' . $e->getMessage());
    }
}

// 3. ሽያጭ መሰረዝ/መቀልበስ (Cancel or Refund Sale) — Admin ብቻ
if ($action === 'cancel') {
    require_admin($user);
    $data = get_request_data();
    $sale_id = (int)($data['sale_id'] ?? 0);
    $reason = trim($data['reason'] ?? 'የሽያጭ ስረዛ / ተመላሽ');

    if ($sale_id <= 0) {
        send_error('የሽያጭ መለያ አልተገለጸም');
    }

    $stmt = $pdo->prepare("SELECT * FROM sales WHERE id = :id");
    $stmt->execute([':id' => $sale_id]);
    $sale = $stmt->fetch();
    if (!$sale) {
        send_error('ሽያጩ አልተገኘም');
    }

    if ($sale['status'] === 'cancelled') {
        send_error('ይህ ሽያጭ አስቀድሞ ተሰርዟል');
    }

    $company_loc_id = 1;

    $pdo->beginTransaction();
    try {
        // የሽያጩን እቃዎች ማምጣት
        $stmtItems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = :sid");
        $stmtItems->execute([':sid' => $sale_id]);
        $items = $stmtItems->fetchAll();

        // ሬሲፒዎችን ማምጣት
        $stmtAllRecipes = $pdo->query("SELECT * FROM recipes");
        $recipesByParent = [];
        foreach ($stmtAllRecipes->fetchAll() as $rc) {
            $recipesByParent[$rc['parent_product_id']][] = $rc;
        }

        // የተቀነሱትን ግብዓቶች ወይም እቃዎች ወደ ክምችት መመለስ (Restore stock accurately)
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $qty = (float)$item['quantity'];

            if (isset($recipesByParent[$pid])) {
                foreach ($recipesByParent[$pid] as $ing) {
                    $ing_qty = round((float)$ing['quantity_needed'] * $qty, 3);
                    record_stock_movement(
                        $pdo,
                        (int)$ing['ingredient_product_id'],
                        'return',
                        $ing_qty,
                        null,
                        $company_loc_id,
                        0.00,
                        $sale['uuid'],
                        'የተሰረዘ ሽያጭ ግብዓት ተመላሽ #' . $sale['receipt_number'] . ': ' . $reason,
                        $user['id']
                    );
                }
            } else {
                record_stock_movement(
                    $pdo,
                    $pid,
                    'return',
                    $qty,
                    null,
                    $company_loc_id,
                    (float)$item['unit_cost'],
                    $sale['uuid'],
                    'የተሰረዘ ሽያጭ እቃ ተመላሽ #' . $sale['receipt_number'] . ': ' . $reason,
                    $user['id']
                );
            }
        }

        // የተከፈሉ ክፍያዎችን ከአካውንቶች መቀነስ (ተመላሽ)
        $stmtPays = $pdo->prepare("SELECT * FROM payments WHERE reference_id = :ref AND transaction_type = 'sale'");
        $stmtPays->execute([':ref' => $sale['uuid']]);
        $payments = $stmtPays->fetchAll();

        foreach ($payments as $pay) {
            record_payment(
                $pdo,
                'sale',
                $sale['uuid'] . '-rev',
                (int)$pay['account_id'],
                (float)$pay['amount'],
                date('Y-m-d H:i:s'),
                'የተሰረዘ ሽያጭ ክፍያ ተመላሽ #' . $sale['receipt_number'],
                $user['id'],
                false // ወጪ ስለሆነ ይቀንሳል
            );
        }

        // በብድር የነበረ ከሆነ የደንበኛውን ዕዳ መቀነስ
        if ($sale['payment_status'] === 'credit' && !empty($sale['customer_id'])) {
            $total_paid = 0.0;
            foreach ($payments as $p) $total_paid += (float)$p['amount'];
            $debtToReduce = max(0.0, (float)$sale['grand_total'] - $total_paid);
            if ($debtToReduce > 0) {
                $stmtDebt = $pdo->prepare("
                    UPDATE contacts
                    SET outstanding_balance = GREATEST(0, outstanding_balance - :bal), updated_at = NOW()
                    WHERE id = :cid
                ");
                $stmtDebt->execute([':bal' => $debtToReduce, ':cid' => $sale['customer_id']]);
            }
        }

        // የሽያጩን ሁኔታ ወደ cancelled መቀየር
        $pdo->prepare("UPDATE sales SET status = 'cancelled' WHERE id = :id")->execute([':id' => $sale_id]);

        $pdo->commit();
        log_audit($user['id'], 'SALE_CANCEL', 'sales', (string)$sale_id, null, $data);

        send_success('ሽያጩ በተሳካ ሁኔታ ተሰርዟል፤ ሁሉም ግብዓቶችና ክፍያዎች ወደ ነበሩበት ተመልሰዋል');
    } catch (Exception $e) {
        $pdo->rollBack();
        send_error('ሽያጩን መሰረዝ አልተሳካም: ' . $e->getMessage());
    }
}

send_error('ያልታወቀ ክንውን', 404);
