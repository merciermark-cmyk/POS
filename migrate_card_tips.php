<?php
/**
 * Migration: Add R1/R2 card batch & tips columns to dayclose_counts.
 * Run via browser, then delete this file.
 */
require __DIR__ . '/app/config/config.php';

$db = Database::getInstance()->getConnection();

$columns = [
    'r1_card' => 'DECIMAL(10,2) NULL AFTER r3_tips',
    'r1_tips' => 'DECIMAL(10,2) NULL AFTER r1_card',
    'r2_card' => 'DECIMAL(10,2) NULL AFTER r1_tips',
    'r2_tips' => 'DECIMAL(10,2) NULL AFTER r2_card',
];

echo "<pre>\n";
foreach ($columns as $col => $def) {
    try {
        $db->exec("ALTER TABLE dayclose_counts ADD COLUMN $col $def");
        echo "Added $col\n";
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate column')) {
            echo "$col already exists\n";
        } else {
            echo "ERROR on $col: " . $e->getMessage() . "\n";
        }
    }
}
echo "\nDone.\n</pre>";
