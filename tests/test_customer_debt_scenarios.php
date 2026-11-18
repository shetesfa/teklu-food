<?php
/**
 * ተክሉ ምግብ ቤት (Teklu Restaurant Management)
 * የደንበኛ ዱቤ፣ ከፊል ክፍያ እና ደረሰኝ ፍተሻ (Customer Debts & Statement Test Runner)
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/accounting.php';

$pdo = get_db_connection();

echo "=========================================================================\n";
echo "  ተክሉ ምግብ ቤት — የደንበኛ ዱቤ፣ ከፊል ክፍያ እና ደረሰኝ ፍተሻ\n";
echo "=========================================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "✅ [አልፏል / PASS] $name\n";
        if ($details) echo "    ↳ $details\n";
    } else {
        $failed++;
        echo "❌ [ወድቋል / FAIL] $name\n";
        if ($details) echo "    ↳ $details\n";
    }
}

// 1. አዲስ ደንበኛ መፍጠር
$custName = 'አቶ በቀለ ደስታ ' . time();
$stmt = $pdo->prepare("INSERT INTO contacts (uuid, type, name, phone, address, outstanding_balance, created_at) VALUES (:u, 'customer', :n, '0911223344', 'ቦሌ', 0.00, NOW())");
$custUuid = 'cust-' . bin2hex(random_bytes(6));
$stmt->execute([':u' => $custUuid, ':n' => $custName]);
$custId = (int)$pdo->lastInsertId();

assert_test("TEST 1: አዲስ ደንበኛ መመዝገብ", $custId > 0, "የደንበኛ ID: $custId, ስም: $custName");

// 2. በብድር ሽያጭ መፈጸም (500 ETB)
$saleUuid = 'sal-test-' . bin2hex(random_bytes(6));
$recNum = 'REC-TEST-' . rand(1000, 9999);
$stmtSale = $pdo->prepare("
    INSERT INTO sales (uuid, receipt_number, sale_date, customer_id, subtotal, discount, grand_total, payment_status, status, created_by, created_at)
    VALUES (:u, :rec, NOW(), :cid, 500.00, 0.00, 500.00, 'credit', 'completed', 1, NOW())
");
$stmtSale->execute([':u' => $saleUuid, ':rec' => $recNum, ':cid' => $custId]);
$saleId = (int)$pdo->lastInsertId();

// የሽያጭ እቃዎች ማከል (2x ምግብ)
$prodId = (int)$pdo->query("SELECT id FROM products LIMIT 1")->fetchColumn();
$stmtItem = $pdo->prepare("
    INSERT INTO sale_items (uuid, sale_id, product_id, quantity, unit_price, total_price, unit_cost)
    VALUES (:u, :sid, :pid, 2, 250.00, 500.00, 44.50)
");
$stmtItem->execute([':u' => bin2hex(random_bytes(8)), ':sid' => $saleId, ':pid' => $prodId]);

// የደንበኛውን ዕዳ ማዘመን
$pdo->prepare("UPDATE contacts SET outstanding_balance = outstanding_balance + 500.00 WHERE id = :id")->execute([':id' => $custId]);

// ማረጋገጥ
$stmtCheckCust = $pdo->prepare("SELECT outstanding_balance FROM contacts WHERE id = :id");
$stmtCheckCust->execute([':id' => $custId]);
$curDebt = (float)$stmtCheckCust->fetchColumn();

assert_test("TEST 2: በብድር ሽያጭ ሲደረግ የደንበኛው ዕዳ መጨመሩን ማረጋገጥ", $curDebt === 500.0, "የአሁኑ የደንበኛው እዳ: $curDebt ETB (የተጠበቀው: 500.00 ETB)");

// 3. ከፊል ክፍያ መፈጸም (200 ETB መክፈል)
$payRef = 'cust-pay-' . $custId . '-' . time();
record_payment(
    $pdo,
    'customer_credit_settlement',
    $payRef,
    1, // ጥሬ ገንዘብ
    200.00,
    date('Y-m-d H:i:s'),
    'የከፊል ዕዳ ክፍያ',
    1,
    true
);
$pdo->prepare("UPDATE contacts SET outstanding_balance = GREATEST(0, outstanding_balance - 200.00) WHERE id = :id")->execute([':id' => $custId]);

$stmtCheckCust->execute([':id' => $custId]);
$remDebt = (float)$stmtCheckCust->fetchColumn();

assert_test("TEST 3: ከፊል ክፍያ (200 ብር) ሲከፈል ቀሪው ዕዳ 300 ብር መሆኑን ማረጋገጥ", $remDebt === 300.0, "የቀረው ያልተከፈለ ዕዳ: $remDebt ETB (የተጠበቀው: 300.00 ETB)");

// 4. ሁለተኛ ክፍያ (የቀረውን 300 ETB ሙሉ በሙሉ መክፈል)
$payRef2 = 'cust-pay-' . $custId . '-' . (time() + 1);
record_payment(
    $pdo,
    'customer_credit_settlement',
    $payRef2,
    1,
    300.00,
    date('Y-m-d H:i:s'),
    'ቀሪ ሙሉ ክፍያ',
    1,
    true
);
$pdo->prepare("UPDATE contacts SET outstanding_balance = GREATEST(0, outstanding_balance - 300.00) WHERE id = :id")->execute([':id' => $custId]);

$stmtCheckCust->execute([':id' => $custId]);
$finalDebt = (float)$stmtCheckCust->fetchColumn();

assert_test("TEST 4: ቀሪው 300 ብር ሙሉ ሲከፈል ዕዳው 0 ብር መሆኑን ማረጋገጥ", $finalDebt === 0.0, "የመጨረሻ ዕዳ: $finalDebt ETB (የተጠበቀው: 0.00 ETB)");

echo "\n=========================================================================\n";
echo "  የፍተሻ ውጤት: ያለፉ: $passed | የወደቁ: $failed\n";
echo "=========================================================================\n";
