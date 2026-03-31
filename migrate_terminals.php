<?php
/**
 * Migration: Multi-terminal support.
 * Creates pos_terminals table and adds terminal_id to pos_shifts + pos_transactions.
 *
 * Run once: php migrate_terminals.php  (or visit via browser)
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

$queries = [
    // 1. New table
    "CREATE TABLE IF NOT EXISTS pos_terminals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        print_service_url VARCHAR(255) NOT NULL DEFAULT 'http://localhost:5000',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // 2. Add terminal_id to pos_shifts (nullable for backward compat)
    "ALTER TABLE pos_shifts ADD COLUMN terminal_id INT UNSIGNED NULL AFTER user_id",
    "ALTER TABLE pos_shifts ADD INDEX idx_terminal_id (terminal_id)",

    // 3. Add terminal_id to pos_transactions (nullable for backward compat)
    "ALTER TABLE pos_transactions ADD COLUMN terminal_id INT UNSIGNED NULL AFTER shift_id",
    "ALTER TABLE pos_transactions ADD INDEX idx_terminal_id (terminal_id)",
];

$out = '';
foreach ($queries as $sql) {
    try {
        $db->exec($sql);
        $out .= "OK: " . substr($sql, 0, 80) . "...\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        // Ignore "duplicate column" / "duplicate key" on re-run
        if (str_contains($msg, 'Duplicate column') || str_contains($msg, 'Duplicate key name') || str_contains($msg, 'already exists')) {
            $out .= "SKIP (already exists): " . substr($sql, 0, 80) . "...\n";
        } else {
            $out .= "ERROR: $msg\n  SQL: $sql\n";
        }
    }
}

if (php_sapi_name() === 'cli') {
    echo $out;
} else {
    echo '<pre>' . htmlspecialchars($out) . '</pre>';
}
