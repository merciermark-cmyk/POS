<?php
/**
 * Migration: Add schedule_user_id to pos_users.
 * Links POS users to schedule app users for clock in/out.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

$db->exec("ALTER TABLE pos_users ADD COLUMN schedule_user_id INT UNSIGNED NULL");

echo "Added schedule_user_id column to pos_users.\n";
