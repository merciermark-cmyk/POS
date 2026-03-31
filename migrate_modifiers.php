<?php
/**
 * Migration: Create pos_modifiers and pos_transaction_item_modifiers tables.
 * Run once via browser or CLI: php migrate_modifiers.php
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

$queries = [
    "CREATE TABLE IF NOT EXISTS pos_modifiers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS pos_transaction_item_modifiers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        transaction_item_id INT UNSIGNED NOT NULL,
        modifier_id INT UNSIGNED NOT NULL,
        modifier_name VARCHAR(100) NOT NULL,
        modifier_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity INT NOT NULL DEFAULT 1,
        INDEX idx_transaction_item_id (transaction_item_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

foreach ($queries as $sql) {
    $db->exec($sql);
}

echo "Migration complete: pos_modifiers + pos_transaction_item_modifiers tables created.\n";
