<?php
/**
 * Monthly Financial Report Generator
 *
 * Produces CRA-compliant archive reports for a given month:
 *   - transactions.csv    — every transaction line item (audit trail)
 *   - line_items.csv      — per-product detail
 *   - summary.csv         — daily + monthly totals, per-terminal, per-category
 *   - summary.html        — human-readable summary (landlord/auditor friendly)
 *   - rent_statement.html — single-page lease rent statement (8%/3% + base $1720)
 *
 * Usage (CLI):
 *   php generate_monthly_report.php <YYYY> <MM> <OUTPUT_DIR>
 *
 * Example:
 *   php generate_monthly_report.php 2026 04 /home/gitte512/backups/archive/2026-04/
 */

declare(strict_types=1);

// Lease rent terms (change here if the lease changes)
const RENT_BASE_MONTHLY = 1720.00;
const RENT_PCT_CUPS     = 0.08; // beverages
const RENT_PCT_LOOSE    = 0.08; // loose tea
const RENT_PCT_MAIL     = 0.08; // mail / web orders
const RENT_PCT_ACCESS   = 0.08; // accessories
const RENT_PCT_WHOLESALE= 0.03; // wholesale
const RENT_GST          = 0.05;

// PrestaShop constants (match pos/.env defaults)
const PS_DB    = 'gitte512_dev_staging';
const PS_PFX   = 'ps_';
const PS_SHIPPED_STATE = 4;
const PS_GIFTCARD_CAT  = 63;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php generate_monthly_report.php <YYYY> <MM> <OUTPUT_DIR>\n");
    exit(1);
}

$year  = (int)$argv[1];
$month = (int)$argv[2];
$outDir = rtrim($argv[3], '/') . '/';

if ($year < 2000 || $month < 1 || $month > 12) {
    fwrite(STDERR, "Invalid year/month.\n");
    exit(1);
}

if (!is_dir($outDir)) {
    if (!mkdir($outDir, 0755, true)) {
        fwrite(STDERR, "Cannot create output directory: $outDir\n");
        exit(1);
    }
}

// ── DB connection ───────────────────────────────────────────────────────────
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

$monthStr = sprintf('%04d-%02d', $year, $month);
$dateFrom = "$monthStr-01";
$dateTo   = date('Y-m-t', strtotime($dateFrom));

echo "Generating monthly report for $monthStr ($dateFrom to $dateTo)\n";
echo "Output: $outDir\n";

// ── 1. Transactions CSV (full audit trail) ──────────────────────────────────
$fh = fopen($outDir . 'transactions.csv', 'w');
fputcsv($fh, [
    'transaction_id', 'datetime', 'terminal', 'cashier',
    'is_wholesale', 'discount_percent', 'subtotal', 'gst', 'pst', 'total',
    'status', 'payment_methods', 'payment_amounts'
]);

$sql = "
    SELECT t.id, t.created_at, t.is_wholesale, t.discount_percent,
           t.subtotal, t.gst_amount, t.pst_amount, t.total, t.status,
           COALESCE(tm.name, '') AS terminal,
           COALESCE(u.username, '') AS cashier,
           GROUP_CONCAT(p.method ORDER BY p.id) AS methods,
           GROUP_CONCAT(p.amount ORDER BY p.id) AS amounts
    FROM pos_transactions t
    LEFT JOIN pos_terminals tm ON tm.id = t.terminal_id
    LEFT JOIN pos_users u ON u.id = t.user_id
    LEFT JOIN pos_payments p ON p.transaction_id = t.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    GROUP BY t.id
    ORDER BY t.id
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dateFrom, $dateTo]);
$txnCount = 0;
while ($r = $stmt->fetch()) {
    fputcsv($fh, [
        $r['id'], $r['created_at'], $r['terminal'], $r['cashier'],
        $r['is_wholesale'], $r['discount_percent'],
        $r['subtotal'], $r['gst_amount'], $r['pst_amount'], $r['total'],
        $r['status'], $r['methods'], $r['amounts']
    ]);
    $txnCount++;
}
fclose($fh);
echo "  transactions.csv: $txnCount transactions\n";

// ── 2. Line items CSV (per product) ─────────────────────────────────────────
$fh = fopen($outDir . 'line_items.csv', 'w');
fputcsv($fh, [
    'transaction_id', 'datetime', 'terminal', 'product_name', 'product_code',
    'category', 'parent_category', 'quantity', 'unit_price',
    'tax_profile', 'gst', 'pst', 'line_total', 'discount_percent'
]);

$sql = "
    SELECT t.id AS txn_id, t.created_at, COALESCE(tm.name, '') AS terminal,
           ti.product_name, ti.product_code, ti.quantity, ti.unit_price,
           ti.tax_profile, ti.gst, ti.pst, ti.line_total, ti.discount_percent,
           COALESCE(c.name, '') AS category,
           COALESCE(pc.name, '') AS parent_category
    FROM pos_transaction_items ti
    JOIN pos_transactions t ON t.id = ti.transaction_id
    LEFT JOIN pos_terminals tm ON tm.id = t.terminal_id
    LEFT JOIN products p ON p.id = ti.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN categories pc ON pc.id = c.parent_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND t.status IN ('completed','partial_refund')
    ORDER BY t.id, ti.id
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dateFrom, $dateTo]);
$itemCount = 0;
while ($r = $stmt->fetch()) {
    fputcsv($fh, array_values($r));
    $itemCount++;
}
fclose($fh);
echo "  line_items.csv: $itemCount items\n";

// ── 3. Summary totals ───────────────────────────────────────────────────────
$summary = [];

// Monthly totals
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS txns, COALESCE(SUM(subtotal),0) AS subtotal,
           COALESCE(SUM(gst_amount),0) AS gst, COALESCE(SUM(pst_amount),0) AS pst,
           COALESCE(SUM(total),0) AS total
    FROM pos_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
      AND status IN ('completed','partial_refund')
");
$stmt->execute([$dateFrom, $dateTo]);
$summary['month'] = $stmt->fetch();

// Daily breakdown
$stmt = $pdo->prepare("
    SELECT DATE(created_at) AS d, COUNT(*) AS txns,
           COALESCE(SUM(subtotal),0) AS subtotal,
           COALESCE(SUM(gst_amount),0) AS gst,
           COALESCE(SUM(pst_amount),0) AS pst,
           COALESCE(SUM(total),0) AS total
    FROM pos_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
      AND status IN ('completed','partial_refund')
    GROUP BY DATE(created_at) ORDER BY d
");
$stmt->execute([$dateFrom, $dateTo]);
$summary['daily'] = $stmt->fetchAll();

// Terminal breakdown
$stmt = $pdo->prepare("
    SELECT COALESCE(tm.name,'(unassigned)') AS terminal, COUNT(*) AS txns,
           COALESCE(SUM(t.subtotal),0) AS subtotal,
           COALESCE(SUM(t.gst_amount),0) AS gst,
           COALESCE(SUM(t.pst_amount),0) AS pst,
           COALESCE(SUM(t.total),0) AS total
    FROM pos_transactions t
    LEFT JOIN pos_terminals tm ON tm.id = t.terminal_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND t.status IN ('completed','partial_refund')
    GROUP BY tm.name ORDER BY tm.name
");
$stmt->execute([$dateFrom, $dateTo]);
$summary['terminals'] = $stmt->fetchAll();

// Payment method breakdown
$stmt = $pdo->prepare("
    SELECT p.method, COUNT(*) AS count, COALESCE(SUM(p.amount),0) AS total
    FROM pos_payments p
    JOIN pos_transactions t ON t.id = p.transaction_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND t.status IN ('completed','partial_refund')
    GROUP BY p.method ORDER BY p.method
");
$stmt->execute([$dateFrom, $dateTo]);
$summary['payments'] = $stmt->fetchAll();

// Category breakdown (parent-level)
$stmt = $pdo->prepare("
    SELECT COALESCE(pc.name, c.name, '(uncategorized)') AS category,
           SUM(ti.quantity) AS qty,
           COALESCE(SUM(ti.line_total - ti.gst - ti.pst),0) AS subtotal,
           COALESCE(SUM(ti.gst),0) AS gst,
           COALESCE(SUM(ti.pst),0) AS pst,
           COALESCE(SUM(ti.line_total),0) AS total
    FROM pos_transaction_items ti
    JOIN pos_transactions t ON t.id = ti.transaction_id
    LEFT JOIN products p ON p.id = ti.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN categories pc ON pc.id = c.parent_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND t.status IN ('completed','partial_refund')
    GROUP BY COALESCE(pc.name, c.name)
    ORDER BY total DESC
");
$stmt->execute([$dateFrom, $dateTo]);
$summary['categories'] = $stmt->fetchAll();

// Refunds
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total
    FROM pos_refunds
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$dateFrom, $dateTo]);
$summary['refunds'] = $stmt->fetch();

// Voids
$stmt = $pdo->prepare("
    SELECT COUNT(*) AS count, COALESCE(SUM(total),0) AS total
    FROM pos_transactions
    WHERE DATE(created_at) BETWEEN ? AND ? AND status = 'voided'
");
$stmt->execute([$dateFrom, $dateTo]);
$summary['voids'] = $stmt->fetch();

// Shifts (cash reconciliation)
$stmt = $pdo->prepare("
    SELECT s.id, s.opened_at, s.closed_at, u.username,
           COALESCE(tm.name,'') AS terminal,
           s.opening_float, s.closing_cash, s.expected_cash, s.over_short,
           s.closing_card, s.expected_card, s.card_over_short, s.closing_tips
    FROM pos_shifts s
    JOIN pos_users u ON u.id = s.user_id
    LEFT JOIN pos_terminals tm ON tm.id = s.terminal_id
    WHERE DATE(s.opened_at) BETWEEN ? AND ? AND s.status = 'closed'
    ORDER BY s.opened_at
");
$stmt->execute([$dateFrom, $dateTo]);
$summary['shifts'] = $stmt->fetchAll();

// ── Write summary.csv ───────────────────────────────────────────────────────
$fh = fopen($outDir . 'summary.csv', 'w');

fputcsv($fh, ['MONTHLY SUMMARY', $monthStr]);
fputcsv($fh, []);
fputcsv($fh, ['Metric', 'Value']);
fputcsv($fh, ['Total transactions', $summary['month']['txns']]);
fputcsv($fh, ['Subtotal (before tax)', number_format((float)$summary['month']['subtotal'], 2, '.', '')]);
fputcsv($fh, ['GST (5%)',              number_format((float)$summary['month']['gst'], 2, '.', '')]);
fputcsv($fh, ['PST (7%)',              number_format((float)$summary['month']['pst'], 2, '.', '')]);
fputcsv($fh, ['Grand total',           number_format((float)$summary['month']['total'], 2, '.', '')]);
fputcsv($fh, []);

fputcsv($fh, ['DAILY BREAKDOWN']);
fputcsv($fh, ['Date','Txns','Subtotal','GST','PST','Total']);
foreach ($summary['daily'] as $d) {
    fputcsv($fh, [$d['d'], $d['txns'], $d['subtotal'], $d['gst'], $d['pst'], $d['total']]);
}
fputcsv($fh, []);

fputcsv($fh, ['PER TERMINAL']);
fputcsv($fh, ['Terminal','Txns','Subtotal','GST','PST','Total']);
foreach ($summary['terminals'] as $t) {
    fputcsv($fh, [$t['terminal'], $t['txns'], $t['subtotal'], $t['gst'], $t['pst'], $t['total']]);
}
fputcsv($fh, []);

fputcsv($fh, ['PAYMENT METHODS']);
fputcsv($fh, ['Method','Count','Total']);
foreach ($summary['payments'] as $p) {
    fputcsv($fh, [$p['method'], $p['count'], $p['total']]);
}
fputcsv($fh, []);

fputcsv($fh, ['CATEGORIES']);
fputcsv($fh, ['Category','Qty','Subtotal','GST','PST','Total']);
foreach ($summary['categories'] as $c) {
    fputcsv($fh, [$c['category'], $c['qty'], $c['subtotal'], $c['gst'], $c['pst'], $c['total']]);
}
fputcsv($fh, []);

fputcsv($fh, ['REFUNDS / VOIDS']);
fputcsv($fh, ['Refund count', $summary['refunds']['count']]);
fputcsv($fh, ['Refund total', $summary['refunds']['total']]);
fputcsv($fh, ['Void count',   $summary['voids']['count']]);
fputcsv($fh, ['Void total',   $summary['voids']['total']]);
fputcsv($fh, []);

fputcsv($fh, ['SHIFT RECONCILIATION']);
fputcsv($fh, ['Shift','Opened','Closed','Cashier','Terminal','Float','Exp Cash','Cnt Cash','Cash +/-','Exp Card','Cnt Card','Card +/-','Tips']);
foreach ($summary['shifts'] as $s) {
    fputcsv($fh, [
        $s['id'], $s['opened_at'], $s['closed_at'], $s['username'], $s['terminal'],
        $s['opening_float'], $s['expected_cash'], $s['closing_cash'], $s['over_short'],
        $s['expected_card'], $s['closing_card'], $s['card_over_short'], $s['closing_tips']
    ]);
}
fclose($fh);
echo "  summary.csv: written\n";

// ── Write summary.html (human-readable) ─────────────────────────────────────
$h = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$money = function($v) { return '$' . number_format((float)$v, 2); };

ob_start();
?><!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>Monthly Financial Report — <?= $h($monthStr) ?></title>
<style>
body{font-family:system-ui,Arial;max-width:1000px;margin:30px auto;padding:0 20px;color:#222}
h1,h2{border-bottom:2px solid #444;padding-bottom:5px}
h2{margin-top:35px;font-size:18px}
table{border-collapse:collapse;width:100%;margin:10px 0}
th,td{border:1px solid #ccc;padding:6px 10px;text-align:left}
th{background:#f4f4f4}
td.num{text-align:right;font-variant-numeric:tabular-nums}
.totals td{font-weight:bold;background:#fafafa}
.footer{margin-top:40px;font-size:11px;color:#888;text-align:center}
</style></head><body>
<h1>Granville Tea — Monthly Financial Report</h1>
<p><strong>Period:</strong> <?= $h($dateFrom) ?> to <?= $h($dateTo) ?></p>
<p><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></p>

<h2>Monthly Totals</h2>
<table>
<tr><th>Transactions</th><td class="num"><?= (int)$summary['month']['txns'] ?></td></tr>
<tr><th>Subtotal (pre-tax)</th><td class="num"><?= $money($summary['month']['subtotal']) ?></td></tr>
<tr><th>GST (5%)</th><td class="num"><?= $money($summary['month']['gst']) ?></td></tr>
<tr><th>PST (7%)</th><td class="num"><?= $money($summary['month']['pst']) ?></td></tr>
<tr class="totals"><th>Grand Total</th><td class="num"><?= $money($summary['month']['total']) ?></td></tr>
</table>

<h2>Daily Sales</h2>
<table>
<tr><th>Date</th><th>Txns</th><th>Subtotal</th><th>GST</th><th>PST</th><th>Total</th></tr>
<?php foreach ($summary['daily'] as $d): ?>
<tr><td><?= $h($d['d']) ?></td><td class="num"><?= (int)$d['txns'] ?></td>
<td class="num"><?= $money($d['subtotal']) ?></td><td class="num"><?= $money($d['gst']) ?></td>
<td class="num"><?= $money($d['pst']) ?></td><td class="num"><?= $money($d['total']) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Per-Terminal Breakdown</h2>
<table>
<tr><th>Terminal</th><th>Txns</th><th>Subtotal</th><th>GST</th><th>PST</th><th>Total</th></tr>
<?php foreach ($summary['terminals'] as $t): ?>
<tr><td><?= $h($t['terminal']) ?></td><td class="num"><?= (int)$t['txns'] ?></td>
<td class="num"><?= $money($t['subtotal']) ?></td><td class="num"><?= $money($t['gst']) ?></td>
<td class="num"><?= $money($t['pst']) ?></td><td class="num"><?= $money($t['total']) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Payment Methods</h2>
<table>
<tr><th>Method</th><th>Count</th><th>Total</th></tr>
<?php foreach ($summary['payments'] as $p): ?>
<tr><td><?= $h(ucfirst(str_replace('_',' ',$p['method']))) ?></td>
<td class="num"><?= (int)$p['count'] ?></td><td class="num"><?= $money($p['total']) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Category Breakdown</h2>
<table>
<tr><th>Category</th><th>Qty</th><th>Subtotal</th><th>GST</th><th>PST</th><th>Total</th></tr>
<?php foreach ($summary['categories'] as $c): ?>
<tr><td><?= $h($c['category']) ?></td><td class="num"><?= rtrim(rtrim(number_format((float)$c['qty'],2),'0'),'.') ?></td>
<td class="num"><?= $money($c['subtotal']) ?></td><td class="num"><?= $money($c['gst']) ?></td>
<td class="num"><?= $money($c['pst']) ?></td><td class="num"><?= $money($c['total']) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Refunds &amp; Voids</h2>
<table>
<tr><th>Refunds</th><td class="num"><?= (int)$summary['refunds']['count'] ?> (<?= $money($summary['refunds']['total']) ?>)</td></tr>
<tr><th>Voids</th><td class="num"><?= (int)$summary['voids']['count'] ?> (<?= $money($summary['voids']['total']) ?>)</td></tr>
</table>

<h2>Shift Reconciliation</h2>
<table>
<tr><th>Shift</th><th>Date</th><th>Cashier</th><th>Terminal</th>
<th>Float</th><th>Cash Exp / Cnt / +-</th><th>Card Exp / Cnt / +-</th><th>Tips</th></tr>
<?php foreach ($summary['shifts'] as $s): ?>
<tr><td>#<?= (int)$s['id'] ?></td><td><?= $h(substr((string)$s['opened_at'],0,10)) ?></td>
<td><?= $h($s['username']) ?></td><td><?= $h($s['terminal']) ?></td>
<td class="num"><?= $money($s['opening_float']) ?></td>
<td class="num"><?= $money($s['expected_cash']) ?> / <?= $money($s['closing_cash']) ?> / <?= $money($s['over_short']) ?></td>
<td class="num"><?= $s['expected_card'] !== null ? $money($s['expected_card']) . ' / ' . $money($s['closing_card']) . ' / ' . $money($s['card_over_short']) : '—' ?></td>
<td class="num"><?= $s['closing_tips'] !== null ? $money($s['closing_tips']) : '—' ?></td></tr>
<?php endforeach; ?>
</table>

<p class="footer">Generated by the Granville Tea POS archive system. Retain this document for at least 7 years for CRA compliance.</p>
</body></html>
<?php
file_put_contents($outDir . 'summary.html', ob_get_clean());
echo "  summary.html: written\n";

// ── 4. Rent statement (single-page, matches legacy landlord format) ─────────
// Per-day breakdown: Final | Count | Cups | Loose | Mail | Access | Wholesale
// Wholesale = SUM(total) for transactions where is_wholesale=1 (bucketed on its own).
// Non-wholesale transactions get split by parent category into Cups/Loose/Access.
// Mail = PrestaShop orders shipped on that date (excludes gift card line items).

$daysInMonth = (int)date('t', strtotime($dateFrom));

// Category totals per day for non-wholesale transactions
$stmt = $pdo->prepare("
    SELECT DATE(t.created_at) AS d,
           COALESCE(pc.name, c.name, '') AS cat,
           COALESCE(SUM(ti.line_total - ti.gst - ti.pst), 0) AS amt
    FROM pos_transaction_items ti
    JOIN pos_transactions t ON t.id = ti.transaction_id
    LEFT JOIN products p ON p.id = ti.product_id
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN categories pc ON pc.id = c.parent_id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND t.status IN ('completed','partial_refund')
      AND t.is_wholesale = 0
    GROUP BY DATE(t.created_at), COALESCE(pc.name, c.name)
");
$stmt->execute([$dateFrom, $dateTo]);
$catByDay = []; // [date][category] = amount
foreach ($stmt->fetchAll() as $r) {
    $catByDay[$r['d']][$r['cat']] = (float)$r['amt'];
}

// Manual entries with no line items (e.g. Iced Tea Register end-of-day lump sums).
// These have is_manual_entry=1 and no rows in pos_transaction_items.
// Iced Tea Register sells only beverages, so count subtotal as Cups (Beverages).
$stmt = $pdo->prepare("
    SELECT DATE(t.created_at) AS d,
           COALESCE(SUM(t.subtotal), 0) AS amt
    FROM pos_transactions t
    LEFT JOIN pos_transaction_items ti ON ti.transaction_id = t.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
      AND t.status IN ('completed','partial_refund')
      AND t.is_wholesale = 0
      AND t.is_manual_entry = 1
      AND ti.id IS NULL
      AND t.terminal_id = (
          SELECT id FROM pos_terminals WHERE name = 'Iced Tea Register' LIMIT 1
      )
    GROUP BY DATE(t.created_at)
");
$stmt->execute([$dateFrom, $dateTo]);
foreach ($stmt->fetchAll() as $r) {
    $catByDay[$r['d']]['Beverages'] = ($catByDay[$r['d']]['Beverages'] ?? 0) + (float)$r['amt'];
}

// Wholesale total + transaction count per day
$stmt = $pdo->prepare("
    SELECT DATE(created_at) AS d,
           COUNT(*) AS txns,
           COALESCE(SUM(CASE WHEN is_wholesale=1 THEN subtotal ELSE 0 END), 0) AS wholesale
    FROM pos_transactions
    WHERE DATE(created_at) BETWEEN ? AND ?
      AND status IN ('completed','partial_refund')
    GROUP BY DATE(created_at)
");
$stmt->execute([$dateFrom, $dateTo]);
$dayStats = [];
foreach ($stmt->fetchAll() as $r) {
    $dayStats[$r['d']] = $r;
}

// Mail orders (PrestaShop) per day — shipped_on_date logic from WebOrder model
$mailByDay = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE(oh.date_add) AS d,
               COALESCE(SUM(od.total_price_tax_excl), 0) AS amt
        FROM `" . PS_DB . "`.`" . PS_PFX . "order_history` oh
        JOIN `" . PS_DB . "`.`" . PS_PFX . "orders` o
          ON o.id_order = oh.id_order
        JOIN `" . PS_DB . "`.`" . PS_PFX . "order_detail` od
          ON od.id_order = o.id_order
        WHERE oh.id_order_state = ?
          AND DATE(oh.date_add) BETWEEN ? AND ?
          AND od.product_id NOT IN (
              SELECT cp.id_product
              FROM `" . PS_DB . "`.`" . PS_PFX . "category_product` cp
              WHERE cp.id_category = ?
          )
        GROUP BY DATE(oh.date_add)
    ");
    $stmt->execute([PS_SHIPPED_STATE, $dateFrom, $dateTo, PS_GIFTCARD_CAT]);
    foreach ($stmt->fetchAll() as $r) {
        $mailByDay[$r['d']] = (float)$r['amt'];
    }
} catch (Throwable $e) {
    fwrite(STDERR, "  (warn) mail orders query failed: " . $e->getMessage() . "\n");
}

// Build day rows + running totals
$rentRows = [];
$totalFinal = $totalCount = 0;
$totalCups = $totalLoose = $totalMail = $totalAccess = $totalWholesale = 0.0;

for ($day = 1; $day <= $daysInMonth; $day++) {
    $d = sprintf('%s-%02d', $monthStr, $day);
    $cups      = (float)($catByDay[$d]['Beverages']   ?? 0);
    $loose     = (float)($catByDay[$d]['Loose Tea']   ?? 0);
    $access    = (float)($catByDay[$d]['Accessories'] ?? 0);
    $wholesale = (float)($dayStats[$d]['wholesale']   ?? 0);
    $mail      = (float)($mailByDay[$d]               ?? 0);
    $count     = (int)  ($dayStats[$d]['txns']        ?? 0);
    $final     = $cups + $loose + $access + $wholesale + $mail;

    $rentRows[] = compact('day','final','count','cups','loose','mail','access','wholesale');

    $totalFinal     += $final;
    $totalCount     += $count;
    $totalCups      += $cups;
    $totalLoose     += $loose;
    $totalMail      += $mail;
    $totalAccess    += $access;
    $totalWholesale += $wholesale;
}

// Rent calculation
$rCups      = $totalCups      * RENT_PCT_CUPS;
$rLoose     = $totalLoose     * RENT_PCT_LOOSE;
$rMail      = $totalMail      * RENT_PCT_MAIL;
$rAccess    = $totalAccess    * RENT_PCT_ACCESS;
$rWholesale = $totalWholesale * RENT_PCT_WHOLESALE;
$rentGross  = $rCups + $rLoose + $rMail + $rAccess + $rWholesale;
$rentSub    = $rentGross - RENT_BASE_MONTHLY;
// If percentage rent doesn't exceed base, no additional rent owed.
$rentOwed   = max(0, $rentSub);
$rentGst    = $rentOwed * RENT_GST;
$rentDue    = $rentOwed + $rentGst;

$monthName = date('F Y', strtotime($dateFrom));

ob_start();
?><!DOCTYPE html>
<html><head><meta charset="utf-8">
<title>Sales Report — <?= $h($monthName) ?></title>
<style>
@page { size: letter; margin: 0.5in; }
body{font-family:Arial,Helvetica,sans-serif;color:#000;margin:0;padding:0;font-size:11px}
h1{font-size:16px;text-align:center;margin:0 0 2px}
h2{font-size:13px;text-align:center;font-weight:normal;margin:0 0 12px}
table{border-collapse:collapse;width:100%;font-size:10.5px}
th,td{border:1px solid #999;padding:2px 5px;text-align:right;font-variant-numeric:tabular-nums}
th{background:#eee;text-align:center;font-weight:bold}
td.day{text-align:center;width:26px}
tr.tot td{font-weight:bold;background:#f4f4f4;border-top:2px solid #000}
tr.pct td{font-style:italic;color:#555}
tr.pctamt td{background:#fafafa}
tr.final td{font-weight:bold;background:#eef;border-top:2px solid #000}
.rentbox{margin-top:10px;float:right;width:50%}
.rentbox td{border:none;padding:1px 6px}
.rentbox td:first-child{text-align:left}
.rentbox tr.sep td{border-top:1px solid #999}
.rentbox tr.due td{font-weight:bold;border-top:2px solid #000;font-size:12px}
.footer{clear:both;text-align:center;font-size:9px;color:#666;margin-top:30px;padding-top:10px;border-top:1px solid #ccc}
</style></head><body>
<h1>Sales Report &mdash; Granville Island Tea Co.</h1>
<h2><?= $h($monthName) ?></h2>

<table>
<tr><th>Day</th><th>Final</th><th>Count</th><th>Cups</th><th>Loose</th><th>Mail Orders</th><th>Accessories</th><th>Wholesale</th></tr>
<?php foreach ($rentRows as $r): ?>
<tr><td class="day"><?= $r['day'] ?></td>
<td><?= $money($r['final']) ?></td>
<td><?= $r['count'] ?></td>
<td><?= $money($r['cups']) ?></td>
<td><?= $money($r['loose']) ?></td>
<td><?= $money($r['mail']) ?></td>
<td><?= $money($r['access']) ?></td>
<td><?= $money($r['wholesale']) ?></td></tr>
<?php endforeach; ?>

<tr class="tot"><td></td>
<td><?= $money($totalFinal) ?></td>
<td><?= $totalCount ?></td>
<td><?= $money($totalCups) ?></td>
<td><?= $money($totalLoose) ?></td>
<td><?= $money($totalMail) ?></td>
<td><?= $money($totalAccess) ?></td>
<td><?= $money($totalWholesale) ?></td></tr>

<tr class="pct"><td colspan="3" style="text-align:right">% rate</td>
<td>8%</td><td>8%</td><td>8%</td><td>8%</td><td>3%</td></tr>

<tr class="pctamt"><td colspan="3" style="text-align:right">% amount</td>
<td><?= $money($rCups) ?></td>
<td><?= $money($rLoose) ?></td>
<td><?= $money($rMail) ?></td>
<td><?= $money($rAccess) ?></td>
<td><?= $money($rWholesale) ?></td></tr>
</table>

<table class="rentbox">
<tr><td>Percentage rent (sum)</td><td style="text-align:right"><?= $money($rentGross) ?></td></tr>
<tr><td>Less base rent</td><td style="text-align:right">(<?= $money(RENT_BASE_MONTHLY) ?>)</td></tr>
<tr class="sep"><td>Sub total</td><td style="text-align:right"><?= $money($rentSub) ?><?= $rentSub < 0 ? ' (below base — no additional rent)' : '' ?></td></tr>
<tr><td>GST (5%)</td><td style="text-align:right"><?= $money($rentGst) ?></td></tr>
<tr class="due"><td>Total additional rent due</td><td style="text-align:right"><?= $money($rentDue) ?></td></tr>
</table>

<p class="footer">Generated <?= date('Y-m-d H:i:s') ?> by Granville Tea POS archive system. Retain 7 years (CRA).</p>
</body></html>
<?php
file_put_contents($outDir . 'rent_statement.html', ob_get_clean());
echo "  rent_statement.html: written\n";

echo "Done.\n";
