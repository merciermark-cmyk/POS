<?php
/**
 * Migration: Add parent_id to categories table for subcategory support.
 * Run once on production DB.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

echo "Adding parent_id column to categories...\n";

try {
    // Check if column already exists
    $cols = $db->query("SHOW COLUMNS FROM categories LIKE 'parent_id'")->fetchAll();
    if (!empty($cols)) {
        echo "Column parent_id already exists — skipping.\n";
    } else {
        $db->exec("ALTER TABLE categories ADD COLUMN parent_id INT UNSIGNED NULL AFTER name");
        $db->exec("ALTER TABLE categories ADD CONSTRAINT fk_categories_parent
                    FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL");
        echo "Added parent_id column + FK constraint.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Migration complete.\n";
