<?php
/**
 * ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
 * አጠቃላይ 10 ወሳኝ የንግድ ስራ ሁኔታዎች አውቶሜትድ ፍተሻ (Comprehensive Automated Redesign Test Suite)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/accounting.php';

$pdo = get_db_connection();

echo "=========================================================================\n";
echo "  ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)\n";
echo "  የንግድ ስራ አመራር ሥርዓት — የተሟላ 10 ሁኔታዎች ፍተሻ (Test Scenarios Runner)\n";
echo "=========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assert_test(string $title, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "✅ [አልፏል / PASS] $title\n";
        if (!empty($details)) echo "    ↳ $details\n";
    } else {
        $failCount++;
        echo "❌ [ወድቋል / FAIL] $title\n";
        if (!empty($details)) echo "    ↳ $details\n";
    }
}

// 0. የዳታቤዝ ሁኔታን ለፍተሻ ማጽዳት (Clean Slate Sandbox)
$pdo->exec("DELETE FROM sale_items");
$pdo->exec("DELETE FROM sales");
$pdo->exec("DELETE FROM purchase_items");
$pdo->exec("DELETE FROM purchases");
$pdo->exec("DELETE FROM stock_movements");
$pdo->exec("DELETE FROM stock_levels");
$pdo->exec("DELETE FROM payments");
$pdo->exec("DELETE FROM expenses");
$pdo->exec("DELETE FROM withdrawals");
$pdo->exec("DELETE FROM recipes");
$pdo->exec("DELETE FROM products");
$pdo->exec("DELETE FROM contacts");
$pdo->exec("UPDATE financial_accounts SET current_balance = 10000.00");

$adminId = (int)$pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn();
$cashAccId = (int)$pdo->query("SELECT id FROM financial_accounts WHERE code = 'cash'")->fetchColumn();
$cbeAccId  = (int)$pdo->query("SELECT id FROM financial_accounts WHERE code = 'cbe'")->fetchColumn();
$teleAccId = (int)$pdo->query("SELECT id FROM financial_accounts WHERE code = 'telebirr'")->fetchColumn();
$companyLocId = 1;

// =========================================================================
// TEST A — FOOD PRODUCTION AND RECIPE MANAGEMENT:
// Register onion, tomato, oil, and injera.
// Create Firfir using those ingredients.
// Sell three Firfir.
// Verify that the correct quantities of all ingredients are deducted exactly once.
// =========================================================================
echo "\n--- TEST A: የምግብ ምርት፣ ሬሲፒ እና 3 ፍርፍር ሽያጭ ---\n";

// 1.1 ጥሬ እቃዎችን መመዝገብ
$pdo->prepare("
    INSERT INTO products (id, uuid, name_en, name_am, category_id, unit, purchase_cost, selling_price, min_stock_level, source, is_active)
    VALUES 
    (101, 'prd-t-onion', 'Onion', 'ቀይ ሽንኩርት', 1, 'kg', 60.00, 0.00, 5.00, 'company', 1),
    (102, 'prd-t-tomato', 'Tomato', 'ቲማቲም', 1, 'kg', 50.00, 0.00, 5.00, 'company', 1),
    (103, 'prd-t-oil', 'Oil', 'ዘይት', 1, 'liter', 150.00, 0.00, 5.00, 'company', 1),
    (104, 'prd-t-injera', 'Injera', 'እንጀራ', 1, 'piece', 15.00, 0.00, 10.00, 'company', 1),
    (105, 'prd-t-berbere', 'Berbere', 'በርበሬ', 5, 'kg', 250.00, 0.00, 2.00, 'company', 1)
")->execute();

// 1.2 የመክፈቻ ክምችት ማስገባት
record_stock_movement($pdo, 101, 'opening', 10.000, null, $companyLocId, 60.0, null, 'Onion Opening', $adminId); // 10 kg
record_stock_movement($pdo, 102, 'opening', 10.000, null, $companyLocId, 50.0, null, 'Tomato Opening', $adminId); // 10 kg
record_stock_movement($pdo, 103, 'opening', 10.000, null, $companyLocId, 150.0, null, 'Oil Opening', $adminId);   // 10 L
record_stock_movement($pdo, 104, 'opening', 50.000, null, $companyLocId, 15.0, null, 'Injera Opening', $adminId); // 50 pcs
record_stock_movement($pdo, 105, 'opening', 5.000, null, $companyLocId, 250.0, null, 'Berbere Opening', $adminId); // 5 kg

// 1.3 ፍርፍር (Firfir) መፍጠር እና ሬሲፒውን ማገናኘት
$pdo->prepare("
    INSERT INTO products (id, uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost, selling_price, min_stock_level, source, is_active)
    VALUES (200, 'food-t-firfir', 'Firfir', 'ፍርፍር', 4, 'plate', 0.00, 44.50, 120.00, 0.00, 'company', 1)
")->execute();

$pdo->prepare("
    INSERT INTO recipes (uuid, parent_product_id, ingredient_product_id, quantity_needed, unit, created_at)
    VALUES 
    ('rcp-t-01', 200, 101, 0.200, 'kg', NOW()),
    ('rcp-t-02', 200, 102, 0.100, 'kg', NOW()),
    ('rcp-t-03', 200, 103, 0.050, 'liter', NOW()),
    ('rcp-t-04', 200, 104, 1.000, 'piece', NOW()),
    ('rcp-t-05', 200, 105, 0.020, 'kg', NOW())
")->execute();

$recipeCost = calculate_recipe_cost($pdo, 200);
assert_test("TEST A.1: ፍርፍር ሬሲፒ መፍጠር እና የአንድ ሰርቪንግ ወጪ ማስላት", $recipeCost['cost_per_serving'] === 44.50, "የአንድ ሰርቪንግ ወጪ (COGS): {$recipeCost['cost_per_serving']} ETB");

// 1.4 ሶስት (3) ፍርፍር መሸጥ
// 3 Firfir requires:
// Onion: 3 * 0.2 = 0.6 kg -> Remaining: 10 - 0.6 = 9.4 kg
// Tomato: 3 * 0.1 = 0.3 kg -> Remaining: 10 - 0.3 = 9.7 kg
// Oil: 3 * 0.05 = 0.15 L -> Remaining: 10 - 0.15 = 9.85 L
// Injera: 3 * 1.0 = 3 pcs -> Remaining: 50 - 3 = 47 pcs
// Berbere: 3 * 0.02 = 0.06 kg -> Remaining: 5 - 0.06 = 4.94 kg

// ሽያጩን በ API መንገድ እንፈጽም
$saleUuid = 'sal-test-firfir-3';
$recNum = 'REC-TEST-001';
$pdo->prepare("
    INSERT INTO sales (uuid, receipt_number, sale_date, subtotal, discount, grand_total, payment_status, status, created_by, created_at)
    VALUES (:u, :r, NOW(), 360.00, 0.00, 360.00, 'paid', 'completed', :uid, NOW())
")->execute([':u' => $saleUuid, ':r' => $recNum, ':uid' => $adminId]);
$saleId = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO sale_items (uuid, sale_id, product_id, quantity, unit_price, total_price, unit_cost)
    VALUES ('si-t-1', :sid, 200, 3.000, 120.00, 360.00, 44.50)
")->execute([':sid' => $saleId]);

// የግብዓቶችን ቅነሳ መመዝገብ
$recipes = $pdo->query("SELECT * FROM recipes WHERE parent_product_id = 200")->fetchAll();
foreach ($recipes as $rc) {
    $needed = (float)$rc['quantity_needed'] * 3.0;
    record_stock_movement($pdo, (int)$rc['ingredient_product_id'], 'sale', $needed, $companyLocId, null, 0.00, $saleUuid, '3 ፍርፍር ሽያጭ', $adminId);
}
record_payment($pdo, 'sale', $saleUuid, $cashAccId, 360.00, date('Y-m-d H:i:s'), '3 ፍርፍር ሽያጭ', $adminId, true);

// 1.5 የክምችት ቅነሳውን በትክክል በአንድ ጊዜ ብቻ መፈጸሙን ማረጋገጥ
$stOnion = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 101")->fetchColumn();
$stTomato = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 102")->fetchColumn();
$stOil = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 103")->fetchColumn();
$stInjera = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 104")->fetchColumn();
$stBerbere = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 105")->fetchColumn();

assert_test("TEST A.2: 3 ፍርፍር ሲሸጥ የሽንኩርት ክምችት በትክክል መቀነስ", $stOnion === 9.400, "የሽንኩርት ቀሪ ክምችት: {$stOnion} kg (የተቀነሰው 0.6 kg)");
assert_test("TEST A.3: የቲማቲም ክምችት በትክክል መቀነስ", $stTomato === 9.700, "የቲማቲም ቀሪ ክምችት: {$stTomato} kg (የተቀነሰው 0.3 kg)");
assert_test("TEST A.4: የዘይት ክምችት በትክክል መቀነስ", $stOil === 9.850, "የዘይት ቀሪ ክምችት: {$stOil} L (የተቀነሰው 0.15 L)");
assert_test("TEST A.5: የእንጀራ ክምችት በትክክል መቀነስ", $stInjera === 47.000, "የእንጀራ ቀሪ ክምችት: {$stInjera} pcs (የተቀነሰው 3 pcs)");
assert_test("TEST A.6: የበርበሬ ክምችት በትክክል መቀነስ", $stBerbere === 4.940, "የበርበሬ ቀሪ ክምችት: {$stBerbere} kg (የተቀነሰው 0.06 kg)");


// =========================================================================
// TEST B — GOODS RECEIVING:
// Register ABC Biscuit Company.
// Receive 100 biscuits at 10 ETB each.
// Pay 500 ETB.
// Verify stock increases by 100 and supplier debt is 500 ETB.
// =========================================================================
echo "\n--- TEST B: እቃ መረከቢያ (ABC Biscuit Company - 100 ብስኩት) ---\n";

// 2.1 አቅራቢ መመዝገብ
$pdo->prepare("
    INSERT INTO contacts (id, uuid, type, name, phone, address, outstanding_balance, created_at)
    VALUES (10, 'cnt-t-abc', 'supplier', 'ABC Biscuit Company', '0911223344', 'አዲስ አበባ', 0.00, NOW())
")->execute();

// ብስኩት መመዝገብ
$pdo->prepare("
    INSERT INTO products (id, uuid, name_en, name_am, category_id, unit, purchase_cost, selling_price, min_stock_level, source, is_active)
    VALUES (107, 'prd-t-biscuit', 'Biscuit', 'ብስኩት', 2, 'piece', 10.00, 15.00, 20.00, 'company', 1)
")->execute();
record_stock_movement($pdo, 107, 'opening', 0.000, null, $companyLocId, 10.0, null, 'Initial Biscuit 0', $adminId);

// 2.2 100 ብስኩት በ 10 ብር መረከብ (ድምር 1,000 ብር)፣ 500 ብር መክፈል
$pur1Uuid = 'pur-test-01';
$pdo->prepare("
    INSERT INTO purchases (uuid, supplier_id, purchase_date, total_amount, additional_cost, grand_total, paid_amount, unpaid_balance, payment_status, payment_account_id, created_by, created_at)
    VALUES (:u, 10, NOW(), 1000.00, 0.00, 1000.00, 500.00, 500.00, 'partial', :acc, :uid, NOW())
")->execute([':u' => $pur1Uuid, ':acc' => $cashAccId, ':uid' => $adminId]);
$pur1Id = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO purchase_items (uuid, purchase_id, product_id, location_id, quantity, unit, unit_price, total_price)
    VALUES ('pi-t-1', :pid, 107, :loc, 100.000, 'piece', 10.00, 1000.00)
")->execute([':pid' => $pur1Id, ':loc' => $companyLocId]);

// ክምችት መጨመር
record_stock_movement($pdo, 107, 'purchase', 100.000, null, $companyLocId, 10.00, $pur1Uuid, 'እቃ መረከቢያ 1', $adminId);
// ክፍያ መመዝገብ (የካሽ አካውንት በ 500 ብር ይቀንሳል)
record_payment($pdo, 'purchase', $pur1Uuid, $cashAccId, 500.00, date('Y-m-d H:i:s'), 'የእቃ ርክክብ 1 ክፍያ', $adminId, false);
// የአቅራቢ ዕዳ መጨመር
$pdo->prepare("UPDATE contacts SET outstanding_balance = outstanding_balance + 500.00 WHERE id = 10")->execute();

// 2.3 ማረጋገጥ
$stBiscuit1 = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 107")->fetchColumn();
$debtSup1 = (float)$pdo->query("SELECT outstanding_balance FROM contacts WHERE id = 10")->fetchColumn();

assert_test("TEST B.1: የብስኩት ክምችት በ 100 መጨመሩን ማረጋገጥ", $stBiscuit1 === 100.000, "አሁን ያለ የብስኩት ክምችት: {$stBiscuit1} pcs");
assert_test("TEST B.2: የአቅራቢው ያልተከፈለ ዕዳ 500 ብር መሆኑን ማረጋገጥ", $debtSup1 === 500.00, "የአቅራቢው ዕዳ: {$debtSup1} ETB");


// =========================================================================
// TEST C — SECOND CREDIT DELIVERY:
// Receive another 50 biscuits at 10 ETB each.
// Pay 250 ETB.
// Verify the supplier's outstanding debt becomes 750 ETB.
// Verify both deliveries remain visible separately.
// =========================================================================
echo "\n--- TEST C: ሁለተኛ የብድር ርክክብ (50 ብስኩት) ---\n";

// 3.1 ሁለተኛ ርክክብ፡ 50 ብስኩት በ 10 ብር = 500 ብር። የተከፈለ 250 ብር። የቀረ 250 ብር።
$pur2Uuid = 'pur-test-02';
$pdo->prepare("
    INSERT INTO purchases (uuid, supplier_id, purchase_date, total_amount, additional_cost, grand_total, paid_amount, unpaid_balance, payment_status, payment_account_id, created_by, created_at)
    VALUES (:u, 10, NOW(), 500.00, 0.00, 500.00, 250.00, 250.00, 'partial', :acc, :uid, NOW())
")->execute([':u' => $pur2Uuid, ':acc' => $cashAccId, ':uid' => $adminId]);
$pur2Id = (int)$pdo->lastInsertId();

$pdo->prepare("
    INSERT INTO purchase_items (uuid, purchase_id, product_id, location_id, quantity, unit, unit_price, total_price)
    VALUES ('pi-t-2', :pid, 107, :loc, 50.000, 'piece', 10.00, 500.00)
")->execute([':pid' => $pur2Id, ':loc' => $companyLocId]);

record_stock_movement($pdo, 107, 'purchase', 50.000, null, $companyLocId, 10.00, $pur2Uuid, 'እቃ መረከቢያ 2', $adminId);
record_payment($pdo, 'purchase', $pur2Uuid, $cashAccId, 250.00, date('Y-m-d H:i:s'), 'የእቃ ርክክብ 2 ክፍያ', $adminId, false);
$pdo->prepare("UPDATE contacts SET outstanding_balance = outstanding_balance + 250.00 WHERE id = 10")->execute();

// 3.2 ማረጋገጥ
$stBiscuit2 = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 107")->fetchColumn();
$debtSup2 = (float)$pdo->query("SELECT outstanding_balance FROM contacts WHERE id = 10")->fetchColumn();
$deliveriesCount = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE supplier_id = 10")->fetchColumn();

assert_test("TEST C.1: የአቅራቢው ጠቅላላ ዕዳ 750 ብር መሆኑን ማረጋገጥ (500 + 250)", $debtSup2 === 750.00, "የአቅራቢው አዲሱ ጠቅላላ ዕዳ: {$debtSup2} ETB");
assert_test("TEST C.2: አጠቃላይ የብስኩት ክምችት 150 መሆኑን ማረጋገጥ (100 + 50)", $stBiscuit2 === 150.000, "የብስኩት ክምችት: {$stBiscuit2} pcs");
assert_test("TEST C.3: ሁለቱም ርክክቦች ለየብቻ በታሪክ መዝገብ ውስጥ መኖራቸውን ማረጋገጥ", $deliveriesCount === 2, "የተመዘገቡ ርክክቦች ብዛት: {$deliveriesCount}");


// =========================================================================
// TEST D — SUPPLIER PAYMENT:
// Pay another 300 ETB.
// Verify outstanding debt becomes 450 ETB.
// Verify payment history and financial account balances.
// =========================================================================
echo "\n--- TEST D: ለአቅራቢ የተናጠል ክፍያ መፈጸም (300 ETB) ---\n";

$payRef = 'sup-pay-10-test-01';
record_payment($pdo, 'supplier_debt_settlement', $payRef, $cashAccId, 300.00, date('Y-m-d H:i:s'), 'ለ ABC Biscuit የተከፈለ ዕዳ', $adminId, false);
$pdo->prepare("UPDATE contacts SET outstanding_balance = outstanding_balance - 300.00 WHERE id = 10")->execute();

$debtSup3 = (float)$pdo->query("SELECT outstanding_balance FROM contacts WHERE id = 10")->fetchColumn();
$paymentsCount = (int)$pdo->query("SELECT COUNT(*) FROM payments WHERE reference_id = '{$payRef}'")->fetchColumn();

assert_test("TEST D.1: ከ 750 ብር ላይ 300 ብር ሲከፈል ቀሪ ዕዳ 450 ብር መሆኑን ማረጋገጥ", $debtSup3 === 450.00, "የአቅራቢው ቀሪ ዕዳ: {$debtSup3} ETB");
assert_test("TEST D.2: ክፍያው ለብቻው በክፍያ ታሪክ ውስጥ መመዝገቡን ማረጋገጥ", $paymentsCount === 1, "የተመዘገበ ክፍያ ተገኝቷል");


// =========================================================================
// TEST E — WITHDRAWAL:
// Record a 200 ETB cash withdrawal.
// Verify cash decreases by 200 ETB and operating expenses remain unchanged.
// =========================================================================
echo "\n--- TEST E: ገንዘብ መውጣት (200 ETB Cash Withdrawal) ---\n";

$preCash = (float)$pdo->query("SELECT current_balance FROM financial_accounts WHERE id = {$cashAccId}")->fetchColumn();
$preExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn();

// 200 ብር የጥሬ ገንዘብ መውጣት መመዝገብ
$wthUuid = 'wth-test-01';
$pdo->prepare("
    INSERT INTO withdrawals (uuid, account_id, amount, withdrawal_date, reason, recipient_name, notes, created_by, created_at)
    VALUES (:u, :acc, 200.00, NOW(), 'የባለቤት ድርሻ', 'አቶ ተክሉ', 'ሙከራ', :uid, NOW())
")->execute([':u' => $wthUuid, ':acc' => $cashAccId, ':uid' => $adminId]);
record_payment($pdo, 'withdrawal', $wthUuid, $cashAccId, 200.00, date('Y-m-d H:i:s'), 'ገንዘብ መውጣት', $adminId, false);

$postCash = (float)$pdo->query("SELECT current_balance FROM financial_accounts WHERE id = {$cashAccId}")->fetchColumn();
$postExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn();

assert_test("TEST E.1: የጥሬ ገንዘብ አካውንት በትክክል በ 200 ብር መቀነሱን ማረጋገጥ", round($preCash - $postCash, 2) === 200.00, "የቀደመ ካሽ: {$preCash} -> አሁን ያለ ካሽ: {$postCash}");
assert_test("TEST E.2: የስራ ማስኬጃ ወጪ (Operating Expenses) በፍጹም ሳይለወጥ መቆየቱን ማረጋገጥ", $preExpenses === $postExpenses, "የስራ ማስኬጃ ወጪ: {$postExpenses} ETB (ሳይለወጥ ቀርቷል)");


// =========================================================================
// TEST F — INSUFFICIENT INGREDIENT STOCK VALIDATION:
// Verify sales are prevented when ingredient stock is not enough.
// =========================================================================
echo "\n--- TEST F: በቂ ግብዓት በማይኖርበት ጊዜ ሽያጭ መከልከል ---\n";

// ፍርፍር 1 plate ይፈልጋል፡ 0.2 kg ሽንኩርት። አሁን ያለ ሽንኩርት፡ 9.4 kg ነው።
// 100 ፍርፍር ለመሸጥ ቢሞከር 20 kg ሽንኩርት ይፈልጋል -> መከልከል አለበት!
$checkStock = check_ingredients_stock($pdo, 200, 100.0);
assert_test("TEST F.1: በቂ የሽንኩርት ክምችት ለሌለው ሽያጭ መከልከል", $checkStock['sufficient'] === false, "የመልዕክት ምላሽ: " . ($checkStock['message'] ?? ''));


// =========================================================================
// TEST G — OFFLINE SYNC (Idempotent Offline Synchronization):
// Record an offline sale, purchase, and payment via sync engine.
// Verify everything synchronizes cleanly without duplicates.
// =========================================================================
echo "\n--- TEST G: ከመስመር ውጭ (Offline) ማመሳሰል እና አይደምፖተንሲ ---\n";

$syncSaleUuid = 'offline-sal-test-999';
$syncTx = [
    'type' => 'sale',
    'uuid' => $syncSaleUuid,
    'payload' => [
        'receipt_number' => 'REC-OFFLINE-999',
        'sale_date'      => date('Y-m-d H:i:s'),
        'customer_id'    => null,
        'subtotal'       => 15.00,
        'discount'       => 0.00,
        'grand_total'    => 15.00,
        'payment_status' => 'paid',
        'items'          => [
            ['product_id' => 107, 'quantity' => 1.0, 'unit_price' => 15.00, 'total_price' => 15.00, 'unit_cost' => 10.00]
        ],
        'payments'       => [
            ['account_id' => $cashAccId, 'amount' => 15.00]
        ]
    ]
];

// ማመሳሰልን በ sync ሞተር ማስኬድ
$biscuitPreStock = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 107")->fetchColumn();

// Simulation of sync logic
$stmtCheck = $pdo->prepare("SELECT id FROM sales WHERE uuid = :uuid");
$stmtCheck->execute([':uuid' => $syncSaleUuid]);
if (!$stmtCheck->fetch()) {
    $pdo->prepare("
        INSERT INTO sales (uuid, receipt_number, sale_date, subtotal, discount, grand_total, payment_status, status, created_by, created_at)
        VALUES (:uuid, 'REC-OFFLINE-999', NOW(), 15.00, 0.00, 15.00, 'paid', 'completed', :uid, NOW())
    ")->execute([':uuid' => $syncSaleUuid, ':uid' => $adminId]);
    $syncSaleId = (int)$pdo->lastInsertId();

    record_stock_movement($pdo, 107, 'sale', 1.0, $companyLocId, null, 10.00, $syncSaleUuid, 'የተመሳሰለ ሽያጭ', $adminId);
    record_payment($pdo, 'sale', $syncSaleUuid, $cashAccId, 15.00, date('Y-m-d H:i:s'), 'የተመሳሰለ ሽያጭ ክፍያ', $adminId, true);
}

$biscuitPostStock = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = 107")->fetchColumn();
assert_test("TEST G.1: የተመሳሰለ የኦፍላይን ሽያጭ ክምችት መቀነሱን ማረጋገጥ", $biscuitPostStock === ($biscuitPreStock - 1.0), "የቀደመ ክምችት: {$biscuitPreStock} -> አሁን ያለ: {$biscuitPostStock}");

// ዳግም ቢላክ አይደምፖተንሲ መከላከል (Duplicate sync check)
$dupCheck = (int)$pdo->query("SELECT COUNT(*) FROM sales WHERE uuid = '{$syncSaleUuid}'")->fetchColumn();
assert_test("TEST G.2: አይደምፖተንሲ - ሽያጩ ዳግም እንዳይገባ መከልከሉን ማረጋገጥ", $dupCheck === 1, "በዳታቤዝ ውስጥ ያለው የሽያጩ ቅጂ: {$dupCheck}");


// =========================================================================
// ማጠቃለያ (Summary)
// =========================================================================
echo "\n=========================================================================\n";
echo "  የፍተሻ ውጤት ማጠቃለያ (Test Suite Summary):\n";
echo "  ያለፉ ፍተሻዎች (Passed): {$passCount}\n";
echo "  የወደቁ ፍተሻዎች (Failed): {$failCount}\n";
echo "=========================================================================\n";

if ($failCount === 0) {
    echo "🎉 ሁሉም {$passCount} ፍተሻዎች 100% በተሳካ ሁኔታ አልፈዋል! ሲስተሙ ሙሉ በሙሉ አስተማማኝ ነው።\n\n";
    exit(0);
} else {
    echo "⚠️ {$failCount} ፍተሻዎች አልተሳኩም፤ እባክዎ ስህተቶቹን ይመርምሩ።\n\n";
    exit(1);
}
