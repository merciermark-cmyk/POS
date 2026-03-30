<?php
/**
 * Migration: Add staff_code column to pos_users.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$pdo = getDbConnection();

$pdo->exec("ALTER TABLE pos_users ADD COLUMN staff_code CHAR(3) NULL AFTER pin");

echo "OK — staff_code column added to pos_users.\n";
