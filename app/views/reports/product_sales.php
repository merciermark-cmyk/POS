<?php
$pageTitle = 'Product Sales';
ob_start();
?>

<h3>Product Sales Report</h3>

<form class="row g-2 mb-4" method="get" action="<?= baseUrl('reports/product-sales') ?>">
    <div class="col-auto">
        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
    </div>
    <div class="col-auto">
        <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
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
        <button class="btn btn-primary">Filter</button>
    </div>
</form>

<table class="table table-striped">
    <thead>
        <tr>
            <th>Product</th><th>Code</th>
            <th class="text-center">Qty Sold</th>
            <th class="text-end">Revenue</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($sales)): ?>
            <tr><td colspan="4" class="text-muted text-center">No sales data for this period.</td></tr>
        <?php endif; ?>
        <?php $totalQty = 0; $totalRev = 0; ?>
        <?php foreach ($sales as $s): ?>
            <tr>
                <td><?= e($s['product_name']) ?></td>
                <td class="text-muted"><?= e($s['product_code']) ?></td>
                <td class="text-center"><?= $s['total_qty'] ?></td>
                <td class="text-end">$<?= number_format($s['total_revenue'], 2) ?></td>
            </tr>
            <?php $totalQty += $s['total_qty']; $totalRev += $s['total_revenue']; ?>
        <?php endforeach; ?>
    </tbody>
    <?php if ($sales): ?>
    <tfoot>
        <tr class="fw-bold">
            <td colspan="2">Total</td>
            <td class="text-center"><?= $totalQty ?></td>
            <td class="text-end">$<?= number_format($totalRev, 2) ?></td>
        </tr>
    </tfoot>
    <?php endif; ?>
</table>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
