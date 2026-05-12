<?php
/**
 * Migration: Create pos_standalone_refunds table + threshold setting.
 * Run once, then delete this file.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

echo "<pre>\n";

// Create table
$db->exec("
    CREATE TABLE IF NOT EXISTS pos_standalone_refunds (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shift_id INT UNSIGNED NOT NULL,
        terminal_id INT UNSIGNED NULL,
        processed_by INT UNSIGNED NOT NULL,
        authorized_by INT UNSIGNED NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method ENUM('cash','card') NOT NULL,
        reason VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_shift_id (shift_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "Created pos_standalone_refunds table.\n";

// Insert threshold setting
$db->exec("
    INSERT IGNORE INTO pos_settings (setting_key, setting_value)
    VALUES ('standalone_refund_threshold', '50.00')
");
echo "Inserted standalone_refund_threshold setting.\n";

echo "\nDone. DELETE this file now.\n</pre>";
