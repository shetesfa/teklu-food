-- ተክሉ ፉድ እና ቢዝነስ ማኔጅመንት (Teklu Food & Business Management)
-- ማዕከላዊ ዳታቤዝ ስኪማ (Central MariaDB / MySQL Schema)

CREATE DATABASE IF NOT EXISTS teklu_business_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE teklu_business_db;

-- 1. ተጠቃሚዎች (Users)
CREATE TABLE IF NOT EXISTS users (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. የተፈቀደላቸው መሳሪያዎች (Devices for Offline Access)
CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_uuid VARCHAR(64) UNIQUE NOT NULL,
    user_id INT NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    is_trusted TINYINT(1) DEFAULT 1,
    last_sync_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. የምርት እና ወጪ ምድቦች (Categories)
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    name_am VARCHAR(100) NOT NULL,
    type ENUM('product', 'expense') DEFAULT 'product',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. የዕቃ ማከማቻ ቦታዎች (Stock Locations - ማዕከላዊ የድርጅት ክምችት)
CREATE TABLE IF NOT EXISTS stock_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL, -- 'main_storage'
    name_en VARCHAR(100) NOT NULL,
    name_am VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. ምርቶች እና ጥሬ ዕቃዎች (Products & Raw Materials)
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    name_en VARCHAR(150) NOT NULL,
    name_am VARCHAR(150) NOT NULL,
    category_id INT NOT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'piece', -- 'kg', 'gram', 'liter', 'ml', 'piece', 'plate', etc.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. የምርት ክምችት መጠን (Stock Levels per Location)
CREATE TABLE IF NOT EXISTS stock_levels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    location_id INT NOT NULL,
    quantity DECIMAL(12,3) DEFAULT 0.000,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prod_loc (product_id, location_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES stock_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. የክምችት እንቅስቃሴዎች (Stock Movements History)
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    movement_type ENUM('opening', 'purchase', 'production', 'sale', 'adjustment', 'wastage', 'return') NOT NULL,
    product_id INT NOT NULL,
    from_location_id INT NULL,
    to_location_id INT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit_cost DECIMAL(12,2) DEFAULT 0.00,
    total_cost DECIMAL(12,2) DEFAULT 0.00,
    reference_id VARCHAR(36) NULL,
    reason TEXT NULL,
    authorized_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (authorized_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. የምግብ አዘገጃጀት / የግብዓት ተዋጽኦ (Recipes & Ingredients)
CREATE TABLE IF NOT EXISTS recipes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    parent_product_id INT NOT NULL,
    ingredient_product_id INT NOT NULL,
    quantity_needed DECIMAL(10,3) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (ingredient_product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. የፋይናንስ ሂሳቦች (Financial Accounts)
CREATE TABLE IF NOT EXISTS financial_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) UNIQUE NOT NULL,
    code VARCHAR(50) UNIQUE NOT NULL, -- 'cash', 'cbe', 'telebirr', 'other_bank', 'other_mobile'
    name_en VARCHAR(100) NOT NULL,
    name_am VARCHAR(100) NOT NULL,
    current_balance DECIMAL(14,2) DEFAULT 0.00,
    is_active TINYINT(1) DEFAULT 1,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. ደንበኞች እና አቅራቢዎች (Customers & Suppliers)
CREATE TABLE IF NOT EXISTS contacts (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. እቃ መረከቢያ እና ግዢዎች (Goods Receiving & Purchases)
CREATE TABLE IF NOT EXISTS purchases (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. ሽያጮች (Sales / POS)
CREATE TABLE IF NOT EXISTS sales (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sale_items (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. የገንዘብ ክፍያዎች (Payments)
CREATE TABLE IF NOT EXISTS payments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. የስራ ማስኬጃ ወጪዎች (Operating Expenses)
CREATE TABLE IF NOT EXISTS expenses (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. የባለቤት እና የተፈቀዱ ክፍያዎች/መውጣት (Withdrawals)
CREATE TABLE IF NOT EXISTS withdrawals (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. ዕለታዊ ሂሳብ መዝጊያ (Daily Closings)
CREATE TABLE IF NOT EXISTS daily_closings (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. የኦዲት መዝገብ (Audit Logs)
CREATE TABLE IF NOT EXISTS audit_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
