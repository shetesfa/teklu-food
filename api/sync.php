<?php
/**
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
$transactions = $data['transactions'] ?? []; // የግብይቶች ወረፋ

$synced_uuids = [];
$errors = [];

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
            // 1. ሽያጭ (Sale)
            if ($tx_type === 'sale') {
                $stmtCheck = $pdo->prepare("SELECT id FROM sales WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    // አዲስ ሽያጭ ማስገባት
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

                    // እቃዎች
                    $stmtRestLoc = $pdo->query("SELECT id FROM stock_locations WHERE code = 'restaurant_stock' LIMIT 1");
                    $restaurant_loc_id = (int)($stmtRestLoc->fetchColumn() ?: 2);

                    foreach ($payload['items'] as $it) {
                        $it_uuid = bin2hex(random_bytes(16));
                        $stmtItem = $pdo->prepare("
                            INSERT INTO sale_items (uuid, sale_id, product_id, quantity, unit_price, total_price, unit_cost)
                            VALUES (:uuid, :sale_id, :prod_id, :qty, :u_price, :tot, :u_cost)
                        ");
                        $stmtItem->execute([
                            ':uuid'    => $it_uuid,
                            ':sale_id' => $sale_id,
                            ':prod_id' => $it['product_id'],
                            ':qty'     => $it['quantity'],
                            ':u_price' => $it['unit_price'],
                            ':tot'     => $it['total_price'],
                            ':u_cost'  => $it['unit_cost'] ?? 0.0
                        ]);

                        // ክምችት መቀነስ
                        record_stock_movement(
                            $pdo,
                            (int)$it['product_id'],
                            'sale',
                            (float)$it['quantity'],
                            $restaurant_loc_id,
                            null,
                            (float)($it['unit_cost'] ?? 0.0),
                            $tx_uuid,
                            'የተመሳሰለ ሽያጭ #' . $receipt_num,
                            $user['id']
                        );
                    }

                    // ክፍያዎች
                    foreach ($payload['payments'] as $pay) {
                        record_payment(
                            $pdo,
                            'sale',
                            $tx_uuid,
                            (int)$pay['account_id'],
                            (float)$pay['amount'],
                            $payload['sale_date'] ?? date('Y-m-d H:i:s'),
                            'የተመሳሰለ ሽያጭ ክፍያ #' . $receipt_num,
                            $user['id'],
                            true
                        );
                    }
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 2. ግዢ (Purchase)
            elseif ($tx_type === 'purchase') {
                $stmtCheck = $pdo->prepare("SELECT id FROM purchases WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $stmtPur = $pdo->prepare("
                        INSERT INTO purchases (uuid, supplier_id, purchase_date, total_amount, additional_cost, grand_total, payment_status, notes, created_by, created_at)
                        VALUES (:uuid, :sup_id, :pdate, :tot, :add_cost, :grand, :p_status, :notes, :created_by, NOW())
                    ");
                    $stmtPur->execute([
                        ':uuid'       => $tx_uuid,
                        ':sup_id'     => $payload['supplier_id'] ?? null,
                        ':pdate'      => $payload['purchase_date'] ?? date('Y-m-d'),
                        ':tot'        => $payload['total_amount'] ?? 0.0,
                        ':add_cost'   => $payload['additional_cost'] ?? 0.0,
                        ':grand'      => $payload['grand_total'] ?? 0.0,
                        ':p_status'   => $payload['payment_status'] ?? 'paid',
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
                            ':loc_id'  => $it['location_id'] ?? 1,
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
                            (int)($it['location_id'] ?? 1),
                            (float)$it['unit_price'],
                            $tx_uuid,
                            'የተመሳሰለ ግዢ',
                            $user['id']
                        );
                        update_weighted_average_cost($pdo, (int)$it['product_id'], (float)$it['quantity'], (float)$it['unit_price']);
                    }

                    foreach ($payload['payments'] as $pay) {
                        record_payment(
                            $pdo,
                            'purchase',
                            $tx_uuid,
                            (int)$pay['account_id'],
                            (float)$pay['amount'],
                            $payload['purchase_date'] ?? date('Y-m-d'),
                            'የተመሳሰለ ግዢ ክፍያ',
                            $user['id'],
                            false
                        );
                    }
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 3. ወጪ (Expense)
            elseif ($tx_type === 'expense') {
                $stmtCheck = $pdo->prepare("SELECT id FROM expenses WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $stmtExp = $pdo->prepare("
                        INSERT INTO expenses (uuid, category_id, account_id, amount, expense_date, department, description, created_by, created_at)
                        VALUES (:uuid, :cat_id, :acc_id, :amt, :edate, :dept, :desc, :created_by, NOW())
                    ");
                    $stmtExp->execute([
                        ':uuid'       => $tx_uuid,
                        ':cat_id'     => $payload['category_id'],
                        ':acc_id'     => $payload['account_id'],
                        ':amt'        => $payload['amount'],
                        ':edate'      => $payload['expense_date'] ?? date('Y-m-d'),
                        ':dept'       => $payload['department'] ?? 'general',
                        ':desc'       => $payload['description'] ?? '',
                        ':created_by' => $user['id']
                    ]);
                    record_payment(
                        $pdo,
                        'expense',
                        $tx_uuid,
                        (int)$payload['account_id'],
                        (float)$payload['amount'],
                        $payload['expense_date'] ?? date('Y-m-d'),
                        'የተመሳሰለ ወጪ: ' . ($payload['description'] ?? ''),
                        $user['id'],
                        false
                    );
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 4. መውጣት (Withdrawal)
            elseif ($tx_type === 'withdrawal') {
                $stmtCheck = $pdo->prepare("SELECT id FROM withdrawals WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $stmtWth = $pdo->prepare("
                        INSERT INTO withdrawals (uuid, account_id, amount, withdrawal_date, reason, recipient_name, created_by, created_at)
                        VALUES (:uuid, :acc_id, :amt, :wdate, :reason, :rec, :created_by, NOW())
                    ");
                    $stmtWth->execute([
                        ':uuid'       => $tx_uuid,
                        ':acc_id'     => $payload['account_id'],
                        ':amt'        => $payload['amount'],
                        ':wdate'      => $payload['withdrawal_date'] ?? date('Y-m-d'),
                        ':reason'     => $payload['reason'] ?? '',
                        ':rec'        => $payload['recipient_name'] ?? '',
                        ':created_by' => $user['id']
                    ]);
                    record_payment(
                        $pdo,
                        'withdrawal',
                        $tx_uuid,
                        (int)$payload['account_id'],
                        (float)$payload['amount'],
                        $payload['withdrawal_date'] ?? date('Y-m-d'),
                        'የተመሳሰለ ገንዘብ መውጣት',
                        $user['id'],
                        false
                    );
                }
                $synced_uuids[] = $tx_uuid;
            }

            // 5. የእርሻ ምርት (Farm Production)
            elseif ($tx_type === 'production') {
                $stmtCheck = $pdo->prepare("SELECT id FROM farm_productions WHERE uuid = :uuid");
                $stmtCheck->execute([':uuid' => $tx_uuid]);
                if (!$stmtCheck->fetch()) {
                    $stmtFp = $pdo->prepare("
                        INSERT INTO farm_productions (uuid, product_id, quantity, unit, production_cost, production_date, expiry_date, batch_number, destination_location_id, created_by, created_at)
                        VALUES (:uuid, :prod_id, :qty, :unit, :cost, :pdate, :exp, :batch, :dest_loc, :created_by, NOW())
                    ");
                    $stmtFp->execute([
                        ':uuid'       => $tx_uuid,
                        ':prod_id'    => $payload['product_id'],
                        ':qty'        => $payload['quantity'],
                        ':unit'       => $payload['unit'] ?? 'liter',
                        ':cost'       => $payload['production_cost'] ?? 0.0,
                        ':pdate'      => $payload['production_date'] ?? date('Y-m-d'),
                        ':exp'        => $payload['expiry_date'] ?? null,
                        ':batch'      => $payload['batch_number'] ?? '',
                        ':dest_loc'   => $payload['destination_location_id'] ?? 1,
                        ':created_by' => $user['id']
                    ]);
                    record_stock_movement(
                        $pdo,
                        (int)$payload['product_id'],
                        'production',
                        (float)$payload['quantity'],
                        null,
                        (int)($payload['destination_location_id'] ?? 1),
                        (float)($payload['production_cost'] ?? 0.0),
                        $tx_uuid,
                        'የተመሳሰለ የእርሻ ምርት',
                        $user['id']
                    );
                }
                $synced_uuids[] = $tx_uuid;
            }
        } catch (Exception $txEx) {
            $errors[] = ['uuid' => $tx_uuid, 'error' => $txEx->getMessage()];
        }
    }

    // የመሳሪያውን የመጨረሻ የማመሳሰል ጊዜ ማደስ
    if (!empty($device_uuid)) {
        $pdo->prepare("UPDATE devices SET last_sync_at = NOW() WHERE device_uuid = :duuid")->execute([':duuid' => $device_uuid]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    send_error('ማመሳሰል አልተሳካም: ' . $e->getMessage());
}

// ወቅታዊ የሆኑ አዳዲስ መረጃዎችን (Delta updates) ለስልኩ መመለስ
$latest_products = $pdo->query("
    SELECT p.*, COALESCE(SUM(sl.quantity), 0) as total_stock
    FROM products p
    LEFT JOIN stock_levels sl ON p.id = sl.product_id
    WHERE p.is_active = 1
    GROUP BY p.id
")->fetchAll();

$latest_stock_levels = $pdo->query("SELECT * FROM stock_levels")->fetchAll();
$latest_accounts = $pdo->query("SELECT * FROM financial_accounts WHERE is_active = 1")->fetchAll();

send_success('የማመሳሰል ሂደት ተጠናቋል', [
    'synced_uuids' => $synced_uuids,
    'errors'       => $errors,
    'server_state' => [
        'products'     => $latest_products,
        'stock_levels' => $latest_stock_levels,
        'accounts'     => $latest_accounts,
        'synced_at'    => date('Y-m-d H:i:s')
    ]
]);
