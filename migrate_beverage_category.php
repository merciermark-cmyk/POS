<?php
/**
 * Migration: Add Beverages and Wholesale categories.
 * Run once, then delete.
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

$db = getDB();

$needed = ['Beverages', 'Wholesale'];

foreach ($needed as $name) {
    $exists = $db->prepare('SELECT id FROM categories WHERE name = ?');
    $exists->execute([$name]);
    if ($exists->fetch()) {
        echo "Category '$name' already exists.\n";
    } else {
        $db->prepare('INSERT INTO categories (name) VALUES (?)')->execute([$name]);
        echo "Created category '$name' (id=" . $db->lastInsertId() . ").\n";
    }
}
