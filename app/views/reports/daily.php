<?php
$pageTitle = 'Daily Sales Report';
ob_start();
?>

<h3>Daily Sales Report</h3>

<form class="row g-2 mb-4" method="get" action="<?= baseUrl('reports/daily') ?>">
    <div class="col-auto">
        <input type="date" name="date" class="form-control" value="<?= e($date) ?>">
    </div>
    <?php if (!empty($terminals)): ?>
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
    <?php endif; ?>
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

<?php if (PS_DB_NAME): ?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body text-center">
                <small>Web Sales (<?= $webSales['count'] ?>)</small>
                <div class="fs-2">$<?= number_format($webSales['total'], 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-dark text-white">
            <div class="card-body text-center">
                <small>Combined Total</small>
                <div class="fs-2">$<?= number_format($summary['total'] + $webSales['total'], 2) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($webSales['orders'])): ?>
<h5>Web Orders Shipped</h5>
<table class="table" style="max-width:400px">
    <?php foreach ($webSales['orders'] as $wo): ?>
        <tr>
            <td><?= e($wo['reference']) ?></td>
            <td class="text-end">$<?= number_format($wo['total'], 2) ?></td>
        </tr>
    <?php endforeach; ?>
    <tr class="fw-bold border-top">
        <td>Total (<?= $webSales['count'] ?> order<?= $webSales['count'] !== 1 ? 's' : '' ?>)</td>
        <td class="text-end">$<?= number_format($webSales['total'], 2) ?></td>
    </tr>
</table>
<?php endif; ?>
<?php endif; ?>

<h5>Category Breakdown</h5>
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
        <?php if (!empty($webSales['total'])): ?>
        <tr class="table-info">
            <td>Mail Orders</td>
            <td class="text-end"><?= $webSales['count'] ?></td>
            <td class="text-end text-muted">—</td>
            <td class="text-end text-muted">—</td>
            <td class="text-end text-muted">—</td>
            <td class="text-end">$<?= number_format($webSales['total'], 2) ?></td>
        </tr>
        <?php endif; ?>
    </tbody>
    <tfoot>
        <tr class="fw-bold">
            <td>Total (POS)</td>
            <td class="text-end"><?= $catTotals['qty'] ?></td>
            <td class="text-end">$<?= number_format($catTotals['subtotal'], 2) ?></td>
            <td class="text-end">$<?= number_format($catTotals['gst'], 2) ?></td>
            <td class="text-end">$<?= number_format($catTotals['pst'], 2) ?></td>
            <td class="text-end">$<?= number_format($catTotals['total'], 2) ?></td>
        </tr>
        <?php if (!empty($webSales['total'])): ?>
        <tr class="fw-bold table-dark">
            <td>Grand Total</td>
            <td class="text-end"><?= $catTotals['qty'] + $webSales['count'] ?></td>
            <td class="text-end" colspan="3"></td>
            <td class="text-end">$<?= number_format($catTotals['total'] + $webSales['total'], 2) ?></td>
        </tr>
        <?php endif; ?>
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

<?php if (!empty($giftCardSales) && $giftCardSales['count'] > 0): ?>
<h5>Gift Card Sales <small class="text-muted">(not included in sales totals)</small></h5>
<table class="table" style="max-width:400px">
    <tr>
        <td>Total (<?= (int)$giftCardSales['count'] ?> sale<?= $giftCardSales['count'] != 1 ? 's' : '' ?>)</td>
        <td class="text-end fw-bold">$<?= number_format($giftCardSales['total'], 2) ?></td>
    </tr>
    <?php if ($giftCardSales['card_total'] > 0): ?>
    <tr class="text-muted">
        <td>Paid by Card</td>
        <td class="text-end">$<?= number_format($giftCardSales['card_total'], 2) ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($giftCardSales['cash_total'] > 0): ?>
    <tr class="text-muted">
        <td>Paid by Cash</td>
        <td class="text-end">$<?= number_format($giftCardSales['cash_total'], 2) ?></td>
    </tr>
    <?php endif; ?>
</table>
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
