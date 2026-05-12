<?php
/**
 * Migration: Add lock + R3 columns to dayclose_counts for POS embedding.
 * Run once via browser, then delete this file.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

$sqls = [
    "ALTER TABLE dayclose_counts ADD COLUMN locked_by INT UNSIGNED NULL",
    "ALTER TABLE dayclose_counts ADD COLUMN locked_at DATETIME NULL",
    "ALTER TABLE dayclose_counts ADD COLUMN lock_session VARCHAR(64) NULL",
    "ALTER TABLE dayclose_counts ADD COLUMN r3_total_sales DECIMAL(10,2) NULL",
    "ALTER TABLE dayclose_counts ADD COLUMN r3_txn_count INT NULL",
    "ALTER TABLE dayclose_counts ADD COLUMN r3_gst DECIMAL(10,2) NULL",
    "ALTER TABLE dayclose_counts ADD COLUMN r3_cash DECIMAL(10,2) NULL",
    "ALTER TABLE dayclose_counts ADD COLUMN r3_card DECIMAL(10,2) NULL",
    "ALTER TABLE dayclose_counts ADD COLUMN actual_deposit DECIMAL(10,2) NULL",
];

echo "<pre>\n";
foreach ($sqls as $sql) {
    try {
        $db->exec($sql);
        echo "OK: $sql\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "SKIP (already exists): $sql\n";
        } else {
            echo "ERROR: $sql\n  => " . $e->getMessage() . "\n";
        }
    }
}
echo "\nDone. Delete this file.\n</pre>";
