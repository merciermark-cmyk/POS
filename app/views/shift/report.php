<?php
$pageTitle = 'Shift Report';
ob_start();
?>

<div class="container" style="max-width:700px; margin-top:40px">
    <?php if (!$shift): ?>
        <div class="alert alert-danger">Shift not found.</div>
    <?php else: ?>
        <div class="card shadow">
            <div class="card-body p-4">
                <h3 class="text-center mb-2">Shift Report</h3>
                <p class="text-center text-muted">
                    <?= date('M j, Y g:i A', strtotime($shift['opened_at'])) ?>
                    <?php if ($shift['closed_at']): ?>
                        — <?= date('g:i A', strtotime($shift['closed_at'])) ?>
                    <?php endif; ?>
                </p>

                <?php if ($result): ?>
                    <div class="alert <?= $result['over_short'] >= 0 ? 'alert-success' : 'alert-danger' ?> text-center fs-4">
                        <?php if ($result['over_short'] == 0): ?>
                            Drawer is balanced!
                        <?php elseif ($result['over_short'] > 0): ?>
                            Over by $<?= number_format($result['over_short'], 2) ?>
                        <?php else: ?>
                            Short by $<?= number_format(abs($result['over_short']), 2) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <table class="table">
                    <tr><td>Opening Float</td><td class="text-end">$<?= number_format($shift['opening_float'], 2) ?></td></tr>
                    <tr><td>Transactions</td><td class="text-end"><?= $summary['transaction_count'] ?></td></tr>
                    <tr><td>Voids</td><td class="text-end"><?= $summary['void_count'] ?></td></tr>
                    <tr><td>Refunds</td><td class="text-end"><?= $summary['refund_count'] ?? 0 ?></td></tr>
                    <tr><td>Subtotal</td><td class="text-end">$<?= number_format($summary['subtotal'], 2) ?></td></tr>
                    <tr><td>GST Collected</td><td class="text-end">$<?= number_format($summary['gst'], 2) ?></td></tr>
                    <tr><td>PST Collected</td><td class="text-end">$<?= number_format($summary['pst'], 2) ?></td></tr>
                    <tr class="fw-bold"><td>Total Sales</td><td class="text-end">$<?= number_format($summary['total'], 2) ?></td></tr>
                    <?php if (($summary['refund_total'] ?? 0) > 0): ?>
                        <tr class="text-danger"><td>Refund Total</td><td class="text-end">-$<?= number_format($summary['refund_total'], 2) ?></td></tr>
                        <tr class="fw-bold"><td>Net Sales</td><td class="text-end">$<?= number_format($summary['total'] - $summary['refund_total'], 2) ?></td></tr>
                    <?php endif; ?>
                </table>

                <h5>Payment Breakdown</h5>
                <table class="table table-sm">
                    <?php foreach ($summary['payments'] as $p): ?>
                        <tr>
                            <td><?= e(ucfirst(str_replace('_', ' ', $p['method']))) ?></td>
                            <td class="text-end">$<?= number_format($p['total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!empty($summary['refund_payments'])): ?>
                        <tr><td colspan="2" class="text-muted small pt-2">Refund Payments</td></tr>
                        <?php foreach ($summary['refund_payments'] as $rp): ?>
                            <tr class="text-danger">
                                <td><?= e(ucfirst(str_replace('_', ' ', $rp['method']))) ?> (refund)</td>
                                <td class="text-end">-$<?= number_format($rp['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>

                <?php if ($shift['closing_cash'] !== null): ?>
                    <table class="table table-sm">
                        <tr><td>Expected Cash</td><td class="text-end">$<?= number_format($shift['expected_cash'], 2) ?></td></tr>
                        <tr><td>Actual Cash</td><td class="text-end">$<?= number_format($shift['closing_cash'], 2) ?></td></tr>
                        <tr class="<?= $shift['over_short'] >= 0 ? 'table-success' : 'table-danger' ?>">
                            <td>Over/Short</td>
                            <td class="text-end">$<?= number_format($shift['over_short'], 2) ?></td>
                        </tr>
                    </table>
                <?php endif; ?>

                <?php if ($transactions): ?>
                    <h5 class="mt-4">Transactions</h5>
                    <table class="table table-sm">
                        <thead><tr><th>#</th><th>Time</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><a href="<?= baseUrl('transactions/view/' . $t['id']) ?>"><?= $t['id'] ?></a></td>
                                    <td><?= date('g:i A', strtotime($t['created_at'])) ?></td>
                                    <td>
                                        <?php
                                        $bc = match ($t['status']) {
                                            'completed'      => 'bg-success',
                                            'voided'         => 'bg-danger',
                                            'refunded'       => 'bg-secondary',
                                            'partial_refund' => 'bg-warning text-dark',
                                            default          => 'bg-dark',
                                        };
                                        $sl = $t['status'] === 'partial_refund' ? 'Partial Refund' : ucfirst($t['status']);
                                        ?>
                                        <span class="badge <?= $bc ?>">
                                            <?= e($sl) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">$<?= number_format($t['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <div class="mt-4 d-flex gap-2 justify-content-center">
                    <a href="<?= baseUrl('login') ?>" class="btn btn-primary">Log Out</a>
                    <a href="<?= baseUrl('sale') ?>" class="btn btn-outline-secondary">New Shift</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
