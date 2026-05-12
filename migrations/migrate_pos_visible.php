<?php
/**
 * Add pos_visible column to products table.
 * Defaults to 1 (visible). Set to 0 to hide from POS terminal grid.
 */
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';

$db = getDB();

$db->exec("ALTER TABLE products ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER deleted_at");

echo "Added pos_visible column to products.\n";
