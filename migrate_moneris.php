<?php
/**
 * Migration: Moneris Go Cloud API v3 integration
 * - Creates pos_moneris_transactions table
 * - Adds 'moneris' to pos_payments.method ENUM
 * - Adds moneris_transaction_id column to pos_payments
 * - Adds moneris_terminal_id column to pos_terminals
 * - Seeds Moneris settings
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

echo "=== Moneris Integration Migration ===\n\n";

// 1. Create pos_moneris_transactions table
echo "1. Creating pos_moneris_transactions table...\n";
$db->exec("
    CREATE TABLE IF NOT EXISTS pos_moneris_transactions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        pos_transaction_id INT UNSIGNED NULL,
        order_id VARCHAR(36) NOT NULL,
        idempotency_key VARCHAR(36) NOT NULL UNIQUE,
        terminal_id VARCHAR(50) NOT NULL,
        action ENUM('purchase','refund','void') NOT NULL,
        amount_cents INT UNSIGNED NOT NULL,
        status_code VARCHAR(10) NULL,
        response_code VARCHAR(10) NULL,
        auth_code VARCHAR(20) NULL,
        card_type VARCHAR(30) NULL,
        masked_pan VARCHAR(20) NULL,
        tender_type VARCHAR(20) NULL,
        form_factor VARCHAR(20) NULL,
        moneris_txn_id VARCHAR(50) NULL,
        cloud_ticket VARCHAR(100) NULL,
        saf TINYINT(1) DEFAULT 0,
        approved_amount_cents INT UNSIGNED NULL,
        receipt_text TEXT NULL,
        full_response JSON NULL,
        completed TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_pos_txn (pos_transaction_id),
        INDEX idx_order_id (order_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "   Done.\n";

// 2. Add 'moneris' to pos_payments.method ENUM
echo "2. Adding 'moneris' to pos_payments.method ENUM...\n";
try {
    // Get current ENUM values
    $col = $db->query("SHOW COLUMNS FROM pos_payments WHERE Field = 'method'")->fetch();
    if ($col && strpos($col['Type'], "'moneris'") === false) {
        // Extract current enum values and add moneris
        preg_match("/^enum\((.+)\)$/", $col['Type'], $matches);
        if ($matches) {
            $newEnum = rtrim($matches[1], ')') . ",'moneris'";
            $db->exec("ALTER TABLE pos_payments MODIFY COLUMN method enum($newEnum) NOT NULL");
            echo "   Added 'moneris' to ENUM.\n";
        }
    } else {
        echo "   Already present or column not found.\n";
    }
} catch (Exception $e) {
    echo "   Note: " . $e->getMessage() . "\n";
}

// 3. Add moneris_transaction_id column to pos_payments
echo "3. Adding moneris_transaction_id to pos_payments...\n";
try {
    $exists = $db->query("SHOW COLUMNS FROM pos_payments LIKE 'moneris_transaction_id'")->fetch();
    if (!$exists) {
        $db->exec("ALTER TABLE pos_payments ADD COLUMN moneris_transaction_id INT UNSIGNED NULL AFTER reference");
        echo "   Added.\n";
    } else {
        echo "   Already exists.\n";
    }
} catch (Exception $e) {
    echo "   Note: " . $e->getMessage() . "\n";
}

// 4. Add moneris_terminal_id column to pos_terminals
echo "4. Adding moneris_terminal_id to pos_terminals...\n";
try {
    $exists = $db->query("SHOW COLUMNS FROM pos_terminals LIKE 'moneris_terminal_id'")->fetch();
    if (!$exists) {
        $db->exec("ALTER TABLE pos_terminals ADD COLUMN moneris_terminal_id VARCHAR(50) NULL AFTER print_service_url");
        echo "   Added.\n";
    } else {
        echo "   Already exists.\n";
    }
} catch (Exception $e) {
    echo "   Note: " . $e->getMessage() . "\n";
}

// 5. Seed Moneris settings
echo "5. Seeding Moneris settings...\n";
$defaults = [
    'moneris_enabled'         => '0',
    'moneris_sandbox'         => '1',
    'moneris_api_token'       => '',
    'moneris_store_id'        => '',
    'moneris_ist_config_code' => '',
];
$stmt = $db->prepare("INSERT IGNORE INTO pos_settings (setting_key, setting_value) VALUES (?, ?)");
foreach ($defaults as $key => $val) {
    $stmt->execute([$key, $val]);
}
echo "   Done.\n";

echo "\n=== Migration complete ===\n";
