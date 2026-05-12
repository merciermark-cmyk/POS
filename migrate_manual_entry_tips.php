<?php
/**
 * Migration: Add tip_amount column to pos_transactions for manual entries.
 * Run once, then delete this file.
 */
require __DIR__ . '/app/config/config.php';
require __DIR__ . '/app/config/database.php';

$db = getDB();

$sql = "ALTER TABLE pos_transactions ADD COLUMN tip_amount DECIMAL(10,2) NULL DEFAULT NULL AFTER pst_amount";
try {
    $db->exec($sql);
    echo "OK: $sql\n";
} catch (PDOException $e) {
    echo "WARN: {$e->getMessage()}\n";
}
echo "Done.\n";
