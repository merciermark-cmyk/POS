<?php
/**
 * Migration: Add modifier_group column to pos_modifiers
 * and insert loose tea tin modifiers.
 *
 * Run once, then delete this file.
 */
require __DIR__ . '/app/config/config.php';
require __DIR__ . '/app/config/database.php';

$pdo = getDB();

echo "Adding modifier_group column...\n";
$pdo->exec("ALTER TABLE pos_modifiers ADD COLUMN modifier_group VARCHAR(20) NOT NULL DEFAULT 'beverage' AFTER is_active");

echo "Setting existing modifiers to 'beverage'...\n";
$pdo->exec("UPDATE pos_modifiers SET modifier_group = 'beverage'");

echo "Inserting loose tea tin modifiers...\n";
$stmt = $pdo->prepare(
    "INSERT INTO pos_modifiers (name, price, sort_order, is_active, modifier_group) VALUES (?, ?, ?, 1, 'loose_tea')"
);

$tins = [
    ['50g Sq Tin',       3.50, 1],
    ['100g Sq Tin',      4.50, 2],
    ['50g Round Tin',    4.00, 3],
    ['100g Round Tin',   4.75, 4],
    ['Click Clack Tin',  3.90, 5],
    ['Sample Tin',       3.90, 6],
    ['Window Box Tin',   4.75, 7],
];

foreach ($tins as [$name, $price, $sort]) {
    $stmt->execute([$name, $price, $sort]);
    echo "  + $name ($price)\n";
}

echo "\nDone! You can delete this file now.\n";
