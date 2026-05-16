<?php
/**
 * Safe Coins admin page — three panels.
 * Variables: $runningBalance, $byDenom, $totalsByType, $recent, $bankTxns
 */
$fmt = fn($v) => '$' . number_format((float)$v, 2);
$denomLabels = [
    'toonie' => 'Toonie',
    'loonie' => 'Loonie',
    'quarter' => 'Quarter',
    'dime' => 'Dime',
    'nickel' => 'Nickel',
    'mixed' => 'Mixed (unsorted)',
];
$typeLabels = [
    'overflow_in' => 'Overflow IN',
    'bank_sell'   => 'Bank Sell',
    'bank_buy'    => 'Bank Buy',
    'adjustment'  => 'Adjustment',
    'reconcile'   => 'Reconcile',
];
?>
<div class="container-fluid py-3">
    <h4 class="mb-3" style="color:var(--olive);font-weight:700;">Safe Coins</h4>

    <!-- Panel 1: Dollar Flow ─────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header" style="background:var(--olive);color:#fff;">
            <strong>Dollar Flow</strong>
        </div>
        <div class="card-body">
            <div class="row g-3 text-center mb-3">
                <div class="col-md-3">
                    <div class="border rounded p-2">
                        <div class="text-muted small">Overflow IN</div>
                        <div class="fw-bold fs-5"><?= e($fmt($totalsByType['overflow_in'])) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2">
                        <div class="text-muted small">Bank Sell (out)</div>
                        <div class="fw-bold fs-5 text-danger"><?= e($fmt($totalsByType['bank_sell'])) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-2">
                        <div class="text-muted small">Bank Buy + Adj.</div>
                        <div class="fw-bold fs-5"><?= e($fmt($totalsByType['bank_buy'] + $totalsByType['adjustment'])) ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="border rounded p-3" style="background:#fff4d6;">
                        <div class="text-muted small">Expected Balance</div>
                        <div class="fw-bold fs-3" style="color:var(--olive);"><?= e($fmt($runningBalance)) ?></div>
                    </div>
                </div>
            </div>
            <div class="text-muted small">
                <i class="bi bi-info-circle"></i>
                Expected balance = sum of all ledger entries. Reconcile against the physical bags to spot variance.
            </div>
        </div>
    </div>

    <!-- Panel 2: Bag Inventory ─────────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center" style="background:var(--olive);color:#fff;">
            <strong>Bag Inventory</strong>
            <button class="btn btn-sm btn-light" id="btnReconcile">Reconcile a bag</button>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Bag (denomination)</th>
                        <th class="text-end">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (['toonie','loonie','quarter','dime','nickel','mixed'] as $d): ?>
                    <tr>
                        <td><?= e($denomLabels[$d]) ?></td>
                        <td class="text-end fw-bold"><?= e($fmt($byDenom[$d])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Panel 3: Bank Transactions ─────────────────────────────────── -->
    <div class="card shadow-sm mb-4">
        <div class="card-header d-flex justify-content-between align-items-center" style="background:var(--olive);color:#fff;">
            <strong>Bank Transactions</strong>
            <div>
                <button class="btn btn-sm btn-light me-1" data-add-type="bank_sell">Sell to bank</button>
                <button class="btn btn-sm btn-light me-1" data-add-type="bank_buy">Buy from bank</button>
                <button class="btn btn-sm btn-outline-light" data-add-type="adjustment">Adjustment</button>
            </div>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>Type</th>
                        <th>Denom</th>
                        <th class="text-end">$</th>
                        <th>Note</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bankTxns)): ?>
                        <tr><td colspan="6" class="text-muted text-center py-3">No entries yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bankTxns as $r): ?>
                        <tr>
                            <td class="small"><?= e(date('Y-m-d H:i', strtotime($r['ts']))) ?></td>
                            <td><span class="badge bg-secondary"><?= e($typeLabels[$r['type']] ?? $r['type']) ?></span></td>
                            <td><?= e($denomLabels[$r['denomination']] ?? $r['denomination']) ?></td>
                            <td class="text-end fw-bold <?= (float)$r['dollars'] < 0 ? 'text-danger' : '' ?>"><?= e($fmt($r['dollars'])) ?></td>
                            <td class="small"><?= e($r['note'] ?? '') ?></td>
                            <td class="small"><?= e($r['created_by_name'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add-entry modal -->
<div class="modal fade" id="scAddModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--olive);color:#fff;">
                <h5 class="modal-title" id="scModalTitle">Add entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="scAddType">
                <div class="mb-3">
                    <label class="form-label">Denomination</label>
                    <select id="scAddDenom" class="form-select">
                        <option value="toonie">Toonie ($2)</option>
                        <option value="loonie">Loonie ($1)</option>
                        <option value="quarter">Quarter ($0.25)</option>
                        <option value="dime">Dime ($0.10)</option>
                        <option value="nickel">Nickel ($0.05)</option>
                        <option value="mixed">Mixed</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Weight (grams) — optional, will compute dollars</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="scAddGrams" placeholder="e.g. 692">
                    <div class="form-text" id="scGramsHint">Toonie = 6.92g each.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Dollars</label>
                    <input type="number" step="0.01" class="form-control" id="scAddDollars" placeholder="e.g. 200.00">
                    <div class="form-text" id="scDollarsHint">For Adjustment: negative values subtract from balance.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Note (optional)</label>
                    <input type="text" maxlength="255" class="form-control" id="scAddNote" placeholder="e.g. BMO armored pickup Fri">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-tan" id="scAddSubmit">Save entry</button>
            </div>
        </div>
    </div>
</div>

<script>
    const BASE_URL = <?= json_encode(baseUrl()) ?>;
    const CSRF_TOKEN = <?= json_encode(generateCsrfToken()) ?>;
</script>
