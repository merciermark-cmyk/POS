<?php
/**
 * Migration: Create product_images table.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS product_images (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        filename VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_product_id (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "product_images table created (or already exists).\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
