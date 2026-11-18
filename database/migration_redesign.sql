-- =========================================================================
-- ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
-- አጠቃላይ የስርዓት ዳግም ማስተካከያ እና ማጽጃ ስክሪፕት (System Redesign & Cleanup Migration)
-- =========================================================================

USE teklu_business_db;

-- 1. የእርሻ እና አላስፈላጊ ሰንጠረዦችን ሙሉ በሙሉ ማስወገድ
DROP TABLE IF EXISTS farm_productions;
DROP TABLE IF EXISTS internal_transfers;
DROP TABLE IF EXISTS restaurant_order_items;
DROP TABLE IF EXISTS restaurant_orders;

-- 2. የማከማቻ ቦታዎችን (Stock Locations) ማስተካከል - አንድ ማዕከላዊ የድርጅት ክምችት ብቻ
INSERT INTO stock_locations (id, uuid, code, name_en, name_am, created_at)
VALUES (1, 'loc-main-company-stock', 'main_storage', 'Main Company Inventory', 'ማዕከላዊ የድርጅቱ ክምችት', NOW())
ON DUPLICATE KEY UPDATE name_en = 'Main Company Inventory', name_am = 'ማዕከላዊ የድርጅቱ ክምችት';

-- የነበሩትን የተለያዩ ቦታዎች ክምችቶች ወደ ዋናው መጋዘን (id=1) ማጠቃለል
CREATE TEMPORARY TABLE IF NOT EXISTS temp_stock_merge AS 
SELECT product_id, 1 as location_id, SUM(quantity) as total_qty, MAX(updated_at) as max_upd
FROM stock_levels
GROUP BY product_id;

DELETE FROM stock_levels;

INSERT INTO stock_levels (product_id, location_id, quantity, updated_at)
SELECT product_id, location_id, total_qty, max_upd FROM temp_stock_merge;

DROP TEMPORARY TABLE IF EXISTS temp_stock_merge;

UPDATE stock_movements SET from_location_id = 1 WHERE from_location_id IS NOT NULL AND from_location_id != 1;
UPDATE stock_movements SET to_location_id = 1 WHERE to_location_id IS NOT NULL AND to_location_id != 1;
UPDATE purchase_items SET location_id = 1 WHERE location_id != 1;

-- አላስፈላጊ የእርሻ መጋዘኖችን መሰረዝ
DELETE FROM stock_locations WHERE id != 1;

-- 3. የምርት ሰንጠረዥን ማስተካከል (Products Table Corrections)
ALTER TABLE products MODIFY COLUMN unit VARCHAR(30) NOT NULL DEFAULT 'piece';
ALTER TABLE products MODIFY COLUMN source VARCHAR(50) NOT NULL DEFAULT 'company';

-- 4. የግዢዎች ሰንጠረዥን ማስተካከል (Purchases / Goods Receiving)
ALTER TABLE purchases ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(14,2) DEFAULT 0.00 AFTER grand_total;
ALTER TABLE purchases ADD COLUMN IF NOT EXISTS unpaid_balance DECIMAL(14,2) DEFAULT 0.00 AFTER paid_amount;
ALTER TABLE purchases ADD COLUMN IF NOT EXISTS payment_account_id INT NULL AFTER unpaid_balance;

-- 5. የገንዘብ መውጣት ሰንጠረዥን ማስተካከል (Withdrawals)
ALTER TABLE withdrawals ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER reason;

-- 6. የእውቂያዎች ሰንጠረዥን ማስተካከል (Contacts / Suppliers)
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER address;

-- 7. የወጪ ሰንጠረዥ ላይ 'farm' መምሪያን ወደ 'general' መቀየር
ALTER TABLE expenses MODIFY COLUMN department VARCHAR(50) NOT NULL DEFAULT 'general';
UPDATE expenses SET department = 'general' WHERE department = 'farm';

-- 8. ነባር የምርት ምድቦችን (Categories) ከእርሻ የጸዱ እና ለምግብ/ጥሬ ዕቃ ተስማሚ ማድረግ
UPDATE categories SET name_am = 'ጥሬ እቃዎች እና ግብዓቶች', name_en = 'Raw Materials & Ingredients' WHERE id = 1;
UPDATE categories SET name_am = 'የተዘጋጁ ምግቦች', name_en = 'Prepared Foods & Meals' WHERE id = 4;
UPDATE categories SET name_am = 'የታሸጉ ምርቶች', name_en = 'Packaged Products' WHERE id = 2;
UPDATE categories SET name_am = 'መጠጦች', name_en = 'Beverages' WHERE id = 3;

INSERT INTO categories (id, uuid, name_en, name_am, type, created_at)
VALUES 
(5, 'cat-spices-01', 'Spices & Condiments', 'ቅመማ ቅመሞች እና ማጣፈጫዎች', 'product', NOW()),
(6, 'cat-grains-01', 'Flour & Bakery Materials', 'ዱቄት እና የዳቦ መጋገሪያ ግብዓቶች', 'product', NOW())
ON DUPLICATE KEY UPDATE name_en = VALUES(name_en), name_am = VALUES(name_am);

-- 9. የፋይናንስ አካውንቶች መነሻ ሚዛን
INSERT INTO financial_accounts (id, uuid, code, name_en, name_am, current_balance, is_active)
VALUES 
(1, 'acc-cash-01', 'cash', 'Cash in Hand', 'የእጅ ጥሬ ገንዘብ', 10000.00, 1),
(2, 'acc-cbe-01', 'cbe', 'Commercial Bank of Ethiopia (CBE)', 'የኢትዮጵያ ንግድ ባንክ', 10000.00, 1),
(3, 'acc-telebirr-01', 'telebirr', 'Telebirr', 'ቴሌብር (Telebirr)', 10000.00, 1)
ON DUPLICATE KEY UPDATE is_active = 1;
