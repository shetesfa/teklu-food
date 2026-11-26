<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * ከመስመር ውጭ የተከናወኑ ግብይቶችን ማመሳሰያ API (Offline Sync Engine)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/accounting.php';
require_once __DIR__ . '/../helpers/audit.php';

$user = get_authenticated_user();
$pdo = get_db_connection();

$data = get_request_data();
$device_uuid = trim($data['device_uuid'] ?? '');
$transactions = $data['transactions'] ?? [];

$synced_uuids = [];
$errors = [];
$company_loc_id = 1; // ማዕከላዊ የድርጅት ክምችት

$pdo->beginTransaction();
try {
    foreach ($transactions as $tx) {
        $tx_type = $tx['type'] ?? '';
        $tx_uuid = $tx['uuid'] ?? '';
        $payload = $tx['payload'] ?? [];

        if (empty($tx_uuid) || empty($tx_type)) {
            continue;
        }

        try {
            // 1. ሽያጭ (Sale Sync)
            if ($tx_type === 'sale') {
                $stmtCheck = $pdo->prepare("SELECT id FROM sales WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $receipt_num = $payload['receipt_number'] ?? ('REC-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)));
                    $stmtSale = $pdo->prepare("
                        INSERT INTO sales (uuid, receipt_number, sale_date, customer_id, subtotal, discount, grand_total, payment_status, status, created_by, created_at)
                        VALUES (:uuid, :rec, :sdate, :cust_id, :subtot, :disc, :grand, :p_status, 'completed', :created_by, NOW())
                    ");
                    $stmtSale->execute([
                        ':uuid'       => $tx_uuid,
                        ':rec'        => $receipt_num,
                        ':sdate'      => $payload['sale_date'] ?? date('Y-m-d H:i:s'),
                        ':cust_id'    => $payload['customer_id'] ?? null,
                        ':subtot'     => $payload['subtotal'] ?? 0.0,
                        ':disc'       => $payload['discount'] ?? 0.0,
                        ':grand'      => $payload['grand_total'] ?? 0.0,
                        ':p_status'   => $payload['payment_status'] ?? 'paid',
                        ':created_by' => $user['id']
                    ]);
                    $sale_id = (int)$pdo->lastInsertId();

                    // ሬሲፒዎችን መመርመር
                    $stmtAllRecipes = $pdo->query("SELECT * FROM recipes");
                    $recipesByParent = [];
                    foreach ($stmtAllRecipes->fetchAll() as $rc) {
                        $recipesByParent[$rc['parent_product_id']][] = $rc;
                    }

                    foreach ($payload['items'] as $it) {
                        $it_uuid = bin2hex(random_bytes(16));
                        $pid = (int)$it['product_id'];
                        $qty = (float)$it['quantity'];

                        $stmtItem = $pdo->prepare("
                            INSERT INTO sale_items (uuid, sale_id, product_id, quantity, unit_price, total_price, unit_cost)
                            VALUES (:uuid, :sale_id, :prod_id, :qty, :u_price, :tot, :u_cost)
                        ");
                        $stmtItem->execute([
                            ':uuid'    => $it_uuid,
                            ':sale_id' => $sale_id,
                            ':prod_id' => $pid,
                            ':qty'     => $qty,
                            ':u_price' => $it['unit_price'],
                            ':tot'     => $it['total_price'],
                            ':u_cost'  => $it['unit_cost'] ?? 0.0
                        ]);

                        if (isset($recipesByParent[$pid])) {
                            foreach ($recipesByParent[$pid] as $ingredient) {
                                $ing_qty = round((float)$ingredient['quantity_needed'] * $qty, 3);
                                record_stock_movement(
                                    $pdo,
                                    (int)$ingredient['ingredient_product_id'],
                                    'sale',
                                    $ing_qty,
                                    $company_loc_id,
                                    null,
                                    0.00,
                                    $tx_uuid,
                                    'የተመሳሰለ ሽያጭ ግብዓት ቅነሳ #' . $receipt_num,
                                    $user['id']
                                );
                            }
                        } else {
                            record_stock_movement(
                                $pdo,
                                $pid,
                                'sale',
                                $qty,
                                $company_loc_id,
                                null,
                                (float)($it['unit_cost'] ?? 0.0),
                                $tx_uuid,
                                'የተመሳሰለ ቀጥታ ሽያጭ #' . $receipt_num,
                                $user['id']
                            );
                        }
                    }

                    // ክፍያዎች
                    if (!empty($payload['payments']) && is_array($payload['payments'])) {
                        foreach ($payload['payments'] as $pay) {
                            $pay_amt = (float)($pay['amount'] ?? 0);
                            $acc_id = (int)($pay['account_id'] ?? 0);
                            if ($pay_amt > 0 && $acc_id > 0) {
                                record_payment(
                                    $pdo,
                                    'sale',
                                    $tx_uuid,
                                    $acc_id,
                                    $pay_amt,
                                    $payload['sale_date'] ?? date('Y-m-d H:i:s'),
                                    'የተመሳሰለ ሽያጭ ክፍያ #' . $receipt_num,
                                    $user['id'],
                                    true
                                );
                            }
                        }
                    }

                    // የደንበኛ እዳ ካለ
                    $unpaid = (float)($payload['unpaid_balance'] ?? 0.0);
                    if ($unpaid > 0 && !empty($payload['customer_id'])) {
                        $pdo->prepare("UPDATE contacts SET outstanding_balance = outstanding_balance + :amt WHERE id = :cid")
                            ->execute([':amt' => $unpaid, ':cid' => (int)$payload['customer_id']]);
                    }
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 1b. አዲስ ምግብ/ሬሲፒ መፍጠር (Food / Recipe Creation Sync)
            // ከመስመር ውጭ የተፈጠረ ምግብ — recipes.php save_recipe የሚያደርገውን
            // ተመሳሳይ ስራ እዚህ ይደገማል (አዲስ ምግብ INSERT ወይም ነባር UPDATE)።
            // እንደገና መላክ ቢሆንም (retry) ምግቡ/ሬሲፒው እንዳይደገም UUID
            // ይረጋገጣል፤ ሬሲፒዎቹ ከመግባታቸው በፊት ይሰረዛሉ።
            elseif ($tx_type === 'create_recipe') {
                $food_uuid = trim($payload['uuid'] ?? '');
                $food_id = !empty($payload['food_id']) ? (int)$payload['food_id'] : 0;
                $name_am = trim($payload['name_am'] ?? '');
                $name_en = trim($payload['name_en'] ?? '');
                if (empty($name_en)) {
                    $name_en = $name_am;
                }
                $selling_price = (float)($payload['selling_price'] ?? 0.0);
                $unit = trim($payload['unit'] ?? 'plate');
                $category_id = !empty($payload['category_id']) ? (int)$payload['category_id'] : 4;
                $ingredients = $payload['ingredients'] ?? [];

                if (!empty($name_am) && is_array($ingredients) && count($ingredients) > 0) {
                    // የግብዓቶችን ትክክለኛነት ማጣራት + የምርት ወጪ መቁጠር
                    $cleanIngredients = [];
                    $total_production_cost = 0.0;
                    $valid = true;
                    foreach ($ingredients as $ing) {
                        $ing_id = (int)($ing['ingredient_product_id'] ?? 0);
                        $qty = (float)($ing['quantity_needed'] ?? 0);
                        if ($ing_id <= 0 || $qty <= 0) {
                            $valid = false;
                            break;
                        }
                        $stmtCost = $pdo->prepare("SELECT purchase_cost, unit FROM products WHERE id = :id");
                        $stmtCost->execute([':id' => $ing_id]);
                        $costRow = $stmtCost->fetch();
                        if (!$costRow) {
                            $valid = false;
                            break;
                        }
                        $total_production_cost += round($qty * (float)($costRow['purchase_cost'] ?? 0.0), 2);
                        $cleanIngredients[] = [
                            'ingredient_product_id' => $ing_id,
                            'quantity_needed'       => $qty,
                            'unit'                  => trim($ing['unit'] ?? '') ?: ($costRow['unit'] ?? 'kg')
                        ];
                    }

                    if ($valid) {
                        if ($food_id > 0) {
                            // ነባር ምግብን ማደስ (እንደገና መላክ ቢሆንም ተመሳሳይ ውጤት ነው)
                            $stmtChk = $pdo->prepare("SELECT id FROM products WHERE id = :id");
                            $stmtChk->execute([':id' => $food_id]);
                            if ($stmtChk->fetch()) {
                                $pdo->prepare("
                                    UPDATE products
                                    SET name_am = :name_am, name_en = :name_en, selling_price = :sprice,
                                        production_cost = :prod_cost, unit = :unit, category_id = :cat_id, updated_at = NOW()
                                    WHERE id = :id
                                ")->execute([
                                    ':name_am'   => $name_am,
                                    ':name_en'   => $name_en,
                                    ':sprice'    => $selling_price,
                                    ':prod_cost' => round($total_production_cost, 2),
                                    ':unit'      => $unit,
                                    ':cat_id'    => $category_id,
                                    ':id'        => $food_id
                                ]);
                                $parent_id = $food_id;
                            } else {
                                $parent_id = 0;
                            }
                        } else {
                            // አዲስ ምግብ — UUID ሲደገም (እንደገና መላክ) እንዳይደገም መከላከል
                            $parent_id = 0;
                            if (!empty($food_uuid)) {
                                $stmtChk = $pdo->prepare("SELECT id FROM products WHERE uuid = :uuid");
                                $stmtChk->execute([':uuid' => $food_uuid]);
                                $row = $stmtChk->fetch();
                                if ($row) {
                                    $parent_id = (int)$row['id'];
                                }
                            }
                            if ($parent_id === 0) {
                                $new_uuid = !empty($food_uuid) ? $food_uuid : ('food-' . bin2hex(random_bytes(8)));
                                $pdo->prepare("
                                    INSERT INTO products (
                                        uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost,
                                        selling_price, min_stock_level, source, has_expiry, is_active, created_at
                                    ) VALUES (
                                        :uuid, :name_en, :name_am, :cat_id, :unit, 0.00, :prod_cost,
                                        :sprice, 0.00, 'company', 0, 1, NOW()
                                    )
                                ")->execute([
                                    ':uuid'      => $new_uuid,
                                    ':name_en'   => $name_en,
                                    ':name_am'   => $name_am,
                                    ':cat_id'    => $category_id,
                                    ':unit'      => $unit,
                                    ':prod_cost' => round($total_production_cost, 2),
                                    ':sprice'    => $selling_price
                                ]);
                                $parent_id = (int)$pdo->lastInsertId();
                            }
                        }

                        if ($parent_id > 0) {
                            // የድሮ ሬሲፒ ረድፎችን ማስወገድ (ለማደስም ሆነ ለ retry መደጋገም መከላከያ)
                            $pdo->prepare("DELETE FROM recipes WHERE parent_product_id = :pid")->execute([':pid' => $parent_id]);
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
                            log_audit($user['id'], 'RECIPE_SAVE', 'recipes', (string)$parent_id, null, $payload);
                        }
                    } else {
                        $errors[] = ['uuid' => $tx_uuid, 'error' => 'የሬሲፒው ግብዓቶች ትክክል አይደሉም (create_recipe)'];
                    }
                } else {
                    $errors[] = ['uuid' => $tx_uuid, 'error' => 'የምግብ ስም ወይም ግብዓቶች አልተገኙም (create_recipe)'];
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 2. እቃ መረከቢያ (Goods Receiving / Purchase Sync)
            elseif ($tx_type === 'purchase') {
                $stmtCheck = $pdo->prepare("SELECT id FROM purchases WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $stmtPur = $pdo->prepare("
                        INSERT INTO purchases (uuid, supplier_id, purchase_date, total_amount, additional_cost, grand_total, paid_amount, unpaid_balance, payment_status, payment_account_id, notes, created_by, created_at)
                        VALUES (:uuid, :sup_id, :pdate, :tot, :add_cost, :grand, :paid, :unpaid, :p_status, :acc_id, :notes, :created_by, NOW())
                    ");
                    $stmtPur->execute([
                        ':uuid'       => $tx_uuid,
                        ':sup_id'     => $payload['supplier_id'] ?? null,
                        ':pdate'      => $payload['purchase_date'] ?? date('Y-m-d H:i:s'),
                        ':tot'        => $payload['total_amount'] ?? 0.0,
                        ':add_cost'   => $payload['additional_cost'] ?? 0.0,
                        ':grand'      => $payload['grand_total'] ?? 0.0,
                        ':paid'       => $payload['paid_amount'] ?? 0.0,
                        ':unpaid'     => $payload['unpaid_balance'] ?? 0.0,
                        ':p_status'   => $payload['payment_status'] ?? 'paid',
                        ':acc_id'     => $payload['payment_account_id'] ?? null,
                        ':notes'      => $payload['notes'] ?? '',
                        ':created_by' => $user['id']
                    ]);
                    $purchase_id = (int)$pdo->lastInsertId();

                    foreach ($payload['items'] as $it) {
                        $it_uuid = bin2hex(random_bytes(16));
                        $stmtItem = $pdo->prepare("
                            INSERT INTO purchase_items (uuid, purchase_id, product_id, location_id, quantity, unit, unit_price, total_price, expiry_date)
                            VALUES (:uuid, :p_id, :prod_id, :loc_id, :qty, :unit, :u_price, :tot, :exp)
                        ");
                        $stmtItem->execute([
                            ':uuid'    => $it_uuid,
                            ':p_id'    => $purchase_id,
                            ':prod_id' => $it['product_id'],
                            ':loc_id'  => $company_loc_id,
                            ':qty'     => $it['quantity'],
                            ':unit'    => $it['unit'] ?? 'piece',
                            ':u_price' => $it['unit_price'],
                            ':tot'     => $it['total_price'],
                            ':exp'     => $it['expiry_date'] ?? null
                        ]);

                        record_stock_movement(
                            $pdo,
                            (int)$it['product_id'],
                            'purchase',
                            (float)$it['quantity'],
                            null,
                            $company_loc_id,
                            (float)$it['unit_price'],
                            $tx_uuid,
                            'የተመሳሰለ እቃ ርክክብ',
                            $user['id']
                        );
                        update_weighted_average_cost($pdo, (int)$it['product_id'], (float)$it['quantity'], (float)$it['unit_price']);
                    }

                    $paid = (float)($payload['paid_amount'] ?? 0.0);
                    $acc_id = (int)($payload['payment_account_id'] ?? 0);
                    if ($paid > 0 && $acc_id > 0) {
                        record_payment(
                            $pdo,
                            'purchase',
                            $tx_uuid,
                            $acc_id,
                            $paid,
                            $payload['purchase_date'] ?? date('Y-m-d H:i:s'),
                            'የተመሳሰለ እቃ ርክክብ ክፍያ',
                            $user['id'],
                            false
                        );
                    }

                    // የአቅራቢ እዳ
                    $unpaid = (float)($payload['unpaid_balance'] ?? 0.0);
                    if ($unpaid > 0 && !empty($payload['supplier_id'])) {
                        $pdo->prepare("UPDATE contacts SET outstanding_balance = outstanding_balance + :bal WHERE id = :sid")
                            ->execute([':bal' => $unpaid, ':sid' => (int)$payload['supplier_id']]);
                    }
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 3. ለአቅራቢ የተፈጸመ ክፍያ (Supplier Debt Payment Sync)
            elseif ($tx_type === 'supplier_payment') {
                $stmtCheck = $pdo->prepare("SELECT id FROM payments WHERE reference_id = :ref");
                $stmtCheck->execute([':ref' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $sup_id = (int)($payload['supplier_id'] ?? 0);
                    $acc_id = (int)($payload['account_id'] ?? 0);
                    $amt = (float)($payload['amount'] ?? 0.0);
                    $pdate = $payload['payment_date'] ?? date('Y-m-d H:i:s');
                    $notes = trim($payload['notes'] ?? '');

                    if ($sup_id > 0 && $acc_id > 0 && $amt > 0) {
                        record_payment(
                            $pdo,
                            'supplier_debt_settlement',
                            $tx_uuid,
                            $acc_id,
                            $amt,
                            $pdate,
                            'የተመሳሰለ ለአቅራቢ የተከፈለ ዕዳ: ' . $notes,
                            $user['id'],
                            false
                        );

                        $pdo->prepare("
                            UPDATE contacts
                            SET outstanding_balance = GREATEST(0, outstanding_balance - :amt), updated_at = NOW()
                            WHERE id = :sid
                        ")->execute([':amt' => $amt, ':sid' => $sup_id]);
                    }
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 4. የስራ ማስኬጃ ወጪ (Operating Expense Sync)
            elseif ($tx_type === 'expense') {
                $stmtCheck = $pdo->prepare("SELECT id FROM expenses WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $stmtExp = $pdo->prepare("
                        INSERT INTO expenses (uuid, category_id, account_id, amount, expense_date, department, description, created_by, created_at)
                        VALUES (:uuid, :cat_id, :acc_id, :amt, :edate, 'general', :desc, :created_by, NOW())
                    ");
                    $stmtExp->execute([
                        ':uuid'       => $tx_uuid,
                        ':cat_id'     => $payload['category_id'],
                        ':acc_id'     => $payload['account_id'],
                        ':amt'        => $payload['amount'],
                        ':edate'      => $payload['expense_date'] ?? date('Y-m-d H:i:s'),
                        ':desc'       => $payload['description'] ?? '',
                        ':created_by' => $user['id']
                    ]);
                    record_payment(
                        $pdo,
                        'expense',
                        $tx_uuid,
                        (int)$payload['account_id'],
                        (float)$payload['amount'],
                        $payload['expense_date'] ?? date('Y-m-d H:i:s'),
                        'የተመሳሰለ ወጪ: ' . ($payload['description'] ?? ''),
                        $user['id'],
                        false
                    );
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 5. ገንዘብ መውጣት (Withdrawal Sync)
            elseif ($tx_type === 'withdrawal') {
                $stmtCheck = $pdo->prepare("SELECT id FROM withdrawals WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $stmtWth = $pdo->prepare("
                        INSERT INTO withdrawals (uuid, account_id, amount, withdrawal_date, reason, recipient_name, notes, created_by, created_at)
                        VALUES (:uuid, :acc_id, :amt, :wdate, :reason, :rec, :notes, :created_by, NOW())
                    ");
                    $stmtWth->execute([
                        ':uuid'       => $tx_uuid,
                        ':acc_id'     => $payload['account_id'],
                        ':amt'        => $payload['amount'],
                        ':wdate'      => $payload['withdrawal_date'] ?? date('Y-m-d H:i:s'),
                        ':reason'     => $payload['reason'] ?? '',
                        ':rec'        => $payload['recipient_name'] ?? '',
                        ':notes'      => $payload['notes'] ?? '',
                        ':created_by' => $user['id']
                    ]);
                    record_payment(
                        $pdo,
                        'withdrawal',
                        $tx_uuid,
                        (int)$payload['account_id'],
                        (float)$payload['amount'],
                        $payload['withdrawal_date'] ?? date('Y-m-d H:i:s'),
                        'የተመሳሰለ ገንዘብ መውጣት ለ ' . ($payload['recipient_name'] ?? ''),
                        $user['id'],
                        false
                    );
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 6. የደንበኛ ዕዳ ክፍያ (Customer Credit Settlement Sync)
            elseif ($tx_type === 'customer_settlement' || $tx_type === 'customer_debt_settlement') {
                $stmtCheck = $pdo->prepare("SELECT id FROM payments WHERE reference_id = :ref");
                $stmtCheck->execute([':ref' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $cid = (int)($payload['customer_id'] ?? $payload['contact_id'] ?? 0);
                    $acc_id = (int)($payload['account_id'] ?? 0);
                    $amt = (float)($payload['amount'] ?? 0.0);
                    $pdate = $payload['payment_date'] ?? date('Y-m-d H:i:s');
                    $notes = trim($payload['notes'] ?? '');

                    if ($cid > 0 && $acc_id > 0 && $amt > 0) {
                        record_payment(
                            $pdo,
                            'customer_credit_settlement',
                            $tx_uuid,
                            $acc_id,
                            $amt,
                            $pdate,
                            'የተመሳሰለ የደንበኛ ዕዳ ክፍያ: ' . $notes,
                            $user['id'],
                            true
                        );

                        $pdo->prepare("
                            UPDATE contacts
                            SET outstanding_balance = GREATEST(0, outstanding_balance - :amt), updated_at = NOW()
                            WHERE id = :cid
                        ")->execute([':amt' => $amt, ':cid' => $cid]);
                    }
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 7. አዲስ ደንበኛ መመዝገብ (Customer Creation Sync)
            elseif ($tx_type === 'create_customer') {
                $name = trim($payload['name'] ?? '');
                $phone = trim($payload['phone'] ?? '');
                $address = trim($payload['address'] ?? '');
                if (!empty($name)) {
                    $stmtCheck = $pdo->prepare("SELECT id FROM contacts WHERE name = :name AND type = 'customer'");
                    $stmtCheck->execute([':name' => $name]);
                    if (!$stmtCheck->fetch()) {
                        $pdo->prepare("INSERT INTO contacts (uuid, type, name, phone, address, outstanding_balance, created_at) VALUES (:uuid, 'customer', :name, :phone, :addr, 0, NOW())")
                            ->execute([':uuid' => $tx_uuid, ':name' => $name, ':phone' => $phone, ':addr' => $address]);
                    }
                }
                $synced_uuids[] = $tx_uuid;
            }
        } catch (Exception $txEx) {
            $errors[] = ['uuid' => $tx_uuid, 'error' => $txEx->getMessage()];
        }
    }

    if (!empty($device_uuid)) {
        $pdo->prepare("UPDATE devices SET last_sync_at = NOW() WHERE device_uuid = :duuid")->execute([':duuid' => $device_uuid]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    send_error('ማመሳሰል አልተሳካም: ' . $e->getMessage());
}

// ወቅታዊ መረጃዎችን ማምጣት
$latest_products = $pdo->query("
    SELECT p.*, COALESCE((SELECT SUM(sl.quantity) FROM stock_levels sl WHERE sl.product_id = p.id), 0) as total_stock
    FROM products p
    WHERE p.is_active = 1
")->fetchAll();

$latest_accounts = $pdo->query("SELECT * FROM financial_accounts WHERE is_active = 1")->fetchAll();
$latest_contacts = $pdo->query("SELECT * FROM contacts")->fetchAll();

send_success('የማመሳሰል ሂደት ተጠናቋል', [
    'synced_uuids' => $synced_uuids,
    'errors'       => $errors,
    'server_state' => [
        'products'  => $latest_products,
        'accounts'  => $latest_accounts,
        'contacts'  => $latest_contacts,
        'synced_at' => date('Y-m-d H:i:s')
    ]
]);
