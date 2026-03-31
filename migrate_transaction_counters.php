<?php
/**
 * Migration: Add daily_number, monthly_number, annual_number to pos_transactions.
 * Run once via browser or CLI: php migrate_transaction_counters.php
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

$queries = [
    "ALTER TABLE pos_transactions ADD COLUMN daily_number INT UNSIGNED NULL AFTER void_reason",
    "ALTER TABLE pos_transactions ADD COLUMN monthly_number INT UNSIGNED NULL AFTER daily_number",
    "ALTER TABLE pos_transactions ADD COLUMN annual_number INT UNSIGNED NULL AFTER monthly_number",
];

foreach ($queries as $sql) {
    try {
        $db->exec($sql);
        echo "OK: $sql\n<br>";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "SKIP (already exists): $sql\n<br>";
        } else {
            echo "ERROR: " . $e->getMessage() . "\n<br>";
        }
    }
}

echo "\nDone.\n";
