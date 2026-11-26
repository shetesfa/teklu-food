-- ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
-- የመነሻ ዳታ (Seed Data for Food, Recipes, Suppliers, and Accounts)

USE teklu_business_db;

-- 1. ተጠቃሚዎች
INSERT INTO users (id, uuid, username, password_hash, full_name, role, permissions, is_active, created_at)
VALUES 
(1, 'usr-admin-01', 'admin', '$2y$10$w0f5eF1VnBqD6Z9lS5f7xO7xK8aV2qD9w0f5eF1VnBqD6Z9lS5f7x.', 'ተክሉ ጌታቸው (ዋና አስተዳዳሪ)', 'super_admin', '{"all": true}', 1, NOW()),
(2, 'usr-mgr-01', 'manager', '$2y$10$8u1dD9w0f5eF1VnBqD6Z9lS5f7xO7xK8aV2qD9w0f5eF1VnBqD6Z.', 'አበበ በቀለ (አስተዳዳሪ)', 'admin', '{"can_discount": true, "can_adjust_stock": true, "can_view_pl": true, "can_record_withdrawal": true}', 1, NOW())
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), role = VALUES(role);

-- የይለፍ ቃሎችን ትክክለኛ Hash ማስገባት
UPDATE users SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE id = 1; -- Admin@123 ወይም እንደ አስፈላጊነቱ በ PHP script hash እናደርገዋለን
UPDATE users SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE id = 2;

-- 2. የማከማቻ ቦታ
INSERT INTO stock_locations (id, uuid, code, name_en, name_am, created_at)
VALUES (1, 'loc-main-company-stock', 'main_storage', 'Main Company Inventory', 'ማዕከላዊ የድርጅቱ ክምችት', NOW())
ON DUPLICATE KEY UPDATE name_en = VALUES(name_en), name_am = VALUES(name_am);

-- 3. የፋይናንስ አካውንቶች (Financial Accounts)
INSERT INTO financial_accounts (id, uuid, code, name_en, name_am, current_balance, is_active)
VALUES 
(1, 'acc-cash-01', 'cash', 'Cash in Hand', 'የእጅ ጥሬ ገንዘብ', 10000.00, 1),
(2, 'acc-cbe-01', 'cbe', 'Commercial Bank of Ethiopia (CBE)', 'የኢትዮጵያ ንግድ ባንክ', 10000.00, 1),
(3, 'acc-telebirr-01', 'telebirr', 'Telebirr', 'ቴሌብር (Telebirr)', 10000.00, 1)
ON DUPLICATE KEY UPDATE is_active = 1;

-- 4. ምድቦች (Categories)
INSERT INTO categories (id, uuid, name_en, name_am, type, created_at)
VALUES 
(1, 'cat-raw-01', 'Raw Materials & Ingredients', 'ጥሬ እቃዎች እና ግብዓቶች', 'product', NOW()),
(2, 'cat-pkg-01', 'Packaged Products', 'የታሸጉ ምርቶች', 'product', NOW()),
(3, 'cat-bev-01', 'Beverages', 'መጠጦች', 'product', NOW()),
(4, 'cat-food-01', 'Prepared Foods & Meals', 'የተዘጋጁ ምግቦች', 'product', NOW()),
(5, 'cat-spices-01', 'Spices & Condiments', 'ቅመማ ቅመሞች እና ማጣፈጫዎች', 'product', NOW()),
(10, 'cat-exp-rent', 'Rent Expense', 'የቤት ኪራይ ወጪ', 'expense', NOW()),
(11, 'cat-exp-util', 'Utilities (Water/Electric)', 'መብራት እና ውሃ', 'expense', NOW()),
(12, 'cat-exp-salary', 'Salaries & Wages', 'የሰራተኛ ደመወዝ', 'expense', NOW()),
(13, 'cat-exp-transport', 'Transport & Fuel', 'ትራንስፖርት እና ነዳጅ', 'expense', NOW()),
(14, 'cat-exp-maint', 'Maintenance & Repairs', 'ጥገና እና እድሳት', 'expense', NOW())
ON DUPLICATE KEY UPDATE name_en = VALUES(name_en), name_am = VALUES(name_am);

-- 5. ጥሬ እቃዎች እና ምርቶች (Raw Materials & Products)
INSERT INTO products (id, uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost, selling_price, min_stock_level, source, is_active, created_at)
VALUES 
(1, 'prd-onion-01', 'Onion', 'ቀይ ሽንኩርት', 1, 'kg', 60.00, 0.00, 0.00, 5.00, 'company', 1, NOW()),
(2, 'prd-tomato-01', 'Tomato', 'ቲማቲም', 1, 'kg', 50.00, 0.00, 0.00, 5.00, 'company', 1, NOW()),
(3, 'prd-oil-01', 'Cooking Oil', 'የምግብ ዘይት', 1, 'liter', 150.00, 0.00, 0.00, 5.00, 'company', 1, NOW()),
(4, 'prd-injera-01', 'Injera', 'እንጀራ', 1, 'piece', 15.00, 0.00, 0.00, 10.00, 'company', 1, NOW()),
(5, 'prd-berbere-01', 'Berbere', 'በርበሬ', 5, 'kg', 250.00, 0.00, 0.00, 2.00, 'company', 1, NOW()),
(6, 'prd-flour-01', 'Wheat Flour', 'የስንዴ ዱቄት', 1, 'kg', 85.00, 0.00, 0.00, 10.00, 'company', 1, NOW()),
(7, 'prd-biscuit-01', 'Biscuit', 'ብስኩት', 2, 'piece', 10.00, 0.00, 15.00, 20.00, 'company', 1, NOW()),
(8, 'prd-water-01', 'Bottled Water 500ml', 'የታሸገ ውሃ 500ml', 3, 'bottle', 15.00, 0.00, 25.00, 15.00, 'company', 1, NOW()),
(9, 'prd-milk-01', 'Fresh Milk', 'ትኩስ ወተት', 1, 'liter', 65.00, 0.00, 80.00, 10.00, 'company', 1, NOW())
ON DUPLICATE KEY UPDATE name_en = VALUES(name_en), name_am = VALUES(name_am), unit = VALUES(unit), purchase_cost = VALUES(purchase_cost), selling_price = VALUES(selling_price);

-- 6. የተዘጋጀ ምግብ፡ ፍርፍር (Firfir)
-- Cost per serving:
-- Onion: 0.2 kg * 60 = 12 ETB
-- Tomato: 0.1 kg * 50 = 5 ETB
-- Oil: 0.05 liter * 150 = 7.5 ETB
-- Injera: 1 piece * 15 = 15 ETB
-- Berbere: 0.02 kg * 250 = 5 ETB
-- Total COGS = 44.50 ETB, Selling Price = 120.00 ETB
INSERT INTO products (id, uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost, selling_price, min_stock_level, source, is_active, created_at)
VALUES 
(20, 'food-firfir-01', 'Firfir Special', 'ፍርፍር (ልዩ)', 4, 'plate', 0.00, 44.50, 120.00, 0.00, 'company', 1, NOW())
ON DUPLICATE KEY UPDATE name_en = VALUES(name_en), name_am = VALUES(name_am), production_cost = VALUES(production_cost), selling_price = VALUES(selling_price);

-- 7. የፍርፍር ሬሲፒ ግብዓቶች (Firfir Recipe Ingredients)
DELETE FROM recipes WHERE parent_product_id = 20;
INSERT INTO recipes (uuid, parent_product_id, ingredient_product_id, quantity_needed, unit, created_at)
VALUES 
('rcp-ff-01', 20, 1, 0.200, 'kg', NOW()),    -- Onion: 0.2 kg
('rcp-ff-02', 20, 2, 0.100, 'kg', NOW()),    -- Tomato: 0.1 kg
('rcp-ff-03', 20, 3, 0.050, 'liter', NOW()), -- Oil: 0.05 liter
('rcp-ff-04', 20, 4, 1.000, 'piece', NOW()), -- Injera: 1 piece
('rcp-ff-05', 20, 5, 0.020, 'kg', NOW());    -- Berbere: 0.02 kg

-- 8. የመክፈቻ ክምችቶች (Initial Stock Levels in Main Company Inventory)
INSERT INTO stock_levels (product_id, location_id, quantity, updated_at)
VALUES 
(1, 1, 50.000, NOW()),   -- Onion 50 kg
(2, 1, 40.000, NOW()),   -- Tomato 40 kg
(3, 1, 30.000, NOW()),   -- Oil 30 L
(4, 1, 100.000, NOW()),  -- Injera 100 pcs
(5, 1, 15.000, NOW()),   -- Berbere 15 kg
(6, 1, 80.000, NOW()),   -- Flour 80 kg
(7, 1, 120.000, NOW()),  -- Biscuit 120 pcs
(8, 1, 200.000, NOW()),  -- Water 200 bottles
(9, 1, 60.000, NOW())    -- Milk 60 L
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

-- 9. አቅራቢዎች እና ደንበኞች (Suppliers & Customers)
INSERT INTO contacts (id, uuid, type, name, phone, address, notes, outstanding_balance, created_at)
VALUES 
(1, 'cnt-sup-01', 'supplier', 'ABC Biscuit Company', '0911223344', 'አዲስ አበባ', 'የብስኩት እና ጣፋጮች አቅራቢ', 0.00, NOW()),
(2, 'cnt-sup-02', 'supplier', 'መርካቶ የቅመማ ቅመም ነጋዴዎች', '0922334455', 'መርካቶ', 'የሽንኩርት፣ ቲማቲም እና ቅመማ ቅመም አቅራቢ', 0.00, NOW()),
(3, 'cnt-cust-01', 'customer', 'አቶ ዳዊት ገብሬ (ተራ ደንበኛ)', '0933445566', 'ፒያሳ', 'መደበኛ ደንበኛ', 0.00, NOW())
ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone);
