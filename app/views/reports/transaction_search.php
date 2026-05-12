<?php
$pageTitle = 'Transaction Search';
ob_start();
?>

<h3>Transaction Search</h3>
<p class="text-muted">Find transactions or line items matching a specific dollar amount (e.g. a shift discrepancy).</p>

<form class="row g-2 mb-4" method="get" action="<?= baseUrl('reports/transaction-search') ?>">
    <div class="col-auto">
        <div class="input-group">
            <span class="input-group-text">$</span>
            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="Amount"
                   value="<?= e($amount ?: '') ?>" required>
        </div>
    </div>
    <div class="col-auto">
        <div class="input-group">
            <span class="input-group-text">&plusmn;$</span>
            <input type="number" step="0.01" min="0" name="tolerance" class="form-control" style="width:90px"
                   value="<?= e($tolerance) ?>">
        </div>
    </div>
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
        <button class="btn btn-primary">Search</button>
    </div>
</form>

<?php if ($results !== null): ?>
    <?php
    $txnCount  = count($results['transaction_matches']);
    $lineCount = count($results['line_item_matches']);
    ?>

    <div class="row g-3 mb-4">
        <div class="col-auto">
            <div class="card text-bg-primary">
                <div class="card-body py-2 px-3">
                    <small>Search Amount</small>
                    <div class="fw-bold">$<?= number_format($amount, 2) ?> &plusmn;$<?= number_format($tolerance, 2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-auto">
            <div class="card text-bg-<?= $txnCount ? 'success' : 'secondary' ?>">
                <div class="card-body py-2 px-3">
                    <small>Transaction Matches</small>
                    <div class="fw-bold"><?= $txnCount ?></div>
                </div>
            </div>
        </div>
        <div class="col-auto">
            <div class="card text-bg-<?= $lineCount ? 'info' : 'secondary' ?>">
                <div class="card-body py-2 px-3">
                    <small>Line Item Matches</small>
                    <div class="fw-bold"><?= $lineCount ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction Matches -->
    <h5>Transaction Matches</h5>
    <div class="table-responsive mb-4">
    <table class="table table-striped table-sm">
        <thead>
            <tr>
                <th>Sale #</th><th>Date/Time</th><th>Cashier</th><th>Terminal</th>
                <th>Status</th><th class="text-end">Total</th><th>Payments</th>
                <th class="text-end">Diff</th><th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$txnCount): ?>
                <tr><td colspan="9" class="text-muted text-center">No transaction-level matches.</td></tr>
            <?php endif; ?>
            <?php foreach ($results['transaction_matches'] as $t): ?>
                <?php
                $rowClass = '';
                if ($t['status'] === 'voided') $rowClass = 'table-danger';
                elseif ($t['status'] === 'partial_refund') $rowClass = 'table-warning';
                elseif ($t['status'] === 'refunded') $rowClass = 'table-secondary';

                $payParts = [];
                foreach ($t['payments'] as $p) {
                    $payParts[] = ucfirst(str_replace('_', ' ', $p['method'])) . ' $' . number_format($p['amount'], 2);
                }

                $diff = $t['match_diff'];
                $diffLabel = $diff == 0 ? '<span class="badge bg-success">Exact</span>'
                    : ($diff > 0 ? '+$' . number_format($diff, 2) : '-$' . number_format(abs($diff), 2));

                $notes = [];
                if ($t['status'] === 'voided' && !empty($t['void_reason'])) {
                    $notes[] = 'Void: ' . $t['void_reason'];
                }
                if ($t['refund_total'] > 0) {
                    $notes[] = 'Refunded: $' . number_format($t['refund_total'], 2) . ' (' . $t['refund_count'] . ')';
                }
                ?>
                <tr class="<?= $rowClass ?>">
                    <td><a href="<?= baseUrl('transactions/view/' . $t['id']) ?>">#<?= $t['id'] ?></a></td>
                    <td><?= date('M j g:ia', strtotime($t['created_at'])) ?></td>
                    <td><?= e($t['username']) ?></td>
                    <td><?= e($t['terminal_name'] ?? '—') ?></td>
                    <td>
                        <?php
                        $badge = match ($t['status']) {
                            'completed'      => 'bg-success',
                            'voided'         => 'bg-danger',
                            'partial_refund' => 'bg-warning text-dark',
                            'refunded'       => 'bg-secondary',
                            default          => 'bg-light text-dark',
                        };
                        ?>
                        <span class="badge <?= $badge ?>"><?= e(ucfirst(str_replace('_', ' ', $t['status']))) ?></span>
                    </td>
                    <td class="text-end fw-bold">$<?= number_format($t['total'], 2) ?></td>
                    <td><small><?= implode(' / ', $payParts) ?></small></td>
                    <td class="text-end"><?= $diffLabel ?></td>
                    <td><small class="text-muted"><?= e(implode('; ', $notes)) ?></small></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Line Item Matches -->
    <h5>Line Item Matches</h5>
    <div class="table-responsive">
    <table class="table table-striped table-sm">
        <thead>
            <tr>
                <th>Txn #</th><th>Date/Time</th><th>Cashier</th><th>Status</th>
                <th>Product</th><th class="text-center">Qty</th>
                <th class="text-end">Item Total</th><th class="text-end">Txn Total</th>
                <th class="text-end">Diff</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$lineCount): ?>
                <tr><td colspan="9" class="text-muted text-center">No line-item matches.</td></tr>
            <?php endif; ?>
            <?php foreach ($results['line_item_matches'] as $li): ?>
                <?php
                $rowClass = '';
                if ($li['txn_status'] === 'voided') $rowClass = 'table-danger';
                elseif ($li['txn_status'] === 'partial_refund') $rowClass = 'table-warning';
                elseif ($li['txn_status'] === 'refunded') $rowClass = 'table-secondary';

                $diff = $li['match_diff'];
                $diffLabel = $diff == 0 ? '<span class="badge bg-success">Exact</span>'
                    : ($diff > 0 ? '+$' . number_format($diff, 2) : '-$' . number_format(abs($diff), 2));

                $badge = match ($li['txn_status']) {
                    'completed'      => 'bg-success',
                    'voided'         => 'bg-danger',
                    'partial_refund' => 'bg-warning text-dark',
                    'refunded'       => 'bg-secondary',
                    default          => 'bg-light text-dark',
                };
                ?>
                <tr class="<?= $rowClass ?>">
                    <td><a href="<?= baseUrl('transactions/view/' . $li['transaction_id']) ?>">#<?= $li['transaction_id'] ?></a></td>
                    <td><?= date('M j g:ia', strtotime($li['txn_date'])) ?></td>
                    <td><?= e($li['username']) ?></td>
                    <td><span class="badge <?= $badge ?>"><?= e(ucfirst(str_replace('_', ' ', $li['txn_status']))) ?></span></td>
                    <td><?= e($li['product_name']) ?></td>
                    <td class="text-center"><?= $li['quantity'] ?></td>
                    <td class="text-end fw-bold">$<?= number_format($li['line_total'], 2) ?></td>
                    <td class="text-end">$<?= number_format($li['txn_total'], 2) ?></td>
                    <td class="text-end"><?= $diffLabel ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Deep Search -->
    <?php if ($combos === null): ?>
        <div class="mb-4 p-3 bg-light border rounded">
            <strong>Can't find it?</strong> Deep Search looks for 2-3 transactions that <em>combine</em> to the discrepancy amount.
            <a href="<?= baseUrl('reports/transaction-search') ?>?amount=<?= e($amount) ?>&tolerance=<?= e($tolerance) ?>&date_from=<?= e($dateFrom) ?>&date_to=<?= e($dateTo) ?>&terminal_id=<?= e($terminalId ?? '') ?>&deep=1"
               class="btn btn-outline-warning btn-sm ms-2">Deep Search</a>
        </div>
    <?php else: ?>
        <h5>Deep Search — Combination Matches</h5>
        <p class="text-muted">Checked <?= $combos['transactions_checked'] ?> transactions for pairs<?= empty($combos['pairs']) ? ' and triples' : '' ?> summing to $<?= number_format($amount, 2) ?> &plusmn;$<?= number_format($tolerance, 2) ?></p>

        <?php if (empty($combos['pairs']) && empty($combos['triples'])): ?>
            <div class="alert alert-secondary">No combinations found.</div>
        <?php endif; ?>

        <?php foreach (['pairs' => 'Pairs', 'triples' => 'Triples'] as $key => $label): ?>
            <?php if (!empty($combos[$key])): ?>
                <h6><?= $label ?> (<?= count($combos[$key]) ?> found)</h6>
                <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>#</th><th>Transactions</th><th class="text-end">Combined Total</th><th class="text-end">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($combos[$key] as $i => $combo): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <?php foreach ($combo['transactions'] as $ct): ?>
                                        <div>
                                            <a href="<?= baseUrl('transactions/view/' . $ct['id']) ?>">#<?= $ct['id'] ?></a>
                                            — $<?= number_format($ct['total'], 2) ?>
                                            <small class="text-muted"><?= date('M j g:ia', strtotime($ct['created_at'])) ?> · <?= e($ct['username']) ?></small>
                                            <?php if ($ct['status'] !== 'completed'): ?>
                                                <?php
                                                $cb = match ($ct['status']) {
                                                    'voided'         => 'bg-danger',
                                                    'partial_refund' => 'bg-warning text-dark',
                                                    'refunded'       => 'bg-secondary',
                                                    default          => 'bg-light text-dark',
                                                };
                                                ?>
                                                <span class="badge <?= $cb ?>"><?= e(ucfirst(str_replace('_', ' ', $ct['status']))) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-end fw-bold">$<?= number_format($combo['sum'], 2) ?></td>
                                <td class="text-end">
                                    <?php
                                    $cd = $combo['diff'];
                                    echo $cd == 0 ? '<span class="badge bg-success">Exact</span>'
                                        : ($cd > 0 ? '+$' . number_format($cd, 2) : '-$' . number_format(abs($cd), 2));
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

<?php elseif (isset($_GET['amount'])): ?>
    <div class="alert alert-warning">Please enter an amount greater than zero.</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
