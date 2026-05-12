<?php
/**
 * One-time diagnostic: verify the PrestaShop "Shipped" order state ID.
 * Run from CLI: php migrate_web_sales_config.php
 */
require __DIR__ . '/app/config/config.php';
require __DIR__ . '/app/helpers/db.php';

if (!PS_DB_NAME) {
    echo "PS_DB_NAME is not configured. Set it in .env first.\n";
    exit(1);
}

$psDb   = PS_DB_NAME;
$prefix = PS_DB_PREFIX;
$db     = getDB();

echo "Looking up order states in {$psDb}.{$prefix}order_state_lang ...\n\n";

$stmt = $db->query(
    "SELECT osl.id_order_state, osl.name
     FROM `$psDb`.`{$prefix}order_state_lang` osl
     WHERE osl.id_lang = 1
     ORDER BY osl.id_order_state"
);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    $marker = ((int)$row['id_order_state'] === PS_SHIPPED_STATE_ID) ? ' <-- CURRENT CONFIG' : '';
    printf("  [%d] %s%s\n", $row['id_order_state'], $row['name'], $marker);
}

echo "\nPS_SHIPPED_STATE_ID is currently set to: " . PS_SHIPPED_STATE_ID . "\n";
echo "If the 'Shipped' state has a different ID above, update PS_SHIPPED_STATE_ID in .env or config.php.\n";
