<?php
/**
 * ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
 * የዳታቤዝ መረጃዎችን በንጹህ UTF-8 ማስገቢያ ስክሪፕት
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

$pdo = get_db_connection();

echo "MariaDB ስኪማ እና መረጃዎችን በ UTF-8 በማስገባት ላይ...\n";

// 1. ስኪማ ማስኬድ
$schemaSql = file_get_contents(__DIR__ . '/schema.sql');
$pdo->exec($schemaSql);

// 2. ሲድ ዳታ ማስኬድ
$seedSql = file_get_contents(__DIR__ . '/seed.sql');
$pdo->exec($seedSql);

// 3. የይለፍ ቃሎችን ትክክለኛ Hash ማስገባት
$adminHash = password_hash('Admin@123', PASSWORD_BCRYPT);
$mgrHash = password_hash('Manager@123', PASSWORD_BCRYPT);

$pdo->prepare("UPDATE users SET password_hash = :p WHERE username = 'admin'")->execute([':p' => $adminHash]);
$pdo->prepare("UPDATE users SET password_hash = :p WHERE username = 'manager'")->execute([':p' => $mgrHash]);

echo "✅ ስኪማ እና ሲድ ዳታ በንጹህ UTF-8 በተሳካ ሁኔታ ገብቷል!\n";

// ማረጋገጥ
$users = $pdo->query("SELECT id, username, full_name, role FROM users")->fetchAll();
echo "ተጠቃሚዎች:\n";
foreach ($users as $u) {
    echo "- ID: {$u['id']} | {$u['username']} | {$u['full_name']} | {$u['role']}\n";
}

$prods = $pdo->query("SELECT id, name_am, name_en, selling_price, unit FROM products LIMIT 6")->fetchAll();
echo "ምርቶች:\n";
foreach ($prods as $p) {
    echo "- ID: {$p['id']} | {$p['name_am']} ({$p['name_en']}) | {$p['selling_price']} ETB per {$p['unit']}\n";
}

$recipes = $pdo->query("SELECT r.*, p.name_am as food_name, ing.name_am as ing_name FROM recipes r JOIN products p ON r.parent_product_id = p.id JOIN products ing ON r.ingredient_product_id = ing.id")->fetchAll();
echo "የምግብ ሬሲፒዎች:\n";
foreach ($recipes as $r) {
    echo "- {$r['food_name']} -> ግብዓት: {$r['ing_name']} ({$r['quantity_needed']} {$r['unit']})\n";
}
