<?php
$pageTitle = 'Shift History';
ob_start();
?>

<h3>Shift History</h3>

<?php if (!empty($terminals)): ?>
<form class="row g-2 mb-3" method="get" action="<?= baseUrl('shift/history') ?>">
    <div class="col-auto">
        <select name="terminal_id" class="form-select">
            <option value="">All Terminals</option>
            <?php foreach ($terminals as $t): ?>
                <option value="<?= $t['id'] ?>" <?= ($terminalId ?? null) == $t['id'] ? 'selected' : '' ?>>
                    <?= e($t['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary">Filter</button>
    </div>
</form>
<?php endif; ?>

<table class="table table-striped">
    <thead>
        <tr>
            <th>#</th><th>Opened By</th><th>Closed By</th><th>Terminal</th><th>Opened</th><th>Closed</th>
            <th class="text-end">Sales</th><th class="text-center">Txns</th>
            <th class="text-end">Cash Count</th><th class="text-end">Deposit</th>
            <th class="text-end">Over/Short</th><th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($shifts as $s): ?>
            <tr>
                <td><a href="<?= baseUrl('shift/report/' . $s['id']) ?>"><?= $s['id'] ?></a></td>
                <td><?= e($s['username']) ?></td>
                <td><?= e($s['closed_by_name'] ?? '—') ?></td>
                <td><?= e($s['terminal_name'] ?? '—') ?></td>
                <td><?= date('M j g:i A', strtotime($s['opened_at'])) ?></td>
                <td><?= $s['closed_at'] ? date('g:i A', strtotime($s['closed_at'])) : '—' ?></td>
                <td class="text-end">$<?= number_format($s['total_sales'], 2) ?></td>
                <td class="text-center"><?= $s['transaction_count'] ?></td>
                <td class="text-end"><?= $s['closing_cash'] !== null ? '$' . number_format($s['closing_cash'], 2) : '—' ?></td>
                <td class="text-end"><?= $s['cash_deposit'] !== null ? '$' . number_format($s['cash_deposit'], 2) : '—' ?></td>
                <td class="text-end">
                    <?php if ($s['over_short'] !== null): ?>
                        <span class="<?= $s['over_short'] >= 0 ? 'text-success' : 'text-danger' ?>">
                            $<?= number_format($s['over_short'], 2) ?>
                        </span>
                        <?php if ((int)$s['terminal_id'] === 3): ?>
                            <small class="text-muted" title="R3 reconciliation: Moneris batch vs Z-tape card">(card)</small>
                        <?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-<?= $s['status'] === 'open' ? 'success' : 'secondary' ?>">
                        <?= e($s['status']) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
