<?php
$pageTitle = 'Monthly Sales Report';
$monthLabel = date('F Y', mktime(0, 0, 0, $month, 1, $year));
ob_start();
?>

<h3>Monthly Sales Report</h3>

<form class="row g-2 mb-4" method="get" action="<?= baseUrl('reports/monthly') ?>">
    <div class="col-auto">
        <select name="month" class="form-select">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="year" class="form-select">
            <?php for ($y = (int)date('Y'); $y >= 2025; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary">View</button>
    </div>
</form>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body text-center">
                <small>Transactions</small>
                <div class="fs-2"><?= $summary['count'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body text-center">
                <small>Total Sales</small>
                <div class="fs-2">$<?= number_format($summary['total'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body text-center">
                <small>GST Collected</small>
                <div class="fs-2">$<?= number_format($summary['gst'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body text-center">
                <small>PST Collected</small>
                <div class="fs-2">$<?= number_format($summary['pst'], 2) ?></div>
            </div>
        </div>
    </div>
</div>

<h5>Category Breakdown — <?= e($monthLabel) ?></h5>
<table class="table table-striped" style="max-width:700px">
    <thead>
        <tr>
            <th>Group</th>
            <th class="text-end">Qty</th>
            <th class="text-end">Subtotal</th>
            <th class="text-end">GST</th>
            <th class="text-end">PST</th>
            <th class="text-end">Total</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $catTotals = ['qty' => 0, 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0];
        foreach ($categoryBreakdown as $group => $vals):
            $catTotals['qty']      += $vals['qty'];
            $catTotals['subtotal'] += $vals['subtotal'];
            $catTotals['gst']      += $vals['gst'];
            $catTotals['pst']      += $vals['pst'];
            $catTotals['total']    += $vals['total'];
        ?>
        <tr>
            <td><?= e($group) ?></td>
            <td class="text-end"><?= $vals['qty'] ?></td>
            <td class="text-end">$<?= number_format($vals['subtotal'], 2) ?></td>
            <td class="text-end">$<?= number_format($vals['gst'], 2) ?></td>
            <td class="text-end">$<?= number_format($vals['pst'], 2) ?></td>
            <td class="text-end">$<?= number_format($vals['total'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="fw-bold">
            <td>Total</td>
            <td class="text-end"><?= $catTotals['qty'] ?></td>
            <td class="text-end">$<?= number_format($catTotals['subtotal'], 2) ?></td>
            <td class="text-end">$<?= number_format($catTotals['gst'], 2) ?></td>
            <td class="text-end">$<?= number_format($catTotals['pst'], 2) ?></td>
            <td class="text-end">$<?= number_format($catTotals['total'], 2) ?></td>
        </tr>
    </tfoot>
</table>

<h5>Payment Breakdown</h5>
<table class="table" style="max-width:400px">
    <?php if (empty($paymentBreakdown)): ?>
        <tr><td class="text-muted">No payments recorded.</td></tr>
    <?php endif; ?>
    <?php foreach ($paymentBreakdown as $p): ?>
        <tr>
            <td><?= e(ucfirst(str_replace('_', ' ', $p['method']))) ?></td>
            <td class="text-end">$<?= number_format($p['total'], 2) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
