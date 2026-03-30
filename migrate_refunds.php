<?php
/**
 * Migration: Add refund support to POS.
 * - Extends pos_transactions.status ENUM with 'refunded' and 'partial_refund'
 * - Creates pos_refunds, pos_refund_items, pos_refund_payments tables
 *
 * Run once: php migrate_refunds.php
 */

require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

$steps = [
    'Alter pos_transactions status ENUM' => "
        ALTER TABLE pos_transactions
        MODIFY status ENUM('completed','voided','refunded','partial_refund') NOT NULL DEFAULT 'completed'
    ",

    'Create pos_refunds' => "
        CREATE TABLE IF NOT EXISTS pos_refunds (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            original_transaction_id INT UNSIGNED NOT NULL,
            shift_id INT UNSIGNED NOT NULL,
            refunded_by INT UNSIGNED NOT NULL,
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            gst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            pst_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            reason VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_original_txn (original_transaction_id),
            INDEX idx_shift_id (shift_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Create pos_refund_items' => "
        CREATE TABLE IF NOT EXISTS pos_refund_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            refund_id INT UNSIGNED NOT NULL,
            original_item_id INT UNSIGNED NOT NULL,
            product_id INT UNSIGNED NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            product_code VARCHAR(50) NULL,
            quantity INT NOT NULL,
            unit_price DECIMAL(10,2) NOT NULL,
            tax_profile ENUM('tax_free','gst_only','gst_pst') NOT NULL DEFAULT 'tax_free',
            gst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            pst DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(10,2) NOT NULL,
            INDEX idx_refund_id (refund_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",

    'Create pos_refund_payments' => "
        CREATE TABLE IF NOT EXISTS pos_refund_payments (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            refund_id INT UNSIGNED NOT NULL,
            method ENUM('cash','card','gift_card','web_gift_card') NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            reference VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_refund_id (refund_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ",
];

foreach ($steps as $label => $sql) {
    try {
        $db->exec($sql);
        echo "OK: $label\n";
    } catch (PDOException $e) {
        echo "WARN: $label — {$e->getMessage()}\n";
    }
}

echo "\nDone. You can delete this file.\n";
