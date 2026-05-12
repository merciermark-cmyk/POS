<?php
/**
 * Migration: Drop fk_audit_user foreign key from audit_log.
 * The audit_log is now shared between inventory (users table) and POS (pos_users table),
 * so the FK to users.id is no longer valid.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

try {
    // Check if the FK exists
    $stmt = $db->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'audit_log'
          AND CONSTRAINT_NAME = 'fk_audit_user'
          AND CONSTRAINT_TYPE = 'FOREIGN KEY'
    ");
    $row = $stmt->fetch();

    if ($row) {
        $db->exec("ALTER TABLE audit_log DROP FOREIGN KEY fk_audit_user");
        echo "Dropped fk_audit_user constraint from audit_log.\n";
    } else {
        echo "fk_audit_user constraint does not exist — nothing to do.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
