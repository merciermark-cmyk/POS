<?php
/**
 * One-off backfill: synthesize pos_transactions rows for R3 from dayclose_counts
 * for dates where dayclose was completed but no R3 transaction was created.
 *
 * Mirrors DayClose::upsertR3Transaction() exactly. Idempotent.
 *
 * Usage:
 *   php backfill_r3_dayclose_to_transactions.php              # DRY RUN — report only
 *   php backfill_r3_dayclose_to_transactions.php --apply      # actually write
 */

$apply = in_array('--apply', $argv);
$dates = ['2026-05-10', '2026-05-11', '2026-05-12', '2026-05-13', '2026-05-14', '2026-05-15', '2026-05-16', '2026-05-17'];

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

echo "Mode: " . ($apply ? "APPLY" : "DRY-RUN") . "\n";
echo "Dates: " . implode(', ', $dates) . "\n\n";

$summary = [];

foreach ($dates as $date) {
    echo "─── $date ───\n";

    // Load dayclose row
    $dc = $pdo->prepare("SELECT * FROM dayclose_counts WHERE close_date = ? AND status = 'completed'");
    $dc->execute([$date]);
    $row = $dc->fetch();
    if (!$row) { echo "  no completed dayclose row — skip\n"; continue; }

    $total = $row['r3_total_sales'] !== null ? round((float)$row['r3_total_sales'], 2) : null;
    if ($total === null || $total <= 0) { echo "  r3_total_sales empty — skip\n"; continue; }

    // Find R3 shift for this date (closed_at preferred; opened_at fallback for overnight closes)
    $sh = $pdo->prepare("SELECT id FROM pos_shifts WHERE terminal_id = 3 AND (DATE(closed_at) = ? OR DATE(opened_at) = ?) ORDER BY id DESC LIMIT 1");
    $sh->execute([$date, $date]);
    $shift = $sh->fetch();
    if (!$shift) { echo "  no R3 pos_shifts row — skip (unexpected)\n"; continue; }
    $shiftId = (int)$shift['id'];

    // Idempotency check — skip if prior R3 txn exists on this shift
    $prior = $pdo->prepare("SELECT id FROM pos_transactions WHERE shift_id = ? AND terminal_id = 3 LIMIT 1");
    $prior->execute([$shiftId]);
    if ($prior->fetch()) {
        echo "  R3 txn already exists on shift $shiftId — skip\n";
        continue;
    }

    $gst      = $row['r3_gst']  !== null ? round((float)$row['r3_gst'], 2)  : 0.0;
    $subtotal = round($total - $gst, 2);
    $cash     = $row['r3_cash'] !== null ? round((float)$row['r3_cash'], 2) : 0.0;
    $card     = $row['r3_card'] !== null ? round((float)$row['r3_card'], 2) : 0.0;
    $tips     = $row['r3_tips'] !== null ? round((float)$row['r3_tips'], 2) : null;
    $txnCount = $row['r3_txn_count'] !== null ? (int)$row['r3_txn_count'] : null;
    $closedBy = $row['closed_by'] !== null ? (int)$row['closed_by'] : null;
    // Time-stamp the txn at end-of-day so daily_number lands after the day's real txns
    $createdAt = $date . ' 21:00:00';

    echo "  shift=$shiftId total=$total gst=$gst subtotal=$subtotal cash=$cash card=$card tips=" . ($tips ?? 'null') . " count=" . ($txnCount ?? 'null') . "\n";

    if (!$apply) { echo "  (dry-run — no write)\n"; $summary[$date] = 'would-insert'; continue; }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            "INSERT INTO pos_transactions
             (shift_id, terminal_id, user_id, subtotal, gst_amount, pst_amount, tip_amount, total,
              status, is_manual_entry, transaction_count, notes, created_at)
             VALUES (?, 3, ?, ?, ?, 0, ?, ?, 'completed', 1, ?, ?, ?)"
        );
        $ins->execute([$shiftId, $closedBy, $subtotal, $gst, $tips, $total, $txnCount,
            'Auto-created from Close Registers (R3 register tape) [backfill 2026-05-16]', $createdAt]);
        $txnId = (int)$pdo->lastInsertId();

        if ($cash > 0) {
            $pdo->prepare("INSERT INTO pos_payments (transaction_id, method, amount) VALUES (?, 'cash', ?)")
                ->execute([$txnId, $cash]);
        }
        if ($card > 0) {
            $pdo->prepare("INSERT INTO pos_payments (transaction_id, method, amount) VALUES (?, 'card', ?)")
                ->execute([$txnId, $card]);
        }

        // Counters (uses created_at date, not CURDATE)
        $cstmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND DATE(created_at) = DATE(?) AND id < ?) + 1 AS daily_number,
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND YEAR(created_at) = YEAR(?) AND MONTH(created_at) = MONTH(?) AND id < ?) + 1 AS monthly_number,
                (SELECT COUNT(*) FROM pos_transactions WHERE status = 'completed' AND YEAR(created_at) = YEAR(?) AND id < ?) + 1 AS annual_number"
        );
        $cstmt->execute([$createdAt, $txnId, $createdAt, $createdAt, $txnId, $createdAt, $txnId]);
        $c = $cstmt->fetch();
        $pdo->prepare("UPDATE pos_transactions SET daily_number = ?, monthly_number = ?, annual_number = ? WHERE id = ?")
            ->execute([(int)$c['daily_number'], (int)$c['monthly_number'], (int)$c['annual_number'], $txnId]);

        $pdo->commit();
        echo "  ✓ inserted txn id=$txnId (daily=$c[daily_number] monthly=$c[monthly_number] annual=$c[annual_number])\n";
        $summary[$date] = "inserted #$txnId";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  ✗ FAILED: " . $e->getMessage() . "\n";
        $summary[$date] = 'error';
    }
}

echo "\n=== Summary ===\n";
foreach ($summary as $d => $s) echo "  $d  $s\n";
echo "\n" . ($apply ? "Done." : "Dry-run complete. Re-run with --apply to write.") . "\n";
