<?php
/**
 * Migration: Create pos_gift_card_sales table.
 * Run once, then delete this file.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

echo "<pre>\n";

$db->exec("
    CREATE TABLE IF NOT EXISTS pos_gift_card_sales (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shift_id INT UNSIGNED NOT NULL,
        terminal_id INT UNSIGNED NULL,
        user_id INT UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method ENUM('cash','card') NOT NULL DEFAULT 'card',
        notes VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_shift_id (shift_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "Created pos_gift_card_sales table.\n";

echo "\nDone. DELETE this file now.\n</pre>";
