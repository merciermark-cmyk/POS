<?php
/**
 * Migration: Safe Coin System (Phase 2 of coin rollout)
 *
 * Adds:
 *   - dayclose_counts.r{1,2,3}_coin_overage DECIMAL(10,2) NULL
 *   - safe_coin_ledger table (denomination-aware running ledger)
 *
 * Idempotent: safe to re-run; checks information_schema before altering.
 *
 * Run: `php migrate_safe_coin_system.php` from the app root (loads .env via config).
 */

declare(strict_types=1);

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$pdo = getDB();

function colExists(PDO $pdo, string $table, string $col): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $col]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

echo "Safe Coin System migration — " . date('Y-m-d H:i:s') . "\n";
echo "Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n\n";

// 1. Add coin_overage columns to dayclose_counts
foreach (['r1_coin_overage', 'r2_coin_overage', 'r3_coin_overage'] as $col) {
    if (colExists($pdo, 'dayclose_counts', $col)) {
        echo "  [skip] dayclose_counts.$col already exists\n";
    } else {
        $pdo->exec("ALTER TABLE dayclose_counts ADD COLUMN $col DECIMAL(10,2) NULL DEFAULT NULL AFTER r3_tips");
        echo "  [add]  dayclose_counts.$col\n";
    }
}

// 2. Create safe_coin_ledger
if (tableExists($pdo, 'safe_coin_ledger')) {
    echo "  [skip] table safe_coin_ledger already exists\n";
} else {
    $pdo->exec(<<<SQL
        CREATE TABLE safe_coin_ledger (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            type ENUM('overflow_in','bank_sell','bank_buy','adjustment','reconcile') NOT NULL,
            denomination ENUM('toonie','loonie','quarter','dime','nickel','mixed') NOT NULL,
            grams DECIMAL(8,2) NULL,
            dollars DECIMAL(10,2) NOT NULL,
            note VARCHAR(255) NULL,
            related_count_id INT UNSIGNED NULL,
            related_register ENUM('r1','r2','r3') NULL,
            created_by INT UNSIGNED NULL,
            INDEX idx_denom_ts (denomination, ts),
            INDEX idx_type (type),
            INDEX idx_count (related_count_id),
            CONSTRAINT fk_safe_coin_count
                FOREIGN KEY (related_count_id) REFERENCES dayclose_counts(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    echo "  [add]  table safe_coin_ledger\n";
}

echo "\nDone.\n";
