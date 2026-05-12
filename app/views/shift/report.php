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
                <?php if (!empty($terminalName)): ?>
                    <p class="text-center"><span class="badge bg-primary"><?= e($terminalName) ?></span></p>
                <?php endif; ?>
                <p class="text-center text-muted">
                    <?= date('M j, Y g:i A', strtotime($shift['opened_at'])) ?>
                    <?php if ($shift['closed_at']): ?>
                        — <?= date('g:i A', strtotime($shift['closed_at'])) ?>
                    <?php endif; ?>
                </p>

                <?php if ($result): ?>
                    <div class="alert <?= $result['over_short'] >= 0 ? 'alert-success' : 'alert-danger' ?> text-center fs-5 mb-2">
                        <strong>Cash:</strong>
                        <?php if ($result['over_short'] == 0): ?>
                            Balanced
                        <?php elseif ($result['over_short'] > 0): ?>
                            Over $<?= number_format($result['over_short'], 2) ?>
                        <?php else: ?>
                            Short $<?= number_format(abs($result['over_short']), 2) ?>
                        <?php endif; ?>
                    </div>
                    <?php if ($result['closing_card'] !== null): ?>
                        <div class="alert <?= $result['card_over_short'] >= 0 ? 'alert-success' : 'alert-danger' ?> text-center fs-5 mb-2">
                            <strong>Card:</strong>
                            <?php if ($result['card_over_short'] == 0): ?>
                                Balanced
                            <?php elseif ($result['card_over_short'] > 0): ?>
                                Over $<?= number_format($result['card_over_short'], 2) ?>
                            <?php else: ?>
                                Short $<?= number_format(abs($result['card_over_short']), 2) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <table class="table">
                    <tr><td>Opening Float</td><td class="text-end">$<?= number_format($shift['opening_float'], 2) ?></td></tr>
                    <tr><td>Transactions</td><td class="text-end"><?= $summary['transaction_count'] ?></td></tr>
                    <tr><td>Voids</td><td class="text-end"><?= $summary['void_count'] ?></td></tr>
                    <tr><td>Refunds</td><td class="text-end"><?= $summary['refund_count'] ?? 0 ?></td></tr>
                    <tr><td>Subtotal</td><td class="text-end">$<?= number_format($summary['subtotal'], 2) ?></td></tr>
                    <tr><td>GST Collected</td><td class="text-end">$<?= number_format($summary['gst'], 2) ?></td></tr>
                    <tr><td>PST Collected</td><td class="text-end">$<?= number_format($summary['pst'], 2) ?></td></tr>
                    <tr class="fw-bold"><td>Total Sales (POS)</td><td class="text-end">$<?= number_format($summary['total'], 2) ?></td></tr>
                    <?php if (!empty($webSales['total'])): ?>
                    <tr class="table-info"><td>Mail Orders (<?= $webSales['count'] ?>)</td><td class="text-end">$<?= number_format($webSales['total'], 2) ?></td></tr>
                    <tr class="fw-bold table-dark"><td>Grand Total</td><td class="text-end">$<?= number_format($summary['total'] + $webSales['total'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if (($summary['refund_total'] ?? 0) > 0): ?>
                        <tr class="text-danger"><td>Refund Total</td><td class="text-end">-$<?= number_format($summary['refund_total'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if (($summary['standalone_refund_count'] ?? 0) > 0): ?>
                        <tr class="text-danger"><td>Standalone Refunds (<?= $summary['standalone_refund_count'] ?>)</td><td class="text-end">-$<?= number_format($summary['standalone_refund_total'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if (($summary['petty_cash_count'] ?? 0) > 0): ?>
                        <tr class="text-warning"><td>Petty Cash (<?= $summary['petty_cash_count'] ?>)</td><td class="text-end">-$<?= number_format($summary['petty_cash_total'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php if (($summary['gift_card_sales_count'] ?? 0) > 0): ?>
                        <tr class="text-purple"><td>Gift Card Sales (<?= $summary['gift_card_sales_count'] ?>)</td><td class="text-end">$<?= number_format($summary['gift_card_sales_total'], 2) ?></td></tr>
                    <?php endif; ?>
                    <?php
                        $totalRefunds = ($summary['refund_total'] ?? 0) + ($summary['standalone_refund_total'] ?? 0);
                        if ($totalRefunds > 0):
                    ?>
                        <tr class="fw-bold"><td>Net Sales</td><td class="text-end">$<?= number_format($summary['total'] - $totalRefunds, 2) ?></td></tr>
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
                    <?php if (!empty($summary['standalone_refund_payments'])): ?>
                        <tr><td colspan="2" class="text-muted small pt-2">Standalone Refund Payments</td></tr>
                        <?php foreach ($summary['standalone_refund_payments'] as $sp): ?>
                            <tr class="text-danger">
                                <td><?= e(ucfirst($sp['method'])) ?> (standalone refund)</td>
                                <td class="text-end">-$<?= number_format($sp['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($summary['petty_cash_entries'])): ?>
                        <tr><td colspan="2" class="text-muted small pt-2">Petty Cash Expenditures</td></tr>
                        <?php foreach ($summary['petty_cash_entries'] as $pc): ?>
                            <tr class="text-warning">
                                <td><?= e($pc['description']) ?></td>
                                <td class="text-end">-$<?= number_format($pc['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($summary['gift_card_sales_entries'])): ?>
                        <tr><td colspan="2" class="text-muted small pt-2">Gift Card Sales (not in POS sales)</td></tr>
                        <?php foreach ($summary['gift_card_sales_entries'] as $gc): ?>
                            <tr class="text-purple">
                                <td>
                                    GC <?= ucfirst($gc['payment_method']) ?>
                                    <?php if ($gc['notes']): ?><small class="text-muted">— <?= e($gc['notes']) ?></small><?php endif; ?>
                                </td>
                                <td class="text-end">$<?= number_format($gc['amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>

                <?php if (!empty($webSales['orders'])): ?>
                    <div class="card bg-info bg-opacity-10 border-info mb-3">
                        <div class="card-body py-2 px-3">
                            <h5 class="mb-2 text-info">Web Sales (Shipped Today)</h5>
                            <table class="table table-sm mb-1">
                                <?php foreach ($webSales['orders'] as $wo): ?>
                                    <tr>
                                        <td><?= e($wo['reference']) ?></td>
                                        <td class="text-end">$<?= number_format($wo['total'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="fw-bold border-top">
                                    <td>Total Web Sales (<?= $webSales['count'] ?> order<?= $webSales['count'] !== 1 ? 's' : '' ?>)</td>
                                    <td class="text-end">$<?= number_format($webSales['total'], 2) ?></td>
                                </tr>
                            </table>
                            <div class="fw-bold text-center fs-5 mt-2">
                                Combined Daily Total: $<?= number_format($summary['total'] + $webSales['total'], 2) ?>
                            </div>
                        </div>
                    </div>
                <?php elseif (PS_DB_NAME): ?>
                    <p class="text-muted small">No web orders shipped today.</p>
                <?php endif; ?>

                <?php if ($shift['closing_cash'] !== null): ?>
                    <h5>Cash Reconciliation</h5>
                    <table class="table table-sm">
                        <tr><td>Expected Cash</td><td class="text-end">$<?= number_format($shift['expected_cash'], 2) ?></td></tr>
                        <tr><td>Actual Cash</td><td class="text-end">$<?= number_format($shift['closing_cash'], 2) ?></td></tr>
                        <tr class="<?= $shift['over_short'] >= 0 ? 'table-success' : 'table-danger' ?>">
                            <td>Over/Short</td>
                            <td class="text-end">$<?= number_format($shift['over_short'], 2) ?></td>
                        </tr>
                        <?php if ($shift['cash_deposit'] !== null): ?>
                        <tr class="table-info">
                            <td class="fw-bold">Deposit Envelope</td>
                            <td class="text-end fw-bold">$<?= number_format($shift['cash_deposit'], 2) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <?php
                        $os = (float)($shift['over_short'] ?? 0);
                        $tips = (float)($shift['closing_tips'] ?? 0);
                        if (($_SESSION['pos_user_role'] ?? '') === 'manager'
                            && $os < 0 && $tips > 0
                            && abs($os + $tips) < 5):
                    ?>
                        <div class="alert alert-warning mb-3">
                            <strong>Cash shortage ($<?= number_format(abs($os), 2) ?>) closely matches card tips ($<?= number_format($tips, 2) ?>)</strong>
                            <br><small class="text-muted">Difference: $<?= number_format(abs($os + $tips), 2) ?></small>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($shift['closing_card'] !== null): ?>
                    <h5>Card Reconciliation</h5>
                    <table class="table table-sm">
                        <tr><td>Expected Card (POS<?php if (($summary['gift_card_sales_card_total'] ?? 0) > 0): ?> + GC<?php endif; ?>)</td><td class="text-end">$<?= number_format($shift['expected_card'], 2) ?></td></tr>
                        <?php if ($shift['closing_tips']): ?>
                        <tr><td>Tips from Terminal</td><td class="text-end">$<?= number_format($shift['closing_tips'], 2) ?></td></tr>
                        <tr class="text-muted"><td>Expected Batch Total</td><td class="text-end">$<?= number_format($shift['expected_card'] + $shift['closing_tips'], 2) ?></td></tr>
                        <?php endif; ?>
                        <tr><td>Terminal Batch Total</td><td class="text-end">$<?= number_format($shift['closing_card'], 2) ?></td></tr>
                        <?php
                            $cardOS = (float)$shift['card_over_short'];
                            $cardOSClass = $cardOS == 0 ? 'table-success' : ($cardOS > 0 ? 'table-warning' : 'table-danger');
                        ?>
                        <tr class="<?= $cardOSClass ?>">
                            <td>Over/Short</td>
                            <td class="text-end"><?= $cardOS >= 0 ? '+' : '' ?>$<?= number_format($cardOS, 2) ?></td>
                        </tr>
                        <?php if ($cardOS != 0 && ($summary['gift_card_sales_card_total'] ?? 0) > 0): ?>
                            <?php $gcCardAmt = (float)$summary['gift_card_sales_card_total']; ?>
                            <tr class="table-info">
                                <td>Gift Card Sales (<?= $summary['gift_card_sales_count'] ?> card<?= $summary['gift_card_sales_count'] > 1 ? 's' : '' ?>)</td>
                                <td class="text-end">$<?= number_format($gcCardAmt, 2) ?></td>
                            </tr>
                            <?php $adjusted = round($cardOS + $gcCardAmt, 2); ?>
                            <tr class="<?= $adjusted == 0 ? 'table-success' : ($adjusted > 0 ? 'table-warning' : 'table-danger') ?>">
                                <td>Adjusted Over/Short</td>
                                <td class="text-end"><?= $adjusted >= 0 ? '+' : '' ?>$<?= number_format($adjusted, 2) ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                <?php elseif ($shift['closing_tips'] !== null): ?>
                    <div class="alert alert-info text-center mb-3">
                        <strong>Tips:</strong> $<?= number_format($shift['closing_tips'], 2) ?>
                    </div>
                <?php endif; ?>

                <?php if ($shift['closing_cash'] !== null):
                    // Calculate net cash/card sales from payment data
                    $cashSales = 0;
                    $cardSales = 0;
                    $otherSales = 0;
                    foreach ($summary['payments'] as $p) {
                        if ($p['method'] === 'cash') $cashSales += (float)$p['total'];
                        elseif (in_array($p['method'], ['card', 'moneris'])) $cardSales += (float)$p['total'];
                        else $otherSales += (float)$p['total'];
                    }
                    // Subtract refunds by method
                    foreach ($summary['refund_payments'] ?? [] as $rp) {
                        if ($rp['method'] === 'cash') $cashSales -= (float)$rp['total'];
                        elseif (in_array($rp['method'], ['card', 'moneris'])) $cardSales -= (float)$rp['total'];
                        else $otherSales -= (float)$rp['total'];
                    }
                    foreach ($summary['standalone_refund_payments'] ?? [] as $sp) {
                        if ($sp['method'] === 'cash') $cashSales -= (float)$sp['total'];
                        elseif (in_array($sp['method'], ['card', 'moneris'])) $cardSales -= (float)$sp['total'];
                        else $otherSales -= (float)$sp['total'];
                    }
                    $cashSales = round($cashSales, 2);
                    $cardSales = round($cardSales, 2);

                    $cashCollected = round($shift['closing_cash'] - $shift['opening_float'], 2);
                    $cashDiff      = round($cashCollected - $cashSales, 2);
                    $cashClass     = $cashDiff == 0 ? 'success' : ($cashDiff > 0 ? 'warning' : 'danger');

                    $hasCardBatch  = $shift['closing_card'] !== null;
                    $cardCollected = $hasCardBatch ? round((float)$shift['closing_card'] - ($shift['closing_tips'] ?? 0), 2) : $cardSales;
                    $cardDiff      = round($cardCollected - $cardSales, 2);
                    $cardClass     = $cardDiff == 0 ? 'success' : ($cardDiff > 0 ? 'warning' : 'danger');

                    $totalSales     = round($cashSales + $cardSales, 2);
                    $totalCollected = round($cashCollected + $cardCollected, 2);
                    $totalDiff      = round($totalCollected - $totalSales, 2);
                    $totalClass     = $totalDiff == 0 ? 'success' : ($totalDiff > 0 ? 'warning' : 'danger');
                ?>
                    <div class="card border-dark mb-3">
                        <div class="card-header bg-dark text-white fw-bold">
                            Sales vs Collections
                        </div>
                        <div class="card-body py-2">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr class="text-muted small">
                                        <th></th>
                                        <th class="text-end">POS Sales</th>
                                        <th class="text-end">Collected</th>
                                        <th class="text-end">Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="table-<?= $cashClass ?>">
                                        <td class="fw-bold">Cash</td>
                                        <td class="text-end">$<?= number_format($cashSales, 2) ?></td>
                                        <td class="text-end">$<?= number_format($cashCollected, 2) ?></td>
                                        <td class="text-end fw-bold"><?= $cashDiff >= 0 ? '+' : '' ?>$<?= number_format($cashDiff, 2) ?></td>
                                    </tr>
                                    <tr class="table-<?= $cardClass ?>">
                                        <td class="fw-bold">Card</td>
                                        <td class="text-end">$<?= number_format($cardSales, 2) ?></td>
                                        <td class="text-end">$<?= number_format($cardCollected, 2) ?></td>
                                        <td class="text-end fw-bold"><?= $cardDiff >= 0 ? '+' : '' ?>$<?= number_format($cardDiff, 2) ?></td>
                                    </tr>
                                    <?php if ($otherSales != 0): ?>
                                    <tr>
                                        <td class="fw-bold">Other <small class="text-muted">(gift card, etc.)</small></td>
                                        <td class="text-end">$<?= number_format($otherSales, 2) ?></td>
                                        <td class="text-end text-muted">—</td>
                                        <td class="text-end text-muted">—</td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr class="border-top fw-bold fs-5 table-<?= $totalClass ?>">
                                        <td>Total</td>
                                        <td class="text-end">$<?= number_format($totalSales + $otherSales, 2) ?></td>
                                        <td class="text-end">$<?= number_format($totalCollected, 2) ?></td>
                                        <td class="text-end"><?= $totalDiff >= 0 ? '+' : '' ?>$<?= number_format($totalDiff, 2) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            <?php if ($summary['petty_cash_total'] > 0): ?>
                            <p class="text-muted small mb-0 mt-1">
                                Note: $<?= number_format($summary['petty_cash_total'], 2) ?> petty cash removed from drawer (not reflected in cash collected).
                            </p>
                            <?php endif; ?>
                            <?php if (($summary['gift_card_sales_total'] ?? 0) > 0): ?>
                            <p class="text-muted small mb-0 mt-1">
                                Note: $<?= number_format($summary['gift_card_sales_total'], 2) ?> gift card sales included in expected totals
                                <?php if ($summary['gift_card_sales_card_total'] > 0): ?>(Card: $<?= number_format($summary['gift_card_sales_card_total'], 2) ?>)<?php endif; ?>
                                <?php if ($summary['gift_card_sales_cash_total'] > 0): ?>(Cash: $<?= number_format($summary['gift_card_sales_cash_total'], 2) ?>)<?php endif; ?>
                                — not in POS sales.
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($transactions): ?>
                    <h5 class="mt-4">Transactions</h5>
                    <table class="table table-sm">
                        <thead><tr><th>#</th><th>Sale #</th><th>Time</th><th>Status</th><th class="text-end">Cash</th><th class="text-end">Charge</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><a href="<?= baseUrl('transactions/view/' . $t['id']) ?>"><?= $t['id'] ?></a></td>
                                    <td><?= $t['daily_number'] ? $t['daily_number'] : '—' ?></td>
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
                                    <td class="text-end"><?= (float)$t['cash_amount'] > 0 ? '$' . number_format($t['cash_amount'], 2) : '' ?></td>
                                    <td class="text-end"><?= (float)$t['charge_amount'] > 0 ? '$' . number_format($t['charge_amount'], 2) : '' ?></td>
                                    <td class="text-end">$<?= number_format($t['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if (!empty($nonTrackedSales)): ?>
                    <h5 class="mt-4">Non-Tracked Product Sales</h5>
                    <p class="text-muted small">These products don't track inventory. Consider creating specific products and adjusting stock.</p>
                    <table class="table table-sm">
                        <thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Total</th></tr></thead>
                        <tbody>
                            <?php foreach ($nonTrackedSales as $nts): ?>
                                <tr class="table-warning">
                                    <td><?= e($nts['product_name']) ?></td>
                                    <td class="text-end"><?= (int)$nts['qty'] ?></td>
                                    <td class="text-end">$<?= number_format($nts['total'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if (!empty($result['negative_resets'])): ?>
                    <h5 class="mt-4">Negative Stock Corrections</h5>
                    <p class="text-muted small">These products had negative stock at the Shop and were reset to zero.</p>
                    <table class="table table-sm">
                        <thead><tr><th>Product</th><th class="text-end">Was</th><th class="text-end">Now</th></tr></thead>
                        <tbody>
                            <?php foreach ($result['negative_resets'] as $nr): ?>
                                <tr class="table-warning">
                                    <td><?= e($nr['name']) ?></td>
                                    <td class="text-end"><?= $nr['was'] ?></td>
                                    <td class="text-end">0</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if (!empty($shift['notes'])): ?>
                    <div class="alert alert-secondary mt-3">
                        <strong>Notes:</strong> <?= e($shift['notes']) ?>
                    </div>
                <?php endif; ?>

                <div class="mt-4 d-flex gap-2 justify-content-center flex-wrap">
                    <?php if (!empty($_SESSION['pos_shift_id'])): ?>
                        <a href="<?= baseUrl('sale') ?>" class="btn btn-success">Back to Register</a>
                    <?php endif; ?>
                    <a href="<?= baseUrl('shift/history') ?>" class="btn btn-outline-secondary">Shift History</a>
                    <?php if (($_SESSION['pos_user_role'] ?? '') === 'manager'): ?>
                        <a href="<?= baseUrl('shift/edit/' . $shift['id']) ?>" class="btn btn-outline-warning">Edit Shift</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
