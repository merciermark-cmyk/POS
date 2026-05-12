<?php
$pageTitle = 'Cash Spot Check';
ob_start();
?>

<h3>Cash Spot Check</h3>

<form class="row g-2 mb-4" method="get" action="<?= baseUrl('reports/cash-spot-check') ?>">
    <div class="col-auto">
        <select name="terminal_id" class="form-select" required>
            <option value="">Select Terminal</option>
            <?php foreach ($terminals as $t): ?>
                <option value="<?= $t['id'] ?>" <?= ($terminalId ?? null) == $t['id'] ? 'selected' : '' ?>>
                    <?= e($t['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <input type="time" name="cutoff_time" class="form-control" value="<?= e($cutoffTime) ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-primary">Check</button>
    </div>
</form>

<?php if ($message): ?>
    <div class="alert alert-warning"><?= e($message) ?></div>
<?php endif; ?>

<?php if ($shift && $breakdown): ?>
<div class="row">
    <div class="col-md-6 col-lg-5">
        <div class="card mb-3">
            <div class="card-header">
                <strong>Shift Info</strong>
            </div>
            <div class="card-body p-3">
                <small class="text-muted">
                    Terminal: <?= e($shift['terminal_name']) ?> &middot;
                    Opened by: <?= e($shift['username']) ?> &middot;
                    Opened at: <?= date('g:i A', strtotime($shift['opened_at'])) ?> &middot;
                    Cutoff: <?= date('g:i A', strtotime($cutoffTime)) ?>
                </small>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <strong>Expected Cash Breakdown</strong>
            </div>
            <table class="table table-striped mb-0">
                <tbody>
                    <tr>
                        <td>Opening Float</td>
                        <td class="text-end">$<?= number_format($breakdown['opening_float'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>+ Cash Sales</td>
                        <td class="text-end">$<?= number_format($breakdown['cash_sales'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>&minus; Cash Refunds</td>
                        <td class="text-end">($<?= number_format($breakdown['cash_refunds'], 2) ?>)</td>
                    </tr>
                    <tr>
                        <td>&minus; Standalone Cash Refunds</td>
                        <td class="text-end">($<?= number_format($breakdown['standalone_cash_refunds'], 2) ?>)</td>
                    </tr>
                    <tr>
                        <td>&minus; Petty Cash Out</td>
                        <td class="text-end">($<?= number_format($breakdown['petty_cash'], 2) ?>)</td>
                    </tr>
                    <?php if (($breakdown['gift_card_cash'] ?? 0) > 0): ?>
                    <tr>
                        <td>+ Gift Card Cash Sales</td>
                        <td class="text-end">$<?= number_format($breakdown['gift_card_cash'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold table-dark">
                        <td>Expected Cash</td>
                        <td class="text-end">$<?= number_format($breakdown['expected_cash'], 2) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <strong>Spot Check</strong>
            </div>
            <div class="card-body">
                <div class="input-group mb-3">
                    <span class="input-group-text">$</span>
                    <input type="number" id="countedAmount" class="form-control" step="0.01" min="0" placeholder="Counted amount">
                </div>
                <div id="overShortResult" class="text-center" style="display:none">
                    <span class="fs-5">Over/Short: </span>
                    <span id="overShortBadge" class="badge fs-5"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('countedAmount').addEventListener('input', function() {
    var counted = parseFloat(this.value);
    var result = document.getElementById('overShortResult');
    var badge = document.getElementById('overShortBadge');
    if (isNaN(counted)) { result.style.display = 'none'; return; }

    var expected = <?= $breakdown['expected_cash'] ?>;
    var diff = (counted - expected).toFixed(2);
    result.style.display = '';

    if (parseFloat(diff) === 0) {
        badge.className = 'badge fs-5 bg-success';
        badge.textContent = '$0.00';
    } else {
        badge.className = 'badge fs-5 bg-danger';
        badge.textContent = (diff > 0 ? '+$' : '-$') + Math.abs(diff).toFixed(2);
    }
});
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
