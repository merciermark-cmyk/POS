<?php
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';
$db = getDB();
try {
    $db->exec("ALTER TABLE dayclose_counts ADD COLUMN r3_tips DECIMAL(10,2) NULL");
    echo "OK: Added r3_tips column\n";
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), 'Duplicate column')) {
        echo "SKIP: r3_tips already exists\n";
    } else {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
echo "Done. Delete this file.\n";
