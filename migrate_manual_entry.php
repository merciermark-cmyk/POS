<?php
/**
 * Migration: Add is_manual_entry column to pos_transactions.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

try {
    $col = $db->query("SHOW COLUMNS FROM pos_transactions LIKE 'is_manual_entry'")->fetch();
    if ($col) {
        echo "is_manual_entry column already exists.\n";
    } else {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN is_manual_entry TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added is_manual_entry column to pos_transactions.\n";
    }
    // Also add notes column for manual entry descriptions
    $col2 = $db->query("SHOW COLUMNS FROM pos_transactions LIKE 'notes'")->fetch();
    if ($col2) {
        echo "notes column already exists.\n";
    } else {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN notes TEXT NULL");
        echo "Added notes column to pos_transactions.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
