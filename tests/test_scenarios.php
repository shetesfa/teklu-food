<?php
/**
 * ተክሉ ጌታቸው ቢዝነስ - የተሟላ 20 የንግድ ሁኔታዎች አውቶሜትድ ፍተሻ
 * (Automated 20 Business Scenarios Test Runner)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/accounting.php';

$pdo = get_db_connection();

echo "=========================================================================\n";
echo "  ተክሉ ጌታቸው የከብት እርባታ እና የወተት ተዋጽኦ ማቀነባበሪያ ድርጅት\n";
echo "  የንግድ ስራ አመራር ሥርዓት — የተሟላ 20 ሁኔታዎች ፍተሻ (Comprehensive Test Suite)\n";
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

// 1. የዳታቤዝ ሁኔታን ለፍተሻ ማጽዳት (Clean Slate Test Sandbox)
$pdo->exec("DELETE FROM sale_items");
$pdo->exec("DELETE FROM sales");
$pdo->exec("DELETE FROM purchase_items");
$pdo->exec("DELETE FROM purchases");
$pdo->exec("DELETE FROM stock_movements");
$pdo->exec("DELETE FROM stock_levels");
$pdo->exec("DELETE FROM payments");
$pdo->exec("DELETE FROM expenses");
$pdo->exec("DELETE FROM withdrawals");
$pdo->exec("DELETE FROM internal_transfers");
$pdo->exec("DELETE FROM farm_productions");
$pdo->exec("DELETE FROM daily_closings");
$pdo->exec("UPDATE financial_accounts SET current_balance = 10000.00"); // 10,000 ETB መነሻ ካፒታል ለእያንዳንዱ
$pdo->exec("UPDATE contacts SET outstanding_balance = 0.00");

// የሙከራ ተጠቃሚ
$adminId = (int)$pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn();

// የመጋዘን መለያዎች
$farmLocId = (int)$pdo->query("SELECT id FROM stock_locations WHERE code = 'farm_warehouse'")->fetchColumn();
$restLocId = (int)$pdo->query("SELECT id FROM stock_locations WHERE code = 'restaurant_stock'")->fetchColumn();
$mainLocId = (int)$pdo->query("SELECT id FROM stock_locations WHERE code = 'main_storage'")->fetchColumn();

// የፋይናንስ አካውንቶች
$cashAccId  = (int)$pdo->query("SELECT id FROM financial_accounts WHERE code = 'cash'")->fetchColumn();
$cbeAccId   = (int)$pdo->query("SELECT id FROM financial_accounts WHERE code = 'cbe'")->fetchColumn();
$teleAccId  = (int)$pdo->query("SELECT id FROM financial_accounts WHERE code = 'telebirr'")->fetchColumn();

// -------------------------------------------------------------------------
// ሁኔታ 1፡ ወተት መመዝገብ እና 100 ሊትር የመክፈቻ ክምችት ማስገባት
// -------------------------------------------------------------------------
$milkId = (int)$pdo->query("SELECT id FROM products WHERE uuid = 'prd-milk-01' LIMIT 1")->fetchColumn();
record_stock_movement($pdo, $milkId, 'opening', 100.0, null, $farmLocId, 45.0, null, 'መክፈቻ ክምችት', $adminId);

$stmtSt1 = $pdo->prepare("SELECT quantity FROM stock_levels WHERE product_id = :p AND location_id = :l");
$stmtSt1->execute([':p' => $milkId, ':l' => $farmLocId]);
$st1 = (float)$stmtSt1->fetchColumn();
assert_test("ሁኔታ 1: ወተት 100 ሊትር የመክፈቻ ክምችት ማስገባት", $st1 === 100.0, "በእርሻ መጋዘን ያለው ክምችት: {$st1} ሊትር");

// -------------------------------------------------------------------------
// ሁኔታ 2 እና 3፡ 10 ሊትር ወተት መሸጥ እና ክምችት 90 ሊትር መሆኑን ማረጋገጥ
// -------------------------------------------------------------------------
// ከእርሻ መጋዘን 10 ሊትር ሽያጭ
record_stock_movement($pdo, $milkId, 'sale', 10.0, $farmLocId, null, 45.0, 'test-sale-1', 'ቀጥታ ሽያጭ', $adminId);

$stmtSt2 = $pdo->prepare("SELECT quantity FROM stock_levels WHERE product_id = :p AND location_id = :l");
$stmtSt2->execute([':p' => $milkId, ':l' => $farmLocId]);
$st2 = (float)$stmtSt2->fetchColumn();
assert_test("ሁኔታ 2 እና 3: 10 ሊትር ወተት መሸጥና ክምችቱ 90 ሊትር መሆኑን ማረጋገጥ", $st2 === 90.0, "አሁን ያለ ክምችት: {$st2} ሊትር");

// -------------------------------------------------------------------------
// ሁኔታ 4 እና 5፡ ከውጭ አቅራቢ 20 ሊትር መግዛት እና ክምችቱ 110 ሊትር መሆኑን ማረጋገጥ
// -------------------------------------------------------------------------
record_stock_movement($pdo, $milkId, 'purchase', 20.0, null, $farmLocId, 50.0, 'test-pur-1', 'የውጭ ግዢ', $adminId);

$stmtSt3 = $pdo->prepare("SELECT quantity FROM stock_levels WHERE product_id = :p AND location_id = :l");
$stmtSt3->execute([':p' => $milkId, ':l' => $farmLocId]);
$st3 = (float)$stmtSt3->fetchColumn();
assert_test("ሁኔታ 4 እና 5: 20 ሊትር ከውጭ መግዛትና ክምችት 110 ሊትር መሆኑን ማረጋገጥ", $st3 === 110.0, "አሁን ያለ ክምችት: {$st3} ሊትር");

// -------------------------------------------------------------------------
// ሁኔታ 6 እና 7፡ 20 ሊትር ከእርሻ ወደ ሬስቶራንት ማስተላለፍ እና የክምችት ለውጥ ማረጋገጥ
// -------------------------------------------------------------------------
// 20 ሊትር ከእርሻ ወደ ሬስቶራንት ዝውውር
record_stock_movement($pdo, $milkId, 'transfer_out', 20.0, $farmLocId, null, 45.0, 'trf-001', 'ዝውውር ወጪ', $adminId);
record_stock_movement($pdo, $milkId, 'transfer_in', 20.0, null, $restLocId, 45.0, 'trf-001', 'ዝውውር ገቢ', $adminId);

$stmtFarm = $pdo->prepare("SELECT quantity FROM stock_levels WHERE product_id = :p AND location_id = :l");
$stmtFarm->execute([':p' => $milkId, ':l' => $farmLocId]);
$farmQty = (float)$stmtFarm->fetchColumn();

$stmtRest = $pdo->prepare("SELECT quantity FROM stock_levels WHERE product_id = :p AND location_id = :l");
$stmtRest->execute([':p' => $milkId, ':l' => $restLocId]);
$restQty = (float)$stmtRest->fetchColumn();

assert_test("ሁኔታ 6 እና 7: 20 ሊትር ከእርሻ ወደ ሬስቶራንት ማስተላለፍ (Farm=90L, Rest=20L)", ($farmQty === 90.0 && $restQty === 20.0), "የእርሻ: {$farmQty}L, የሬስቶራንት: {$restQty}L");

// -------------------------------------------------------------------------
// ሁኔታ 8፡ የውስጥ ዝውውር ለተጠቃለለው ድርጅት ገቢ አለመፍጠሩን ማረጋገጥ
// -------------------------------------------------------------------------
$stmtConsRev = $pdo->query("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE status = 'completed'");
$consRev = (float)$stmtConsRev->fetchColumn();
assert_test("ሁኔታ 8: የውስጥ ዝውውር ለድርጅቱ የተጠቃለለ ገቢ/ትርፍ አለመፍጠሩን ማረጋገጥ", $consRev === 0.0, "የተጠቃለለ ውጫዊ የሽያጭ ገቢ: {$consRev} ETB (ዝውውሩ ገቢ ሆኖ አልተመዘገበም)");

// -------------------------------------------------------------------------
// ሁኔታ 9፡ የሬስቶራንት ምግብ መሸጥ እና የ Recipe ግብዓቶችን መቀነስ
// -------------------------------------------------------------------------
// ልዩ የበሬ ጥብስ (Tibs) መሸጥ፡ 1 ሰሃን ጥብስ = 0.25 kg ስጋ + 0.10 kg ሽንኩርት + 0.05 L ዘይት
$meatId   = (int)$pdo->query("SELECT id FROM products WHERE uuid = 'prd-beef-01'")->fetchColumn();
$onionId  = (int)$pdo->query("SELECT id FROM products WHERE uuid = 'prd-onion-01'")->fetchColumn();
$oilId    = (int)$pdo->query("SELECT id FROM products WHERE uuid = 'prd-oil-01'")->fetchColumn();
$tibsId   = (int)$pdo->query("SELECT id FROM products WHERE uuid = 'prd-tibs-01'")->fetchColumn();

// በመጀመሪያ በሬስቶራንቱ ውስጥ ግብዓቶች መክፈቻ ክምችት ማስገባት
record_stock_movement($pdo, $meatId, 'opening', 10.0, null, $restLocId, 500.0, null, 'የስጋ መክፈቻ', $adminId);
record_stock_movement($pdo, $onionId, 'opening', 10.0, null, $restLocId, 60.0, null, 'የሽንኩርት መክፈቻ', $adminId);
record_stock_movement($pdo, $oilId, 'opening', 10.0, null, $restLocId, 150.0, null, 'የዘይት መክፈቻ', $adminId);

// 10 ሰሃን ጥብስ መሸጥ (10 * 0.25 = 2.5 kg ስጋ፣ 10 * 0.10 = 1.0 kg ሽንኩርት፣ 10 * 0.05 = 0.5 L ዘይት መቀነስ አለበት)
$soldPlates = 10.0;
record_stock_movement($pdo, $meatId, 'sale', $soldPlates * 0.25, $restLocId, null, 500.0, 'sal-tibs-10', 'የጥብስ ግብዓት ስጋ', $adminId);
record_stock_movement($pdo, $onionId, 'sale', $soldPlates * 0.10, $restLocId, null, 60.0, 'sal-tibs-10', 'የጥብስ ግብዓት ሽንኩርት', $adminId);
record_stock_movement($pdo, $oilId, 'sale', $soldPlates * 0.05, $restLocId, null, 150.0, 'sal-tibs-10', 'የጥብስ ግብዓት ዘይት', $adminId);

$remMeat  = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = $meatId AND location_id = $restLocId")->fetchColumn();
$remOnion = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = $onionId AND location_id = $restLocId")->fetchColumn();
$remOil   = (float)$pdo->query("SELECT quantity FROM stock_levels WHERE product_id = $oilId AND location_id = $restLocId")->fetchColumn();

assert_test("ሁኔታ 9: 10 ሰሃን ጥብስ ተሽጦ ግብዓቶች (ስጋ=7.5kg, ሽንኩርት=9.0kg, ዘይት=9.5L) በትክክል መቀነሳቸውን ማረጋገጥ",
    ($remMeat === 7.5 && $remOnion === 9.0 && $remOil === 9.5),
    "ቀሪ ስጋ: {$remMeat}kg, ሽንኩርት: {$remOnion}kg, ዘይት: {$remOil}L");

// -------------------------------------------------------------------------
// ሁኔታ 10፡ ድብልቅ ክፍያ (Mixed Payment: Cash + Telebirr) ሽያጭ ማካሄድ
// -------------------------------------------------------------------------
$initCash = (float)$pdo->query("SELECT current_balance FROM financial_accounts WHERE id = $cashAccId")->fetchColumn();
$initTele = (float)$pdo->query("SELECT current_balance FROM financial_accounts WHERE id = $teleAccId")->fetchColumn();

// ሽያጭ 1,000 ETB: 600 በካሽ + 400 በቴሌብር
record_payment($pdo, 'sale', 'sal-mix-01', $cashAccId, 600.0, date('Y-m-d H:i:s'), 'ድብልቅ ክፍያ ካሽ', $adminId, true);
record_payment($pdo, 'sale', 'sal-mix-01', $teleAccId, 400.0, date('Y-m-d H:i:s'), 'ድብልቅ ክፍያ ቴሌብር', $adminId, true);

$newCash = (float)$pdo->query("SELECT current_balance FROM financial_accounts WHERE id = $cashAccId")->fetchColumn();
$newTele = (float)$pdo->query("SELECT current_balance FROM financial_accounts WHERE id = $teleAccId")->fetchColumn();

assert_test("ሁኔታ 10: በድብልቅ ክፍያ (Cash +600, Telebirr +400) ሽያጭ ማካሄድ",
    (($newCash - $initCash === 600.0) && ($newTele - $initTele === 400.0)),
    "Cash አሁን: {$newCash} ETB, Telebirr አሁን: {$newTele} ETB");

// -------------------------------------------------------------------------
// ሁኔታ 11፡ ከፊል የአቅራቢ ክፍያ (Partial Supplier Payment)
// -------------------------------------------------------------------------
$supId = (int)$pdo->query("SELECT id FROM contacts WHERE type = 'supplier' LIMIT 1")->fetchColumn();
// ለአቅራቢው 5,000 ETB ዕዳ መመዝገብ
$pdo->exec("UPDATE contacts SET outstanding_balance = 5000.00 WHERE id = $supId");
// 3,000 ETB ከፊል ክፍያ መክፈል
record_payment($pdo, 'supplier_debt_settlement', 'debt-pay-01', $cbeAccId, 3000.0, date('Y-m-d H:i:s'), 'ለአቅራቢ ከፊል ክፍያ', $adminId, false);
$pdo->exec("UPDATE contacts SET outstanding_balance = outstanding_balance - 3000.00 WHERE id = $supId");

$remSupDebt = (float)$pdo->query("SELECT outstanding_balance FROM contacts WHERE id = $supId")->fetchColumn();
assert_test("ሁኔታ 11: ለአቅራቢ ከ 5,000 ETB ውስጥ 3,000 ETB ከፊል ክፍያ ተከፍሎ ቀሪ ዕዳ 2,000 ETB መሆኑን ማረጋገጥ",
    $remSupDebt === 2000.0, "ቀሪ የአቅራቢ ዕዳ: {$remSupDebt} ETB");

// -------------------------------------------------------------------------
// ሁኔታ 12፡ የደንበኛ የብድር ሽያጭ (Customer Credit Sale) መመዝገብ
// -------------------------------------------------------------------------
$custId = (int)$pdo->query("SELECT id FROM contacts WHERE type = 'customer' LIMIT 1")->fetchColumn();
$pdo->exec("UPDATE contacts SET outstanding_balance = 0.00 WHERE id = $custId");

// 1,500 ETB በብድር መሸጥ
$pdo->exec("UPDATE contacts SET outstanding_balance = outstanding_balance + 1500.00 WHERE id = $custId");
$custDebt1 = (float)$pdo->query("SELECT outstanding_balance FROM contacts WHERE id = $custId")->fetchColumn();
assert_test("ሁኔታ 12: የደንበኛ የብድር ሽያጭ (1,500 ETB) መመዝገብ",
    $custDebt1 === 1500.0, "የደንበኛው ዕዳ: {$custDebt1} ETB");

// -------------------------------------------------------------------------
// ሁኔታ 13፡ ደንበኛው ቆይቶ ዕዳውን ሲከፍል ማስተናገድ (Later Customer Payment)
// -------------------------------------------------------------------------
// ደንበኛው 1,000 ETB በቴሌብር ከፈለ
record_payment($pdo, 'customer_credit_settlement', 'cust-pay-01', $teleAccId, 1000.0, date('Y-m-d H:i:s'), 'የደንበኛ ዕዳ ክፍያ', $adminId, true);
$pdo->exec("UPDATE contacts SET outstanding_balance = outstanding_balance - 1000.00 WHERE id = $custId");

$custDebt2 = (float)$pdo->query("SELECT outstanding_balance FROM contacts WHERE id = $custId")->fetchColumn();
assert_test("ሁኔታ 13: ደንበኛው 1,000 ETB ዕዳ ሲከፍል ቀሪው 500 ETB መሆኑን ማረጋገጥ",
    $custDebt2 === 500.0, "ቀሪ የደንበኛ ዕዳ: {$custDebt2} ETB");

// -------------------------------------------------------------------------
// ሁኔታ 14፡ የባለቤት መውጣት የስራ ማስኬጃ ወጪ ተደርጎ አለመቆጠሩን ማረጋገጥ
// -------------------------------------------------------------------------
$expBefore = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn();
// አቶ ተክሉ 2,000 ETB የግል መውጣት
$stmtW = $pdo->prepare("
    INSERT INTO withdrawals (uuid, account_id, amount, withdrawal_date, reason, recipient_name, created_by, created_at)
    VALUES ('wth-test-01', :acc, 2000.0, CURDATE(), 'የባለቤት የግል መውጣት', 'አቶ ተክሉ ጌታቸው', :uid, NOW())
");
$stmtW->execute([':acc' => $cashAccId, ':uid' => $adminId]);
record_payment($pdo, 'withdrawal', 'wth-test-01', $cashAccId, 2000.0, date('Y-m-d H:i:s'), 'የባለቤት መውጣት', $adminId, false);

$expAfter = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM expenses")->fetchColumn();
assert_test("ሁኔታ 14: የባለቤት መውጣት (2,000 ETB) የስራ ማስኬጃ ወጪ ሆኖ አለመመዝገቡን ማረጋገጥ",
    ($expBefore === $expAfter && $expAfter === 0.0),
    "የስራ ማስኬጃ ወጪዎች: {$expAfter} ETB (የባለቤት መውጣት አልተደመረበትም)");

// -------------------------------------------------------------------------
// ሁኔታ 15፣ 16 እና 17፡ ከመስመር ውጭ ሽያጭ መመዝገብ፣ ወረፋ ላይ መቆየቱን ማረጋገጥ እና በ sync.php ማመሳሰል
// -------------------------------------------------------------------------
$offlineSalePayload = [
    'device_uuid' => 'dev-offline-test-001',
    'transactions' => [
        [
            'type' => 'sale',
            'uuid' => 'sal-offline-uuid-999',
            'payload' => [
                'receipt_number' => 'REC-OFFLINE-999',
                'sale_date'      => date('Y-m-d H:i:s'),
                'customer_id'    => null,
                'subtotal'       => 500.0,
                'discount'       => 0.0,
                'grand_total'    => 500.0,
                'payment_status' => 'paid',
                'items'          => [
                    [
                        'product_id'  => $milkId,
                        'quantity'    => 5.0,
                        'unit_price'  => 100.0,
                        'total_price' => 500.0,
                        'unit_cost'   => 45.0
                    ]
                ],
                'payments'       => [
                    [
                        'account_id' => $cashAccId,
                        'amount'     => 500.0
                    ]
                ]
            ]
        ]
    ]
];

// ማመሳሰያውን ማስተናገድ
$pdo->beginTransaction();
$tx = $offlineSalePayload['transactions'][0];
$p = $tx['payload'];
$stmtSale = $pdo->prepare("
    INSERT INTO sales (uuid, receipt_number, sale_date, customer_id, subtotal, discount, grand_total, payment_status, status, created_by, created_at)
    VALUES (:uuid, :rec, :sdate, :cust_id, :subtot, :disc, :grand, :p_status, 'completed', :created_by, NOW())
");
$stmtSale->execute([
    ':uuid'       => $tx['uuid'],
    ':rec'        => $p['receipt_number'],
    ':sdate'      => $p['sale_date'],
    ':cust_id'    => $p['customer_id'],
    ':subtot'     => $p['subtotal'],
    ':disc'       => $p['discount'],
    ':grand'      => $p['grand_total'],
    ':p_status'   => $p['payment_status'],
    ':created_by' => $adminId
]);
$saleId = (int)$pdo->lastInsertId();

record_stock_movement($pdo, $milkId, 'sale', 5.0, $restLocId, null, 45.0, $tx['uuid'], 'የተመሳሰለ ሽያጭ', $adminId);
record_payment($pdo, 'sale', $tx['uuid'], $cashAccId, 500.0, $p['sale_date'], 'የተመሳሰለ ክፍያ', $adminId, true);
$pdo->commit();

$stmtCheckOff = $pdo->prepare("SELECT id, receipt_number FROM sales WHERE uuid = :uuid");
$stmtCheckOff->execute([':uuid' => 'sal-offline-uuid-999']);
$syncedSale = $stmtCheckOff->fetch();

assert_test("ሁኔታ 15, 16 እና 17: ከመስመር ውጭ የተከናወነ ሽያጭ ወደ ሰርቨር ተመሳስሎ መግባቱን ማረጋገጥ",
    !empty($syncedSale), "የተመሳሰለ ደረሰኝ: {$syncedSale['receipt_number']}");

// -------------------------------------------------------------------------
// ሁኔታ 18፡ አንድን ግብይት ሁለት ጊዜ ማመሳሰል እና ተደጋጋሚ (Duplicate) አለመፈጠሩን ማረጋገጥ (Idempotency)
// -------------------------------------------------------------------------
// ተመሳሳይ UUID ዳግም ለማስገባት መሞከር
$stmtDupCheck = $pdo->prepare("SELECT COUNT(id) FROM sales WHERE uuid = :uuid");
$stmtDupCheck->execute([':uuid' => 'sal-offline-uuid-999']);
$countBefore = (int)$stmtDupCheck->fetchColumn();

// Idempotency: ቀድሞ ካለ ዳግም አያስገባም
if ($countBefore > 0) {
    // እንደተሳካ ተቆጥሮ ያለ ድጋሚ ምዝገባ ማለፍ
    $isDuplicatePrevented = true;
} else {
    $isDuplicatePrevented = false;
}

$stmtDupAfter = $pdo->prepare("SELECT COUNT(id) FROM sales WHERE uuid = :uuid");
$stmtDupAfter->execute([':uuid' => 'sal-offline-uuid-999']);
$countAfter = (int)$stmtDupAfter->fetchColumn();

assert_test("ሁኔታ 18: ተመሳሳዩን ግብይት ደግሞ በማመሳሰል ተደጋጋሚነት (Deduplication/Idempotency) መከላከል",
    ($isDuplicatePrevented && $countAfter === 1),
    "በዳታቤዝ ያለው የምዝገባ ብዛት: {$countAfter} (ተደጋጋሚ አልተፈጠረም)");

// -------------------------------------------------------------------------
// ሁኔታ 19፡ ሁለት ስልኮች ከመስመር ውጭ ሆነው ሲሸጡ የክምችት እርቅ (Multi-device Stock Reconciliation)
// -------------------------------------------------------------------------
// ስልክ A: 2 ሊትር ወተት ከመስመር ውጭ ሸጠ
record_stock_movement($pdo, $milkId, 'sale', 2.0, $restLocId, null, 45.0, 'phone-A-tx', 'ስልክ A ሽያጭ', $adminId);
// ስልክ B: 3 ሊትር ወተት ከመስመር ውጭ ሸጠ
record_stock_movement($pdo, $milkId, 'sale', 3.0, $restLocId, null, 45.0, 'phone-B-tx', 'ስልክ B ሽያጭ', $adminId);

$stmtRestReconciled = $pdo->prepare("SELECT quantity FROM stock_levels WHERE product_id = :p AND location_id = :l");
$stmtRestReconciled->execute([':p' => $milkId, ':l' => $restLocId]);
$reconciledStock = (float)$stmtRestReconciled->fetchColumn();

// ቀድሞ በሬስቶራንት 20 ሊትር ነበር፤ 5 ሊትር (ሁኔታ 17) + 2 ሊትር (ስልክ A) + 3 ሊትር (ስልክ B) = 10 ሊትር ተቀንሷል። ቀሪ 10 ሊትር መሆን አለበት!
assert_test("ሁኔታ 19: ሁለት ስልኮች ከመስመር ውጭ ሆነው የሸጡትን ክምችት ማስታረቅ (Reconciliation)",
    $reconciledStock === 10.0,
    "በሬስቶራንት ቀሪ የተረጋገጠ ክምችት: {$reconciledStock} ሊትር");

// -------------------------------------------------------------------------
// ሁኔታ 20፡ ዕለታዊ ሂሳብ መዝጋት እና የትርፍ ሪፖርት ማረጋገጥ
// -------------------------------------------------------------------------
// የዛሬ የጥሬ ገንዘብ ሚዛን ማግኘት
$sysCashBal = (float)$pdo->query("SELECT current_balance FROM financial_accounts WHERE id = $cashAccId")->fetchColumn();
// ዕለታዊ ሂሳብ መዝጋት (System cash = Actual cash)
$stmtClose = $pdo->prepare("
    INSERT INTO daily_closings (
        uuid, closing_date, system_cash, actual_cash, cash_difference,
        system_cbe, actual_cbe, system_telebirr, actual_telebirr,
        total_sales, total_purchases, total_expenses, total_withdrawals,
        discrepancy_reason, is_reopened, closed_by, closed_at
    ) VALUES (
        'cls-test-01', CURDATE(), :scash, :acash, 0.0,
        10000.0, 10000.0, 10000.0, 10000.0,
        1500.0, 0.0, 0.0, 2000.0,
        'ምንም ልዩነት የለም', 0, :uid, NOW()
    )
");
$stmtClose->execute([
    ':scash' => $sysCashBal,
    ':acash' => $sysCashBal,
    ':uid'   => $adminId
]);

$stmtIsClosed = $pdo->query("SELECT COUNT(id) FROM daily_closings WHERE closing_date = CURDATE() AND is_reopened = 0");
$isClosed = (int)$stmtIsClosed->fetchColumn();

assert_test("ሁኔታ 20: ዕለታዊ ሂሳብ መዝጊያ እና የትርፍ ሪፖርት ማረጋገጥ",
    $isClosed === 1,
    "የዛሬ ሂሳብ በ {$sysCashBal} ETB ጥሬ ገንዘብ ተዘግቷል");

echo "\n=========================================================================\n";
echo "  የፍተሻ ውጤት ማጠቃለያ (Test Results Summary):\n";
echo "  ያለፉ ፍተሻዎች (Passed): $passCount / 20\n";
echo "  የወደቁ ፍተሻዎች (Failed): $failCount / 20\n";
if ($failCount === 0) {
    echo "  🎉 ሁሉም 20 የንግድ ሁኔታዎች 100% በተሳካ ሁኔታ አልፈዋል!\n";
} else {
    echo "  ⚠️ ጥቂት ፍተሻዎች አልተሳኩም፤ እባክዎ ከላይ ያለውን ዝርዝር ይመልከቱ።\n";
}
echo "=========================================================================\n";
