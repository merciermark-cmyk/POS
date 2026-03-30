<?php
/**
 * Migration: Add tax_profile column to products table.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

try {
    // Check if column already exists
    $cols = $db->query("SHOW COLUMNS FROM products LIKE 'tax_profile'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE products ADD COLUMN tax_profile ENUM('tax_free','gst_only','gst_pst') NOT NULL DEFAULT 'tax_free'");
        echo "Added tax_profile column to products table.\n";
    } else {
        echo "tax_profile column already exists.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
