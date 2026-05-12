<?php
$pageTitle = 'Hourly Sales Report';
ob_start();

// Find peak hour
$peakHour = null;
$peakTotal = 0;
$totalTxns = 0;
$totalSales = 0;
foreach ($hourlyData as $row) {
    $totalTxns += (int)$row['count'];
    $totalSales += (float)$row['total'];
    if ((float)$row['total'] > $peakTotal) {
        $peakTotal = (float)$row['total'];
        $peakHour = (int)$row['hour'];
    }
}
?>

<h3>Hourly Sales Report</h3>

<form class="row g-2 mb-4" method="get" action="<?= baseUrl('reports/hourly-sales') ?>">
    <div class="col-auto">
        <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
    </div>
    <div class="col-auto d-flex align-items-center">to</div>
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
        <button class="btn btn-primary">View</button>
    </div>
</form>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body text-center">
                <small>Transactions</small>
                <div class="fs-2"><?= $totalTxns ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body text-center">
                <small>Total Sales</small>
                <div class="fs-2">$<?= number_format($totalSales, 2) ?></div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($hourlyData)): ?>
    <p class="text-muted">No sales found for this period.</p>
<?php else: ?>
<table class="table table-striped" style="max-width:700px">
    <thead>
        <tr>
            <th>Hour</th>
            <th class="text-end">Transactions</th>
            <th class="text-end">Sales Total</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($hourlyData as $row):
            $h = (int)$row['hour'];
            $isPeak = ($h === $peakHour);
            $from = date('g:i A', mktime($h, 0));
            $to   = date('g:i A', mktime($h + 1, 0));
        ?>
        <tr<?= $isPeak ? ' class="table-warning fw-bold"' : '' ?>>
            <td><?= $from ?> &ndash; <?= $to ?><?= $isPeak ? ' &#9733;' : '' ?></td>
            <td class="text-end"><?= (int)$row['count'] ?></td>
            <td class="text-end">$<?= number_format($row['total'], 2) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
