-- =========================================================================
-- ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
-- ማዕከላዊ ዳታቤዝ ስኪማ እና የመነሻ መረጃዎች (Full Database Schema & Seed Data)
-- Compatible with MySQL 8.0+, MariaDB 10.4+, and PHP 8.2+
-- =========================================================================

CREATE DATABASE IF NOT EXISTS teklu_business_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE teklu_business_db;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS daily_closings;
DROP TABLE IF EXISTS devices;
DROP TABLE IF EXISTS expenses;
DROP TABLE IF EXISTS financial_accounts;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS purchase_items;
DROP TABLE IF EXISTS purchases;
DROP TABLE IF EXISTS recipes;
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS stock_levels;
DROP TABLE IF EXISTS stock_locations;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS contacts;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS withdrawals;
DROP TABLE IF EXISTS farm_productions;
DROP TABLE IF EXISTS internal_transfers;
DROP TABLE IF EXISTS restaurant_order_items;
DROP TABLE IF EXISTS restaurant_orders;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. ተጠቃሚዎች (Users)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('super_admin', 'manager') NOT NULL,
    permissions JSON NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. የተፈቀደላቸው መሳሪያዎች (Devices for Offline Access)
CREATE TABLE devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_uuid VARCHAR(64) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    is_trusted TINYINT(1) DEFAULT 1,
    last_sync_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. የምርት እና ወጪ ምድቦች (Categories)
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    name_am VARCHAR(100) NOT NULL,
    type ENUM('product', 'expense') DEFAULT 'product',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. የዕቃ ማከማቻ ቦታዎች (Stock Locations)
CREATE TABLE stock_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    name_am VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ምርቶች እና ጥሬ ዕቃዎች (Products & Raw Materials)
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    name_en VARCHAR(150) NOT NULL,
    name_am VARCHAR(150) NOT NULL,
    category_id INT NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'piece',
    purchase_cost DECIMAL(12,2) DEFAULT 0.00,
    production_cost DECIMAL(12,2) DEFAULT 0.00,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    min_stock_level DECIMAL(10,2) DEFAULT 0.00,
    source VARCHAR(50) NOT NULL DEFAULT 'company',
    has_expiry TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. የምርት ክምችት መጠን (Stock Levels)
CREATE TABLE stock_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    quantity DECIMAL(12,3) DEFAULT 0.000,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prod_loc (product_id, location_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES stock_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. የክምችት እንቅስቃሴዎች (Stock Movements)
CREATE TABLE stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    movement_type ENUM('opening', 'purchase', 'production', 'sale', 'adjustment', 'wastage', 'return') NOT NULL,
    product_id INT NOT NULL,
    from_location_id INT NULL,
    to_location_id INT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit_cost DECIMAL(12,2) DEFAULT 0.00,
    total_cost DECIMAL(12,2) DEFAULT 0.00,
    reference_id VARCHAR(50) NULL,
    reason TEXT NULL,
    authorized_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (authorized_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. የምግብ አዘገጃጀት እና ሬሲፒ (Recipes)
CREATE TABLE recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    parent_product_id INT NOT NULL,
    ingredient_product_id INT NOT NULL,
    quantity_needed DECIMAL(10,3) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. የፋይናንስ ሂሳቦች (Financial Accounts)
CREATE TABLE financial_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    name_am VARCHAR(100) NOT NULL,
    current_balance DECIMAL(14,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. ደንበኞች እና አቅራቢዎች (Contacts)
CREATE TABLE contacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    type ENUM('customer', 'supplier') NOT NULL,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(255) NULL,
    notes TEXT NULL,
    outstanding_balance DECIMAL(12,2) DEFAULT 0.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. እቃ መረከቢያ እና ግዢዎች (Purchases)
CREATE TABLE purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    supplier_id INT NULL,
    purchase_date DATETIME NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL,
    additional_cost DECIMAL(12,2) DEFAULT 0.00,
    grand_total DECIMAL(14,2) NOT NULL,
    paid_amount DECIMAL(14,2) DEFAULT 0.00,
    unpaid_balance DECIMAL(14,2) DEFAULT 0.00,
    payment_status ENUM('paid', 'partial', 'credit') NOT NULL,
    payment_account_id INT NULL,
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES contacts(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    purchase_id INT NOT NULL,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_price DECIMAL(14,2) NOT NULL,
    expiry_date DATE NULL,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (location_id) REFERENCES stock_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. ሽያጮች (Sales / POS)
CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    receipt_number VARCHAR(50) UNIQUE NOT NULL,
    sale_date DATETIME NOT NULL,
    customer_id INT NULL,
    subtotal DECIMAL(14,2) NOT NULL,
    discount DECIMAL(12,2) DEFAULT 0.00,
    grand_total DECIMAL(14,2) NOT NULL,
    payment_status ENUM('paid', 'credit') NOT NULL,
    status ENUM('completed', 'cancelled', 'refunded') DEFAULT 'completed',
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES contacts(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_price DECIMAL(14,2) NOT NULL,
    unit_cost DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. ክፍያዎች (Payments)
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    transaction_type ENUM('sale', 'purchase', 'expense', 'withdrawal', 'customer_credit_settlement', 'supplier_debt_settlement', 'account_transfer') NOT NULL,
    reference_id VARCHAR(50) NOT NULL,
    account_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    notes VARCHAR(255) NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES financial_accounts(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. የስራ ማስኬጃ ወጪዎች (Expenses)
CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    category_id INT NOT NULL,
    account_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    expense_date DATETIME NOT NULL,
    department VARCHAR(50) NOT NULL DEFAULT 'general',
    description TEXT NOT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (account_id) REFERENCES financial_accounts(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. ገንዘብ መውጣት (Withdrawals)
CREATE TABLE withdrawals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    account_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    withdrawal_date DATETIME NOT NULL,
    reason TEXT NOT NULL,
    recipient_name VARCHAR(150) NOT NULL,
    notes TEXT NULL,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES financial_accounts(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. ዕለታዊ ሂሳብ መዝጊያ (Daily Closings)
CREATE TABLE daily_closings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    closing_date DATE UNIQUE NOT NULL,
    system_cash DECIMAL(14,2) NOT NULL,
    actual_cash DECIMAL(14,2) NOT NULL,
    cash_difference DECIMAL(12,2) NOT NULL,
    system_cbe DECIMAL(14,2) NOT NULL,
    actual_cbe DECIMAL(14,2) NOT NULL,
    system_telebirr DECIMAL(14,2) NOT NULL,
    actual_telebirr DECIMAL(14,2) NOT NULL,
    total_sales DECIMAL(14,2) NOT NULL,
    total_purchases DECIMAL(14,2) NOT NULL,
    total_expenses DECIMAL(14,2) NOT NULL,
    total_withdrawals DECIMAL(14,2) NOT NULL,
    discrepancy_reason TEXT NULL,
    is_reopened TINYINT(1) DEFAULT 0,
    reopened_by INT NULL,
    reopened_reason TEXT NULL,
    closed_by INT NOT NULL,
    closed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (closed_by) REFERENCES users(id),
    FOREIGN KEY (reopened_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. የኦዲት መዝገብ (Audit Logs)
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(36) NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- የመነሻ መረጃዎች (Seed Data)
-- =========================================================================

-- ተጠቃሚዎች
INSERT INTO users (id, uuid, username, password_hash, full_name, role, permissions, is_active)
VALUES 
(1, 'usr-admin-01', 'admin', '$2y$10$w0f5eF1VnBqD6Z9lS5f7xO7xK8aV2qD9w0f5eF1VnBqD6Z9lS5f7x.', 'ተክሉ ጌታቸው (ዋና አስተዳዳሪ)', 'super_admin', '{"all": true}', 1),
(2, 'usr-mgr-01', 'manager', '$2y$10$8u1dD9w0f5eF1VnBqD6Z9lS5f7xO7xK8aV2qD9w0f5eF1VnBqD6Z.', 'አበበ በቀለ (ስራ አስኪያጅ)', 'manager', '{"can_discount": true, "can_adjust_stock": true, "can_view_pl": true, "can_record_withdrawal": true}', 1);

-- መጋዘን
INSERT INTO stock_locations (id, uuid, code, name_en, name_am)
VALUES (1, 'loc-main-company-stock', 'main_storage', 'Main Company Inventory', 'ማዕከላዊ የድርጅቱ ክምችት');

-- የፋይናንስ አካውንቶች
INSERT INTO financial_accounts (id, uuid, code, name_en, name_am, current_balance, is_active)
VALUES 
(1, 'acc-cash-01', 'cash', 'Cash in Hand', 'የእጅ ጥሬ ገንዘብ', 10000.00, 1),
(2, 'acc-cbe-01', 'cbe', 'Commercial Bank of Ethiopia (CBE)', 'የኢትዮጵያ ንግድ ባንክ', 10000.00, 1),
(3, 'acc-telebirr-01', 'telebirr', 'Telebirr', 'ቴሌብር (Telebirr)', 10000.00, 1);

-- ምድቦች
INSERT INTO categories (id, uuid, name_en, name_am, type)
VALUES 
(1, 'cat-raw-01', 'Raw Materials & Ingredients', 'ጥሬ እቃዎች እና ግብዓቶች', 'product'),
(2, 'cat-pkg-01', 'Packaged Products', 'የታሸጉ ምርቶች', 'product'),
(3, 'cat-bev-01', 'Beverages', 'መጠጦች', 'product'),
(4, 'cat-food-01', 'Prepared Foods & Meals', 'የተዘጋጁ ምግቦች', 'product'),
(5, 'cat-spices-01', 'Spices & Condiments', 'ቅመማ ቅመሞች እና ማጣፈጫዎች', 'product'),
(10, 'cat-exp-rent', 'Rent Expense', 'የቤት ኪራይ ወጪ', 'expense'),
(11, 'cat-exp-util', 'Utilities (Water/Electric)', 'መብራት እና ውሃ', 'expense'),
(12, 'cat-exp-salary', 'Salaries & Wages', 'የሰራተኛ ደመወዝ', 'expense'),
(13, 'cat-exp-transport', 'Transport & Fuel', 'ትራንስፖርት እና ነዳጅ', 'expense'),
(14, 'cat-exp-maint', 'Maintenance & Repairs', 'ጥገና እና እድሳት', 'expense');

-- ጥሬ እቃዎች እና ምርቶች
INSERT INTO products (id, uuid, name_en, name_am, category_id, unit, purchase_cost, production_cost, selling_price, min_stock_level, source, is_active)
VALUES 
(1, 'prd-onion-01', 'Onion', 'ቀይ ሽንኩርት', 1, 'kg', 60.00, 0.00, 0.00, 5.00, 'company', 1),
(2, 'prd-tomato-01', 'Tomato', 'ቲማቲም', 1, 'kg', 50.00, 0.00, 0.00, 5.00, 'company', 1),
(3, 'prd-oil-01', 'Cooking Oil', 'የምግብ ዘይት', 1, 'liter', 150.00, 0.00, 0.00, 5.00, 'company', 1),
(4, 'prd-injera-01', 'Injera', 'እንጀራ', 1, 'piece', 15.00, 0.00, 0.00, 10.00, 'company', 1),
(5, 'prd-berbere-01', 'Berbere', 'በርበሬ', 5, 'kg', 250.00, 0.00, 0.00, 2.00, 'company', 1),
(6, 'prd-flour-01', 'Wheat Flour', 'የስንዴ ዱቄት', 1, 'kg', 85.00, 0.00, 0.00, 10.00, 'company', 1),
(7, 'prd-biscuit-01', 'Biscuit', 'ብስኩት', 2, 'piece', 10.00, 0.00, 15.00, 20.00, 'company', 1),
(8, 'prd-water-01', 'Bottled Water 500ml', 'የታሸገ ውሃ 500ml', 3, 'bottle', 15.00, 0.00, 25.00, 15.00, 'company', 1),
(9, 'prd-milk-01', 'Fresh Milk', 'ትኩስ ወተት', 1, 'liter', 65.00, 0.00, 80.00, 10.00, 'company', 1),
(20, 'food-firfir-01', 'Firfir Special', 'ፍርፍር (ልዩ)', 4, 'plate', 0.00, 44.50, 120.00, 0.00, 'company', 1);

-- ሬሲፒ
INSERT INTO recipes (uuid, parent_product_id, ingredient_product_id, quantity_needed, unit)
VALUES 
('rcp-ff-01', 20, 1, 0.200, 'kg'),
('rcp-ff-02', 20, 2, 0.100, 'kg'),
('rcp-ff-03', 20, 3, 0.050, 'liter'),
('rcp-ff-04', 20, 4, 1.000, 'piece'),
('rcp-ff-05', 20, 5, 0.020, 'kg');

-- የመክፈቻ ክምችቶች
INSERT INTO stock_levels (product_id, location_id, quantity)
VALUES 
(1, 1, 50.000),
(2, 1, 40.000),
(3, 1, 30.000),
(4, 1, 100.000),
(5, 1, 15.000),
(6, 1, 80.000),
(7, 1, 120.000),
(8, 1, 200.000),
(9, 1, 60.000);

-- አቅራቢዎች እና ደንበኞች
INSERT INTO contacts (id, uuid, type, name, phone, address, notes, outstanding_balance)
VALUES 
(1, 'cnt-sup-01', 'supplier', 'ABC Biscuit Company', '0911223344', 'አዲስ አበባ', 'የብስኩት እና ጣፋጮች አቅራቢ', 0.00),
(2, 'cnt-sup-02', 'supplier', 'መርካቶ የቅመማ ቅመም ነጋዴዎች', '0922334455', 'መርካቶ', 'የሽንኩርት፣ ቲማቲም እና ቅመማ ቅመም አቅራቢ', 0.00),
(3, 'cnt-cust-01', 'customer', 'አቶ ዳዊት ገብሬ (ተራ ደንበኛ)', '0933445566', 'ፒያሳ', 'መደበኛ ደንበኛ', 0.00);
