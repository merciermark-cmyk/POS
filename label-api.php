<?php
/**
 * Label API — returns product data for the label printing app.
 * Public read-only endpoint (product catalog is not sensitive).
 */
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$search = trim($_GET['search'] ?? '');
$db = getDB();

if ($search !== '') {
    $stmt = $db->prepare(
        "SELECT p.id, p.product_code, p.name, p.brewing_instructions, c.name AS category
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.deleted_at IS NULL
           AND (p.name LIKE ? OR p.product_code LIKE ?)
         ORDER BY p.name
         LIMIT 100"
    );
    $like = "%{$search}%";
    $stmt->execute([$like, $like]);
} else {
    $stmt = $db->query(
        "SELECT p.id, p.product_code, p.name, p.brewing_instructions, c.name AS category
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.deleted_at IS NULL
         ORDER BY c.name, p.name
         LIMIT 500"
    );
}

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
