<?php
/**
 * One-off migration (2026-05-18):
 *   1. Add `closed_by_table` ENUM('users','pos_users') to dayclose_counts.
 *      Disambiguates `closed_by` which has no FK and collides between the two user tables
 *      (id=4 = Jayson in users AND Faye F in pos_users). Every historical close was made
 *      by a pos_users row (staff dropdown is PosUser::getActive), so backfill = 'pos_users'.
 *   2. Add `close_errors` TEXT NULL to surface silent closeShifts() failures
 *      (see 2026-05-10 R3 incident: shift 31 hung 7h because per-register catch swallowed the throw).
 *
 * Usage:
 *   php migrate_dayclose_closed_by_and_errors.php              # DRY RUN — report only
 *   php migrate_dayclose_closed_by_and_errors.php --apply      # actually run ALTER + backfill
 */

$apply = in_array('--apply', $argv);

$DB_HOST = 'localhost';
$DB_NAME = 'gitte512_git_inventory';
$DB_USER = 'gitte512_inventory_manager';
$DB_PASS = 'Starlifter44*';

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Mode: " . ($apply ? "APPLY" : "DRY-RUN") . "\n\n";

// ── Probe current schema ──────────────────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM dayclose_counts")->fetchAll();
$colNames = array_column($cols, 'Field');
$hasClosedByTable = in_array('closed_by_table', $colNames);
$hasCloseErrors   = in_array('close_errors', $colNames);

echo "Current schema state:\n";
echo "  closed_by_table column: " . ($hasClosedByTable ? "exists" : "missing") . "\n";
echo "  close_errors column:    " . ($hasCloseErrors ? "exists" : "missing") . "\n\n";

// ── Plan ──────────────────────────────────────────────────────────
$steps = [];

if (!$hasClosedByTable) {
    $steps[] = [
        'desc' => "ALTER TABLE dayclose_counts ADD COLUMN closed_by_table ENUM('users','pos_users') NULL AFTER closed_by",
        'sql'  => "ALTER TABLE dayclose_counts ADD COLUMN closed_by_table ENUM('users','pos_users') NULL AFTER closed_by",
    ];
}

if (!$hasCloseErrors) {
    $steps[] = [
        'desc' => "ALTER TABLE dayclose_counts ADD COLUMN close_errors TEXT NULL",
        'sql'  => "ALTER TABLE dayclose_counts ADD COLUMN close_errors TEXT NULL",
    ];
}

// Backfill is a separate step (runs after ALTERs are applied)
$existing = $pdo->query(
    "SELECT id, closed_by FROM dayclose_counts " .
    ($hasClosedByTable ? "WHERE closed_by_table IS NULL" : "")
)->fetchAll();
$toBackfill = count($existing);
echo "Backfill candidates: $toBackfill row(s) with NULL closed_by_table\n";
foreach ($existing as $r) {
    echo "  id={$r['id']} closed_by={$r['closed_by']}\n";
}
echo "\n";

if (!$apply) {
    echo "Steps that WOULD run:\n";
    foreach ($steps as $s) echo "  - {$s['desc']}\n";
    if ($toBackfill > 0) echo "  - UPDATE dayclose_counts SET closed_by_table='pos_users' WHERE closed_by_table IS NULL ($toBackfill rows)\n";
    echo "\nDry-run only. Re-run with --apply to execute.\n";
    exit(0);
}

// ── Apply ─────────────────────────────────────────────────────────
foreach ($steps as $s) {
    echo "→ {$s['desc']}\n";
    $pdo->exec($s['sql']);
    echo "  ok\n";
}

if ($toBackfill > 0) {
    echo "→ Backfilling closed_by_table='pos_users' on $toBackfill row(s)\n";
    $n = $pdo->exec("UPDATE dayclose_counts SET closed_by_table='pos_users' WHERE closed_by_table IS NULL");
    echo "  $n row(s) updated\n";
}

echo "\nDone.\n";
