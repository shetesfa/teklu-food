<?php
/**
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

echo "✅ ስኪማ እና ሲድ ዳታ በንጹህ UTF-8 በተሳካ ሁኔታ ገብቷል!\n";

// ማረጋገጥ
$users = $pdo->query("SELECT id, username, full_name, role FROM users")->fetchAll();
echo "ተጠቃሚዎች:\n";
foreach ($users as $u) {
    echo "- ID: {$u['id']} | {$u['username']} | {$u['full_name']} | {$u['role']}\n";
}

$prods = $pdo->query("SELECT id, uuid, name_am, name_en, selling_price FROM products LIMIT 5")->fetchAll();
echo "ምርቶች:\n";
foreach ($prods as $p) {
    echo "- ID: {$p['id']} | {$p['name_am']} ({$p['name_en']}) | {$p['selling_price']} ETB\n";
}
