<?php
/**
 * Migration: Create pos_held_orders table.
 * Run once, then delete this file.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

echo "<pre>\n";

$db->exec("
    CREATE TABLE IF NOT EXISTS pos_held_orders (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shift_id INT UNSIGNED NOT NULL,
        terminal_id INT UNSIGNED NULL,
        held_by INT UNSIGNED NOT NULL,
        label VARCHAR(100) NULL,
        cart_json JSON NOT NULL,
        item_count INT UNSIGNED NOT NULL DEFAULT 0,
        cart_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('held','resumed','expired') NOT NULL DEFAULT 'held',
        resumed_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resumed_at DATETIME NULL,
        INDEX idx_shift_id (shift_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "Created pos_held_orders table.\n";

echo "\nDone. DELETE this file now.\n</pre>";
