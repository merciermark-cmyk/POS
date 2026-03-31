<?php
/**
 * Migration: Add track_inventory flag to products table.
 * Default 1 = existing products continue tracking inventory (backward compatible).
 * Set to 0 for made-to-order items (beverages) that shouldn't deduct stock on POS sales.
 */
require __DIR__ . '/app/bootstrap.php';

$db = getDB();

try {
    $db->exec('ALTER TABLE products ADD COLUMN track_inventory TINYINT(1) NOT NULL DEFAULT 1');
    echo "OK — added products.track_inventory column (default 1).\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "SKIP — track_inventory column already exists.\n";
    } else {
        throw $e;
    }
}
