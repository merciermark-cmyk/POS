<?php
/**
 * Migration: Add discount_percent columns to pos_transactions and pos_transaction_items.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

try {
    // pos_transactions.discount_percent
    $col = $db->query("SHOW COLUMNS FROM pos_transactions LIKE 'discount_percent'")->fetch();
    if ($col) {
        echo "pos_transactions.discount_percent already exists.\n";
    } else {
        $db->exec("ALTER TABLE pos_transactions ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0");
        echo "Added discount_percent to pos_transactions.\n";
    }

    // pos_transaction_items.discount_percent
    $col2 = $db->query("SHOW COLUMNS FROM pos_transaction_items LIKE 'discount_percent'")->fetch();
    if ($col2) {
        echo "pos_transaction_items.discount_percent already exists.\n";
    } else {
        $db->exec("ALTER TABLE pos_transaction_items ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0");
        echo "Added discount_percent to pos_transaction_items.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
