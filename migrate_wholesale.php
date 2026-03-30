<?php
/**
 * Migration: Add is_wholesale column to pos_transactions.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

try {
    $col = $db->query("SHOW COLUMNS FROM pos_transactions LIKE 'is_wholesale'")->fetch();
    if ($col) {
        echo "is_wholesale column already exists.\n";
    } else {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN is_wholesale TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added is_wholesale column to pos_transactions.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
