<?php
/**
 * Migration: Add pos_sale and pos_void to audit_log action ENUM.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

try {
    // Get current ENUM values
    $col = $db->query("SHOW COLUMNS FROM audit_log LIKE 'action'")->fetch();
    $type = $col['Type'] ?? '';

    if (str_contains($type, 'pos_sale')) {
        echo "pos_sale already in ENUM.\n";
    } else {
        // Extract existing values
        preg_match("/^enum\('(.*)'\)$/", $type, $m);
        $existing = $m[1] ?? '';
        $values = $existing . "','pos_sale','pos_void";
        $db->exec("ALTER TABLE audit_log MODIFY action ENUM('$values') NOT NULL");
        echo "Added pos_sale, pos_void to audit_log.action ENUM.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
