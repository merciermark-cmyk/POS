<?php
/**
 * May 1 POS Reset Script
 *
 * Step 1: Export all April data to CSV files
 * Step 2: Wipe transaction tables and rename terminals
 *
 * Run via SSH: php /home/gitte512/public_html/pos.granvilletea.com/scripts/may1_reset.php export
 * Then after confirming files: php /home/gitte512/public_html/pos.granvilletea.com/scripts/may1_reset.php wipe
 */

// Load app config for DB connection
require_once __DIR__ . '/../app/config/config.php';
require_once __DIR__ . '/../app/config/database.php';

$action = $argv[1] ?? '';

if (!in_array($action, ['export', 'wipe'])) {
    echo "Usage:\n";
    echo "  php may1_reset.php export   - Export all data to CSV\n";
    echo "  php may1_reset.php wipe     - Wipe tables and rename terminals (RUN EXPORT FIRST!)\n";
    exit(1);
}

$db = getDB();
$exportDir = __DIR__ . '/exports_april_2026';

// ============================================================
// STEP 1: EXPORT
// ============================================================
if ($action === 'export') {
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }

    $tables = [
        'pos_transactions' => 'SELECT t.*, u.username as cashier_name, term.name as terminal_name FROM pos_transactions t LEFT JOIN pos_users u ON u.id = t.user_id LEFT JOIN pos_terminals term ON term.id = t.terminal_id ORDER BY t.id',
        'pos_payments' => 'SELECT p.*, t.daily_number, t.annual_number FROM pos_payments p LEFT JOIN pos_transactions t ON t.id = p.transaction_id ORDER BY p.id',
        'pos_transaction_items' => 'SELECT ti.*, t.daily_number, t.annual_number FROM pos_transaction_items ti LEFT JOIN pos_transactions t ON t.id = ti.transaction_id ORDER BY ti.id',
        'pos_transaction_item_modifiers' => 'SELECT * FROM pos_transaction_item_modifiers ORDER BY id',
        'pos_refunds' => 'SELECT * FROM pos_refunds ORDER BY id',
        'pos_refund_items' => 'SELECT * FROM pos_refund_items ORDER BY id',
        'pos_refund_payments' => 'SELECT * FROM pos_refund_payments ORDER BY id',
        'pos_shifts' => 'SELECT s.*, u.username as user_name, term.name as terminal_name FROM pos_shifts s LEFT JOIN pos_users u ON u.id = s.user_id LEFT JOIN pos_terminals term ON term.id = s.terminal_id ORDER BY s.id',
        'pos_petty_cash' => 'SELECT pc.*, u.username as user_name FROM pos_petty_cash pc LEFT JOIN pos_users u ON u.id = pc.user_id ORDER BY pc.id',
        'pos_standalone_refunds' => 'SELECT * FROM pos_standalone_refunds ORDER BY id',
    ];

    echo "Exporting April 2026 POS data...\n\n";

    foreach ($tables as $table => $query) {
        $stmt = $db->query($query);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $file = $exportDir . '/' . $table . '.csv';
        $fp = fopen($file, 'w');

        if (count($rows) > 0) {
            // Header row
            fputcsv($fp, array_keys($rows[0]));
            // Data rows
            foreach ($rows as $row) {
                fputcsv($fp, $row);
            }
            echo "  $table: " . count($rows) . " rows exported\n";
        } else {
            echo "  $table: 0 rows (empty file)\n";
        }

        fclose($fp);
    }

    // Also export a summary report
    $summary = $exportDir . '/SUMMARY.txt';
    $fp = fopen($summary, 'w');
    fwrite($fp, "GRANVILLE TEA POS - APRIL 2026 DATA EXPORT\n");
    fwrite($fp, "Exported: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "===========================================\n\n");

    // Transaction totals by terminal
    $stmt = $db->query('SELECT term.name, COUNT(*) as txn_count, SUM(t.total) as total_sales FROM pos_transactions t JOIN pos_terminals term ON term.id = t.terminal_id WHERE t.status != "voided" GROUP BY t.terminal_id ORDER BY term.name');
    fwrite($fp, "SALES BY TERMINAL:\n");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        fwrite($fp, sprintf("  %s: %d transactions, $%s total\n", $row['name'], $row['txn_count'], number_format($row['total_sales'], 2)));
    }

    // Payment method totals
    $stmt = $db->query('SELECT p.method, COUNT(*) as cnt, SUM(p.amount) as total FROM pos_payments p JOIN pos_transactions t ON t.id = p.transaction_id WHERE t.status != "voided" GROUP BY p.method');
    fwrite($fp, "\nPAYMENTS BY METHOD:\n");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        fwrite($fp, sprintf("  %s: %d payments, $%s\n", ucfirst(str_replace('_', ' ', $row['method'])), $row['cnt'], number_format($row['total'], 2)));
    }

    // Daily totals
    $stmt = $db->query('SELECT DATE(t.created_at) as sale_date, COUNT(*) as txn_count, SUM(t.total) as total FROM pos_transactions t WHERE t.status != "voided" GROUP BY DATE(t.created_at) ORDER BY sale_date');
    fwrite($fp, "\nDAILY TOTALS:\n");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        fwrite($fp, sprintf("  %s: %d transactions, $%s\n", $row['sale_date'], $row['txn_count'], number_format($row['total'], 2)));
    }

    fclose($fp);
    echo "\n  SUMMARY.txt generated\n";

    echo "\n✓ Export complete! Files saved to:\n  $exportDir/\n";
    echo "\nDownload these files, then run:\n  php may1_reset.php wipe\n";
}

// ============================================================
// STEP 2: WIPE AND RENAME
// ============================================================
if ($action === 'wipe') {
    // Safety check: make sure export exists
    if (!is_dir($exportDir) || !file_exists($exportDir . '/pos_transactions.csv')) {
        echo "ERROR: Export directory not found! Run 'php may1_reset.php export' first.\n";
        exit(1);
    }

    echo "This will:\n";
    echo "  1. Truncate all transaction/payment/shift tables\n";
    echo "  2. Rename terminals: ID1=Loose Tea, ID2=Tea Bar, ID3=Iced Tea\n";
    echo "\nProceeding...\n\n";

    // Disable foreign key checks for truncation
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    $truncateTables = [
        'pos_transaction_item_modifiers',
        'pos_transaction_items',
        'pos_payments',
        'pos_refund_items',
        'pos_refund_payments',
        'pos_refunds',
        'pos_standalone_refunds',
        'pos_moneris_transactions',
        'pos_petty_cash',
        'pos_held_orders',
        'pos_shifts',
        'pos_drawer_opens',
        'pos_temp_auth',
        'pos_transactions',
    ];

    foreach ($truncateTables as $table) {
        $db->exec("TRUNCATE TABLE $table");
        echo "  Truncated: $table\n";
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Rename terminals to match desired numbering
    // ID 1: was "Iced Tea Register" → now "Loose Tea Counter"
    // ID 2: was "Loose Tea Counter" → now "Tea Bar Counter"
    // ID 3: was "Tea Bar Counter"   → now "Iced Tea Register"
    $db->exec("UPDATE pos_terminals SET name = 'Loose Tea Counter' WHERE id = 1");
    $db->exec("UPDATE pos_terminals SET name = 'Tea Bar Counter' WHERE id = 2");
    $db->exec("UPDATE pos_terminals SET name = 'Iced Tea Register' WHERE id = 3");

    echo "\n  Terminals renamed:\n";
    echo "    POS-1 = Loose Tea Counter\n";
    echo "    POS-2 = Tea Bar Counter\n";
    echo "    POS-3 = Iced Tea Register\n";

    echo "\n✓ Reset complete! POS is ready for May 1.\n";
}
