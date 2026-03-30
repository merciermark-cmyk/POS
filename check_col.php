<?php
require __DIR__ . '/app/config/config.php';
require __DIR__ . '/app/config/database.php';
$pdo = getDbConnection();
try {
    $stmt = $pdo->query('DESCRIBE pos_users staff_code');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo 'Column exists: ' . json_encode($row) . "\n";
} catch (Exception $e) {
    echo 'Column missing: ' . $e->getMessage() . "\n";
}
