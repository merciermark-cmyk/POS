-- POS tables (created in the inventory database)

CREATE TABLE IF NOT EXISTS pos_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    pin CHAR(4) NULL UNIQUE,
    role ENUM('manager','cashier') NOT NULL DEFAULT 'cashier',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_terminals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    print_service_url VARCHAR(255) NOT NULL DEFAULT 'http://localhost:5000',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_shifts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    terminal_id INT UNSIGNED NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    opening_float DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    closing_cash DECIMAL(10,2) NULL,
    expected_cash DECIMAL(10,2) NULL,
    over_short DECIMAL(10,2) NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_terminal_id (terminal_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shift_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    terminal_id INT UNSIGNED NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('completed','voided','refunded','partial_refund') NOT NULL DEFAULT 'completed',
    voided_by INT UNSIGNED NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(255) NULL,
    is_manual_entry TINYINT(1) NOT NULL DEFAULT 0,
    transaction_count INT UNSIGNED NULL,
    notes TEXT NULL,
    daily_number INT UNSIGNED NULL,
    monthly_number INT UNSIGNED NULL,
    annual_number INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_shift_id (shift_id),
    INDEX idx_user_id (user_id),
    INDEX idx_terminal_id (terminal_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_transaction_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_code VARCHAR(50) NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2) NOT NULL,
    tax_profile ENUM('tax_free','gst_only','gst_pst') NOT NULL DEFAULT 'tax_free',
    gst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(10,2) NOT NULL,
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    method ENUM('cash','card','gift_card','web_gift_card') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaction_id (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings
INSERT IGNORE INTO pos_settings (setting_key, setting_value) VALUES
    ('shop_location_id', ''),
    ('store_name', 'Granville Island Tea Co.'),
    ('store_address', '1689 Johnston St, Vancouver, BC V6H 3R9'),
    ('store_phone', '(604) 683-7491'),
    ('gst_number', ''),
    ('pst_number', ''),
    ('print_service_url', 'http://localhost:5000'),
    ('receipt_footer', 'Thank you for your purchase!');

CREATE TABLE IF NOT EXISTS product_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_refunds (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_transaction_id INT UNSIGNED NOT NULL,
    shift_id INT UNSIGNED NOT NULL,
    refunded_by INT UNSIGNED NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reason VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_original_txn (original_transaction_id),
    INDEX idx_shift_id (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_refund_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_id INT UNSIGNED NOT NULL,
    original_item_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    product_code VARCHAR(50) NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    tax_profile ENUM('tax_free','gst_only','gst_pst') NOT NULL DEFAULT 'tax_free',
    gst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    pst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(10,2) NOT NULL,
    INDEX idx_refund_id (refund_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_modifiers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_transaction_item_modifiers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_item_id INT UNSIGNED NOT NULL,
    modifier_id INT UNSIGNED NOT NULL,
    modifier_name VARCHAR(100) NOT NULL,
    modifier_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity INT NOT NULL DEFAULT 1,
    INDEX idx_transaction_item_id (transaction_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_refund_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    refund_id INT UNSIGNED NOT NULL,
    method ENUM('cash','card','gift_card','web_gift_card') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reference VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_refund_id (refund_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
