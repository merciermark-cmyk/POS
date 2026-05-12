<?php
/**
 * Migration: Add inventory_synced column + global sync toggle.
 * Also voids all completed transactions from today (2026-04-07) to reverse inventory.
 *
 * Run once on production, then delete.
 */
declare(strict_types=1);

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/app/models/BaseModel.php';
require_once __DIR__ . '/app/models/Transaction.php';
require_once __DIR__ . '/app/models/Inventory.php';
require_once __DIR__ . '/app/models/AuditLog.php';
require_once __DIR__ . '/app/models/Modifier.php';
require_once __DIR__ . '/app/models/PosSetting.php';

$pdo = getDB();

// ── 1. Add inventory_synced column ──────────────────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM pos_transactions LIKE 'inventory_synced'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE pos_transactions ADD COLUMN inventory_synced TINYINT(1) NOT NULL DEFAULT 1 AFTER discount_percent");
    echo "Added inventory_synced column.\n";
} else {
    echo "inventory_synced column already exists.\n";
}

// ── 2. Void all completed transactions from today ───────────────────────────
$today = date('Y-m-d');
$txns = $pdo->prepare(
    "SELECT id FROM pos_transactions WHERE DATE(created_at) = ? AND status IN ('completed', 'partial_refund')"
);
$txns->execute([$today]);
$rows = $txns->fetchAll(PDO::FETCH_ASSOC);

$settings = new PosSetting();
$locationId = $settings->getShopLocationId();

if (empty($rows)) {
    echo "No completed transactions found for $today.\n";
} else {
    echo "Found " . count($rows) . " transactions to void for $today.\n";

    $txnModel = new Transaction();
    // Use user_id = 1 (admin) as the voider
    $voidedBy = 1;
    $reason = 'Training day — bulk void of all transactions';

    foreach ($rows as $row) {
        try {
            $txnModel->void((int)$row['id'], $voidedBy, $reason, $locationId);
            echo "  Voided transaction #{$row['id']}\n";
        } catch (RuntimeException $e) {
            echo "  SKIP transaction #{$row['id']}: {$e->getMessage()}\n";
        }
    }
    echo "Done voiding.\n";
}

// ── 3. Disable inventory sync ───────────────────────────────────────────────
$settings->set('inventory_sync_enabled', '0');
echo "Inventory sync DISABLED. Re-enable with: UPDATE pos_settings SET setting_value = '1' WHERE setting_key = 'inventory_sync_enabled'\n";

echo "\nAll done. Delete this file from the server.\n";
