<?php
$pageTitle = 'Shift History';
ob_start();
?>

<h3>Shift History</h3>
<table class="table table-striped">
    <thead>
        <tr>
            <th>#</th><th>Cashier</th><th>Opened</th><th>Closed</th>
            <th class="text-end">Sales</th><th class="text-center">Txns</th>
            <th class="text-end">Over/Short</th><th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($shifts as $s): ?>
            <tr>
                <td><a href="<?= baseUrl('shift/report/' . $s['id']) ?>"><?= $s['id'] ?></a></td>
                <td><?= e($s['username']) ?></td>
                <td><?= date('M j g:i A', strtotime($s['opened_at'])) ?></td>
                <td><?= $s['closed_at'] ? date('g:i A', strtotime($s['closed_at'])) : '—' ?></td>
                <td class="text-end">$<?= number_format($s['total_sales'], 2) ?></td>
                <td class="text-center"><?= $s['transaction_count'] ?></td>
                <td class="text-end">
                    <?php if ($s['over_short'] !== null): ?>
                        <span class="<?= $s['over_short'] >= 0 ? 'text-success' : 'text-danger' ?>">
                            $<?= number_format($s['over_short'], 2) ?>
                        </span>
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
