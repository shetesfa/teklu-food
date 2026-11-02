-- የመነሻ መረጃዎች (Seed Data)
USE teklu_business_db;

-- 1. ተጠቃሚዎች
INSERT INTO users (uuid, username, password_hash, full_name, role, permissions, is_active) VALUES
('u-admin-001', 'admin', '$2y$10$ZLoo11fReynETUVR4MeB9eRA7IhhMYV8jP45fOKWV48X.wnHl4ULG', 'ተክሉ ጌታቸው (ዋና አስተዳዳሪ)', 'super_admin', '{"all": true}', 1),
('u-mgr-001', 'manager', '$2y$10$syq2wzyP6.mpA8wzR72PG.bZ4GZncFjMgHQmjk1KzQj4mrR8uyNDa', 'አበበ በቀለ (ስራ አስኪያጅ)', 'manager', '{"can_discount": true, "max_discount_pct": 10, "can_adjust_stock": true, "can_record_withdrawal": false, "can_view_pl": true}', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);

-- 2. መጋዘኖች / የዕቃ ማከማቻ ቦታዎች
INSERT INTO stock_locations (uuid, code, name_en, name_am) VALUES
('loc-farm-01', 'farm_warehouse', 'Farm Warehouse', 'የእርሻ መጋዘን'),
('loc-rest-01', 'restaurant_stock', 'Restaurant Stock', 'የሬስቶራንት ክምችት'),
('loc-main-01', 'main_storage', 'Main Storage', 'ዋና ማከማቻ')
ON DUPLICATE KEY UPDATE name_am = VALUES(name_am);

-- 3. የፋይናንስ ሂሳቦች
INSERT INTO financial_accounts (uuid, code, name_en, name_am, current_balance) VALUES
('acc-cash-01', 'cash', 'Cash in Hand', 'የእጅ ጥሬ ገንዘብ', 0.00),
('acc-cbe-01', 'cbe', 'Commercial Bank of Ethiopia (CBE)', 'የኢትዮጵያ ንግድ ባንክ (CBE)', 0.00),
('acc-telebirr-01', 'telebirr', 'Telebirr', 'ቴሌብር (Telebirr)', 0.00),
('acc-otherbank-01', 'other_bank', 'Other Banks (Awash/Dashen)', 'ሌሎች ባንኮች (አዋሽ/ዳሽን)', 0.00),
('acc-othermob-01', 'other_mobile', 'Other Mobile Money', 'ሌሎች የሞባይል ክፍያዎች', 0.00)
ON DUPLICATE KEY UPDATE name_am = VALUES(name_am);

-- 4. የምርት እና ወጪ ምድቦች
INSERT INTO categories (uuid, name_en, name_am, type) VALUES
('cat-prod-01', 'Dairy Products', 'የወተት ተዋጽኦ', 'product'),
('cat-prod-02', 'Farm Produce & Livestock', 'የእርሻና ከብት ምርቶች', 'product'),
('cat-prod-03', 'Meat & Poultry', 'ስጋ እና ዶሮ', 'product'),
('cat-prod-04', 'Restaurant Meals', 'የሬስቶራንት ምግቦች', 'product'),
('cat-prod-05', 'Beverages & Soft Drinks', 'መጠጦችና ለስላሳዎች', 'product'),
('cat-prod-06', 'Cooking Supplies & Spices', 'የማብሰያ ግብዓቶችና ቅመማ ቅመም', 'product'),
('cat-exp-01', 'Electricity & Water (Utilities)', 'መብራት እና ውሃ', 'expense'),
('cat-exp-02', 'Salaries & Wages', 'ደመወዝ እና የቀን ክፍያ', 'expense'),
('cat-exp-03', 'Transportation & Fuel', 'ትራንስፖርት እና ነዳጅ', 'expense'),
('cat-exp-04', 'Animal Feed & Veterinary', 'የከብት መኖ እና ህክምና', 'expense'),
('cat-exp-05', 'Maintenance & Repairs', 'ጥገና እና እደሳ', 'expense'),
('cat-exp-06', 'General Operating Expenses', 'አጠቃላይ የስራ ማስኬጃ', 'expense')
ON DUPLICATE KEY UPDATE name_am = VALUES(name_am);

-- 5. መሰረታዊ ምርቶች
INSERT INTO products (uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost, selling_price, min_stock_level, source, has_expiry) VALUES
('prd-milk-01', 'Raw Fresh Milk', 'ጥሬ ትኩስ ወተት', 1, 'liter', 0.00, 45.00, 65.00, 50.00, 'farm', 1),
('prd-pasteur-01', 'Pasteurized Milk', 'የተቀነባበረ/የፈላ ወተት', 1, 'liter', 0.00, 55.00, 80.00, 30.00, 'farm', 1),
('prd-butter-01', 'Farm Table Butter', 'የእርሻ ጠረጴዛ ቅቤ', 1, 'kg', 0.00, 550.00, 750.00, 10.00, 'farm', 1),
('prd-cheese-01', 'Ayib / Cottage Cheese', 'አይብ (የወተት ተዋጽኦ)', 1, 'kg', 0.00, 180.00, 250.00, 15.00, 'farm', 1),
('prd-beef-01', 'Raw Beef Meat', 'ጥሬ የበሬ ስጋ', 3, 'kg', 500.00, 0.00, 700.00, 20.00, 'external', 1),
('prd-onion-01', 'Red Onions', 'ቀይ ሽንኩርት', 6, 'kg', 60.00, 0.00, 90.00, 25.00, 'external', 1),
('prd-oil-01', 'Cooking Oil', 'የማብሰያ ዘይት', 6, 'liter', 150.00, 0.00, 200.00, 20.00, 'external', 0),
('prd-egg-01', 'Farm Eggs', 'የእርሻ እንቁላል', 2, 'piece', 12.00, 0.00, 18.00, 50.00, 'farm', 1),
('prd-water-01', 'Bottled Water 1L', 'የታሸገ ውሃ 1 ሊትር', 5, 'bottle', 20.00, 0.00, 35.00, 40.00, 'external', 0),
('prd-softdrink-01', 'Soft Drink (Coca/Sprite)', 'ለስላሳ መጠጥ', 5, 'bottle', 25.00, 0.00, 45.00, 40.00, 'external', 0),
('prd-tibs-01', 'Special Beef Tibs', 'ልዩ የበሬ ጥብስ', 4, 'plate', 0.00, 0.00, 320.00, 0.00, 'restaurant', 0),
('prd-kurt-01', 'Fresh Kurt Meat', 'ትኩስ ቁርጥ ስጋ (ኪሎ)', 4, 'kg', 0.00, 0.00, 850.00, 0.00, 'restaurant', 0),
('prd-tea-01', 'Fresh Milk Tea (Shai be-Wetet)', 'ሻይ በወተት', 4, 'cup', 0.00, 0.00, 40.00, 0.00, 'restaurant', 0)
ON DUPLICATE KEY UPDATE name_am = VALUES(name_am);

-- 6. የምግብ አዘገጃጀት (Recipes)
-- የበሬ ጥብስ (Special Beef Tibs): 0.25 kg ስጋ + 0.10 kg ሽንኩርት + 0.05 liter ዘይት
INSERT INTO recipes (uuid, parent_product_id, ingredient_product_id, quantity_needed, unit) VALUES
('rcp-tibs-meat', 11, 5, 0.250, 'kg'),
('rcp-tibs-onion', 11, 6, 0.100, 'kg'),
('rcp-tibs-oil', 11, 7, 0.050, 'liter'),
-- ሻይ በወተት: 0.15 liter ወተት
('rcp-tea-milk', 13, 2, 0.150, 'liter')
ON DUPLICATE KEY UPDATE quantity_needed = VALUES(quantity_needed);

-- 7. የመጀመሪያ ደንበኛ እና አቅራቢ
INSERT INTO contacts (uuid, type, name, phone, address, outstanding_balance) VALUES
('cnt-sup-01', 'supplier', 'አቶ በቀለ የስጋ እና ከብት አቅራቢ', '0911223344', 'ቢሾፍቱ', 0.00),
('cnt-sup-02', 'supplier', 'የአትክልትና ቅመማ ቅመም ጅምላ አቅራቢ', '0922334455', 'ሞጆ', 0.00),
('cnt-cust-01', 'customer', 'አቶ ካሳሁን ኃይሌ (ቋሚ ደንበኛ)', '0933445566', 'አዲስ አበባ', 0.00)
ON DUPLICATE KEY UPDATE name = VALUES(name);
