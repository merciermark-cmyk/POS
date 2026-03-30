<?php
$pageTitle = 'Transactions';
ob_start();
?>

<h3>Transactions</h3>

<form class="row g-2 mb-3" method="get" action="<?= baseUrl('transactions') ?>">
    <div class="col-auto">
        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
    </div>
    <div class="col-auto">
        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
    </div>
    <div class="col-auto">
        <button class="btn btn-primary">Filter</button>
    </div>
</form>

<table class="table table-striped">
    <thead>
        <tr>
            <th>#</th><th>Date/Time</th><th>Cashier</th><th>Shift</th>
            <th class="text-end">Subtotal</th><th class="text-end">Tax</th>
            <th class="text-end">Total</th><th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($transactions)): ?>
            <tr><td colspan="8" class="text-muted text-center">No transactions found.</td></tr>
        <?php endif; ?>
        <?php foreach ($transactions as $t): ?>
            <?php
                $rowClass = match ($t['status']) {
                    'voided'         => 'table-danger',
                    'refunded'       => 'table-secondary',
                    'partial_refund' => 'table-warning',
                    default          => '',
                };
                $badgeClass = match ($t['status']) {
                    'completed'      => 'bg-success',
                    'voided'         => 'bg-danger',
                    'refunded'       => 'bg-secondary',
                    'partial_refund' => 'bg-warning text-dark',
                    default          => 'bg-dark',
                };
                $statusLabel = match ($t['status']) {
                    'partial_refund' => 'Partial Refund',
                    default          => ucfirst($t['status']),
                };
            ?>
            <tr class="<?= $rowClass ?>">
                <td><a href="<?= baseUrl('transactions/view/' . $t['id']) ?>"><?= $t['id'] ?></a></td>
                <td><?= date('M j g:i A', strtotime($t['created_at'])) ?></td>
                <td><?= e($t['username']) ?></td>
                <td><?= $t['shift_number'] ?></td>
                <td class="text-end">$<?= number_format($t['subtotal'], 2) ?></td>
                <td class="text-end">$<?= number_format($t['gst_amount'] + $t['pst_amount'], 2) ?></td>
                <td class="text-end fw-bold">$<?= number_format($t['total'], 2) ?></td>
                <td>
                    <span class="badge <?= $badgeClass ?>">
                        <?= e($statusLabel) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
