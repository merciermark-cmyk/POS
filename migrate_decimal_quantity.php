<?php
/**
 * Migration: Change quantity columns from INT to DECIMAL(10,2)
 * for decimal tea quantities (e.g. 6.5 = 650g).
 *
 * Run once on production: https://pos.granvilletea.com/migrate_decimal_quantity.php
 * Then delete this file.
 */

require __DIR__ . '/config/config.php';
require __DIR__ . '/app/models/BaseModel.php';

$db = new BaseModel();
$pdo = $db->getPdo();

$queries = [
    "ALTER TABLE pos_transaction_items MODIFY quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00",
    "ALTER TABLE pos_refund_items MODIFY quantity DECIMAL(10,2) NOT NULL",
];

echo "<h3>Decimal Quantity Migration</h3><pre>\n";

foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $sql\n";
    } catch (PDOException $e) {
        echo "ERR: $sql\n  → " . $e->getMessage() . "\n";
    }
}

echo "\nDone. Delete this file.\n</pre>";
