<?php
/**
 * POS End-of-Day Sentinel
 *
 * Run nightly at 21:00 PT via cron. Sends an email if either:
 *   1) Any pos_shifts row has status='open' on an active terminal.
 *   2) The Iced Tea register (terminal_id=3) is active but has no
 *      shift opened today (i.e. manual entry was skipped).
 *
 * Silent exit when everything looks normal — no daily "all clear"
 * emails. Recipients: merciermark@gmail.com + mark@granvilletea.com.
 *
 * Cron:
 *   0 21 * * * /usr/local/bin/ea-php82 /home/gitte512/public_html/pos.granvilletea.com/scripts/check_open_shifts.php
 *
 * Manual dry run (prints email body, sends nothing):
 *   php check_open_shifts.php --dry-run
 */

declare(strict_types=1);

date_default_timezone_set('America/Vancouver');

$dryRun = in_array('--dry-run', $argv ?? [], true);

const DB_HOST = 'localhost';
const DB_NAME = 'gitte512_git_inventory';
const DB_USER = 'gitte512_inventory_manager';
const DB_PASS = 'Starlifter44*';

const RECIPIENTS = ['merciermark@gmail.com', 'mark@granvilletea.com'];
const ICED_TEA_TERMINAL_ID = 3;
const FROM_EMAIL = 'pos@granvilletea.com';
const FROM_NAME  = 'POS Alerts';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// ── Check 1: open shifts on active terminals ────────────────────────────────
$stmt = $pdo->prepare("
    SELECT s.id, s.opened_at, t.name AS terminal_name, u.username AS opener
    FROM pos_shifts s
    JOIN pos_terminals t ON t.id = s.terminal_id
    JOIN pos_users u     ON u.id = s.user_id
    WHERE s.status = 'open'
      AND t.is_active = 1
    ORDER BY s.opened_at
");
$stmt->execute();
$openShifts = $stmt->fetchAll();

// ── Check 2: Iced Tea register active but no entry today ────────────────────
$icedMissing = false;
$stmt = $pdo->prepare('SELECT is_active, name FROM pos_terminals WHERE id = ?');
$stmt->execute([ICED_TEA_TERMINAL_ID]);
$iced = $stmt->fetch();

if ($iced && (int)$iced['is_active'] === 1) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS cnt
        FROM pos_shifts
        WHERE terminal_id = ?
          AND DATE(opened_at) = CURDATE()
    ");
    $stmt->execute([ICED_TEA_TERMINAL_ID]);
    $row = $stmt->fetch();
    $icedMissing = ((int)$row['cnt'] === 0);
}

// ── Nothing wrong → exit silently (or report on dry run) ────────────────────
if (empty($openShifts) && !$icedMissing) {
    if ($dryRun) {
        echo "[dry-run] All clear at " . date('Y-m-d H:i:s T') . " -- no email would be sent.\n";
        echo "[dry-run] Iced Tea (terminal " . ICED_TEA_TERMINAL_ID . ") active: ";
        echo ($iced && (int)$iced['is_active'] === 1) ? "yes\n" : "no (alert suppressed)\n";
    }
    exit(0);
}

// ── Compose alert email ─────────────────────────────────────────────────────
$today = date('Y-m-d (l)');
$now   = date('g:i A');

$lines = [];
$lines[] = "POS End-of-Day Alert -- $today, $now PT";
$lines[] = str_repeat('=', 60);
$lines[] = '';

if (!empty($openShifts)) {
    $lines[] = '*** OPEN SHIFTS STILL ACTIVE ***';
    $lines[] = '';
    foreach ($openShifts as $s) {
        $lines[] = sprintf(
            '  - %s: opened by %s at %s (shift #%d)',
            $s['terminal_name'],
            $s['opener'],
            date('g:i A', strtotime($s['opened_at'])),
            $s['id']
        );
    }
    $lines[] = '';
    $lines[] = "These shifts must be closed before midnight. A late close stamps";
    $lines[] = "closed_at with the wrong date, makes the cash count fiction, and";
    $lines[] = "blocks the morning open on that terminal until it's resolved.";
    $lines[] = '';
}

if ($icedMissing) {
    $lines[] = '*** ICED TEA REGISTER: NO ENTRY TODAY ***';
    $lines[] = '';
    $lines[] = 'No manual entry recorded for the Iced Tea register today.';
    $lines[] = 'If the register was used, the closer needs to submit the manual';
    $lines[] = 'entry form with the Z-tape totals before they leave.';
    $lines[] = '';
    $lines[] = 'If the register is shut down for the season, set its terminal';
    $lines[] = 'status to inactive in POS admin to stop these alerts.';
    $lines[] = '';
}

$lines[] = str_repeat('=', 60);
$lines[] = 'Sent by check_open_shifts.php at ' . date('Y-m-d H:i:s T');

$body    = implode("\n", $lines);
$subject = '[POS] End-of-day alert - ' . date('M j');

$headers = [
    'From: ' . FROM_NAME . ' <' . FROM_EMAIL . '>',
    'Content-Type: text/plain; charset=utf-8',
    'X-Mailer: PHP/' . phpversion(),
];

if ($dryRun) {
    echo "[dry-run] Would send to: " . implode(', ', RECIPIENTS) . "\n";
    echo "[dry-run] Subject: $subject\n";
    echo "[dry-run] ----- BODY -----\n";
    echo $body . "\n";
    echo "[dry-run] ----- END -----\n";
    exit(0);
}

$exitCode = 0;
foreach (RECIPIENTS as $to) {
    if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
        fwrite(STDERR, "Failed to send alert to $to\n");
        $exitCode = 1;
    }
}

exit($exitCode);
