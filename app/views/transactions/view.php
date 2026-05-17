<?php
$pageTitle = 'Transaction #' . ($transaction['id'] ?? '');
$txnModel = new Transaction();
$refundedQtys = $txnModel->getRefundedQuantities((int)($transaction['id'] ?? 0));
$refunds = $txnModel->getRefunds((int)($transaction['id'] ?? 0));

// Status badge helper
$statusBadge = match ($transaction['status'] ?? '') {
    'completed'      => '<span class="badge bg-success fs-6">Completed</span>',
    'voided'         => '<span class="badge bg-danger fs-6">Voided</span>',
    'refunded'       => '<span class="badge bg-secondary fs-6">Refunded</span>',
    'partial_refund' => '<span class="badge bg-warning text-dark fs-6">Partial Refund</span>',
    default          => '<span class="badge bg-dark fs-6">' . e($transaction['status'] ?? '') . '</span>',
};

ob_start();
?>

<?php if (!$transaction): ?>
    <div class="alert alert-danger">Transaction not found.</div>
<?php else: ?>
    <div class="row">
        <div class="col-lg-8">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h3 class="mb-0">
                    <?php if ($transaction['daily_number']): ?>
                        Sale #<?= $transaction['daily_number'] ?>
                        <small class="text-muted fs-6">Txn #<?= $transaction['id'] ?></small>
                        <span class="badge bg-info fs-6">Year #<?= $transaction['annual_number'] ?></span>
                    <?php else: ?>
                        Transaction #<?= $transaction['id'] ?>
                    <?php endif; ?>
                </h3>
                <?= $statusBadge ?>
            </div>

            <p class="text-muted">
                <?= date('F j, Y g:i A', strtotime($transaction['created_at'])) ?>
                &mdash; Cashier: <?= e($transaction['username']) ?>
            </p>

            <?php if ($transaction['status'] === 'voided'): ?>
                <div class="alert alert-danger">
                    Voided: <?= e($transaction['void_reason']) ?>
                    <?php if (!empty($transaction['customer_name'])): ?>
                        <br><strong>Customer:</strong> <?= e($transaction['customer_name']) ?>
                    <?php endif; ?>
                    <br><small>at <?= date('M j g:i A', strtotime($transaction['voided_at'])) ?></small>
                </div>
            <?php endif; ?>

            <table class="table">
                <thead>
                    <tr><th>Product</th><th>Code</th><th class="text-center">Qty</th>
                        <th class="text-center">Refunded</th>
                        <th class="text-end">Price</th><th class="text-end">Tax</th><th class="text-end">Total</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php $refQty = $refundedQtys[(int)$item['id']] ?? 0; ?>
                        <tr>
                            <td>
                                <?= e($item['product_name']) ?>
                                <?php if (($item['discount_percent'] ?? 0) > 0): ?>
                                    <span class="badge bg-success">-<?= (int)$item['discount_percent'] ?>%</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= e($item['product_code']) ?></td>
                            <td class="text-center"><?= $item['quantity'] ?></td>
                            <td class="text-center"><?= $refQty > 0 ? '<span class="text-danger">' . $refQty . '</span>' : '—' ?></td>
                            <td class="text-end">$<?= number_format($item['unit_price'], 2) ?></td>
                            <td class="text-end">$<?= number_format($item['gst'] + $item['pst'], 2) ?></td>
                            <td class="text-end">$<?= number_format($item['line_total'], 2) ?></td>
                        </tr>
                        <?php if (!empty($item['modifiers'])): ?>
                            <?php foreach ($item['modifiers'] as $mod): ?>
                                <tr class="text-muted">
                                    <td class="ps-4 small">+ <?= e($mod['modifier_name']) ?> ($<?= number_format($mod['modifier_price'], 2) ?>)</td>
                                    <td colspan="6"></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><td colspan="6" class="text-end">Subtotal</td><td class="text-end">$<?= number_format($transaction['subtotal'], 2) ?></td></tr>
                    <tr><td colspan="6" class="text-end">GST</td><td class="text-end">$<?= number_format($transaction['gst_amount'], 2) ?></td></tr>
                    <tr><td colspan="6" class="text-end">PST</td><td class="text-end">$<?= number_format($transaction['pst_amount'], 2) ?></td></tr>
                    <tr class="fw-bold"><td colspan="6" class="text-end">Total</td><td class="text-end">$<?= number_format($transaction['total'], 2) ?></td></tr>
                </tfoot>
            </table>

            <h5>Payments</h5>
            <table class="table table-sm" style="max-width:500px">
                <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td><?= e(ucfirst(str_replace('_', ' ', $pay['method']))) ?></td>
                        <td class="text-end">$<?= number_format($pay['amount'], 2) ?></td>
                        <td class="text-muted"><?= e($pay['reference'] ?? '') ?></td>
                        <?php if (in_array($transaction['status'], ['completed', 'partial_refund'])): ?>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                        onclick="togglePaymentEdit(<?= $pay['id'] ?>)" title="Change method">
                                    <small>Edit</small>
                                </button>
                            </td>
                        <?php endif; ?>
                    </tr>
                    <?php if (in_array($transaction['status'], ['completed', 'partial_refund'])): ?>
                        <tr id="payEdit-<?= $pay['id'] ?>" class="d-none">
                            <td colspan="4">
                                <form method="post" action="<?= baseUrl('transactions/change-payment') ?>" class="d-flex align-items-center gap-2">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="payment_id" value="<?= $pay['id'] ?>">
                                    <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                                    <select name="new_method" class="form-select form-select-sm" style="width:auto">
                                        <option value="cash" <?= $pay['method'] === 'cash' ? 'selected' : '' ?>>Cash</option>
                                        <option value="card" <?= $pay['method'] === 'card' ? 'selected' : '' ?>>Card</option>
                                        <option value="gift_card" <?= $pay['method'] === 'gift_card' ? 'selected' : '' ?>>Gift Card</option>
                                        <option value="web_gift_card" <?= $pay['method'] === 'web_gift_card' ? 'selected' : '' ?>>Web Gift Card</option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                    <button type="button" class="btn btn-sm btn-link" onclick="togglePaymentEdit(<?= $pay['id'] ?>)">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </table>

            <?php if (!empty($refunds)): ?>
                <h5 class="mt-4">Refund History</h5>
                <table class="table table-sm">
                    <thead><tr><th>#</th><th>Date</th><th>By</th><th>Customer</th><th>Reason</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($refunds as $ref): ?>
                            <tr>
                                <td><?= $ref['id'] ?></td>
                                <td><?= date('M j g:i A', strtotime($ref['created_at'])) ?></td>
                                <td><?= e($ref['refunded_by_name']) ?></td>
                                <td><?= e($ref['customer_name'] ?? '') ?></td>
                                <td><?= e($ref['reason']) ?></td>
                                <td class="text-end text-danger">-$<?= number_format($ref['total'], 2) ?></td>
                                <td><a href="<?= baseUrl('transactions/refund-receipt/' . $ref['id']) ?>" class="btn btn-sm btn-outline-secondary">Receipt</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <h5>Actions</h5>
                    <button class="btn btn-outline-secondary w-100 mb-2"
                            onclick="printReceipt(<?= $transaction['id'] ?>)">Reprint Receipt</button>

                    <?php if ($transaction['status'] === 'completed'): ?>
                        <hr>
                        <form method="post" action="<?= baseUrl('transactions/void') ?>"
                              onsubmit="return confirm('Void this transaction? This will return items to inventory.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                            <div class="mb-2">
                                <label class="form-label">Customer Name</label>
                                <input type="text" name="customer_name" class="form-control" required placeholder="Customer name for records">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Void Reason</label>
                                <input type="text" name="void_reason" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-danger w-100">Void Transaction</button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($transaction['status'], ['completed', 'partial_refund'])): ?>
                        <hr>
                        <h6>Refund Items</h6>
                        <form method="post" action="<?= baseUrl('transactions/refund') ?>" id="refundForm"
                              onsubmit="return validateRefundForm()">
                            <?= csrfField() ?>
                            <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">

                            <div class="mb-2">
                                <?php foreach ($items as $item): ?>
                                    <?php
                                    $refQty = $refundedQtys[(int)$item['id']] ?? 0;
                                    $maxRefundable = (float)$item['quantity'] - $refQty;
                                    if ($maxRefundable <= 0) continue;
                                    ?>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <input type="number" name="refund_qty[<?= $item['id'] ?>]"
                                               class="form-control refund-qty" style="width:70px"
                                               min="0" max="<?= $maxRefundable ?>" step="0.01" value="0"
                                               data-unit-price="<?= $item['unit_price'] ?>"
                                               data-tax-profile="<?= e($item['tax_profile']) ?>"
                                               onchange="updateRefundTotal()" oninput="updateRefundTotal()">
                                        <span class="small"><?= e($item['product_name']) ?> (max <?= $maxRefundable ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="fw-bold mb-2">
                                Refund Total: <span id="refundTotal" class="text-danger">$0.00</span>
                            </div>

                            <?php if (!isManager()): ?>
                                <div class="mb-2 d-none" id="authCodeSection">
                                    <label class="form-label">Authorization Code <span class="text-muted small">(required over $50)</span></label>
                                    <div class="input-group">
                                        <input type="text" name="auth_code" id="authCode" class="form-control font-monospace"
                                               maxlength="6" pattern="\d{6}"
                                               placeholder="6-digit code from manager"
                                               inputmode="numeric" autocomplete="off">
                                        <button type="button" class="btn btn-outline-secondary" id="btnVerifyCode" onclick="verifyAuthCode()">Verify</button>
                                    </div>
                                    <div id="authCodeStatus" class="small mt-1"></div>
                                </div>
                            <?php endif; ?>

                            <div class="mb-2">
                                <label class="form-label">Customer Name</label>
                                <input type="text" name="customer_name" class="form-control" required placeholder="Customer name for records">
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Reason</label>
                                <input type="text" name="refund_reason" class="form-control" required>
                            </div>

                            <div class="mb-2">
                                <label class="form-label">Refund Payment Method</label>
                                <select name="refund_pay_method[]" class="form-select">
                                    <option value="cash">Cash</option>
                                    <option value="card">Card</option>
                                    <option value="gift_card">Gift Card</option>
                                </select>
                                <input type="hidden" name="refund_pay_amount[]" id="refundPayAmount" value="0">
                            </div>

                            <button type="submit" class="btn btn-warning w-100" id="btnSubmitRefund">Process Refund</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <a href="<?= baseUrl('transactions') ?>" class="btn btn-outline-primary w-100 mt-2">Back to List</a>
        </div>
    </div>
<?php endif; ?>

<script>
function togglePaymentEdit(payId) {
    var row = document.getElementById('payEdit-' + payId);
    if (row) row.classList.toggle('d-none');
}

function printReceipt(txnId) {
    fetch('<?= baseUrl('api/print/receipt') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'transaction_id=' + txnId
    }).catch(() => {});
}

function updateRefundTotal() {
    var total = 0;
    document.querySelectorAll('.refund-qty').forEach(function(el) {
        var qty = parseInt(el.value) || 0;
        if (qty <= 0) return;
        var price = parseFloat(el.dataset.unitPrice) || 0;
        var profile = el.dataset.taxProfile;
        var subtotal = qty * price;
        var gst = 0, pst = 0;
        if (profile === 'gst_only') {
            gst = Math.round(subtotal * <?= TAX_GST_RATE ?> * 100) / 100;
        } else if (profile === 'gst_pst') {
            gst = Math.round(subtotal * <?= TAX_GST_RATE ?> * 100) / 100;
            pst = Math.round(subtotal * <?= TAX_PST_RATE ?> * 100) / 100;
        }
        total += subtotal + gst + pst;
    });
    total = Math.round(total * 100) / 100;
    document.getElementById('refundTotal').textContent = '$' + total.toFixed(2);
    document.getElementById('refundPayAmount').value = total.toFixed(2);

    // Show/hide auth code section for cashiers when over $50
    var authSection = document.getElementById('authCodeSection');
    var submitBtn = document.getElementById('btnSubmitRefund');
    if (authSection) {
        if (total > 50) {
            authSection.classList.remove('d-none');
            var authCode = document.getElementById('authCode');
            // Disable submit unless code is already verified (readOnly means verified)
            if (authCode && !authCode.readOnly) {
                submitBtn.disabled = true;
            }
        } else {
            authSection.classList.add('d-none');
            submitBtn.disabled = false;
        }
    }
}

function validateRefundForm() {
    var total = parseFloat(document.getElementById('refundPayAmount').value) || 0;
    if (total <= 0) {
        alert('Please select at least one item to refund.');
        return false;
    }
    return confirm('Process refund of $' + total.toFixed(2) + '?');
}

function verifyAuthCode() {
    var code = document.getElementById('authCode');
    if (!code) return;
    var val = code.value.trim();
    if (val.length !== 6 || !/^\d{6}$/.test(val)) {
        document.getElementById('authCodeStatus').innerHTML = '<span class="text-danger">Enter a 6-digit code.</span>';
        return;
    }

    var btn = document.getElementById('btnVerifyCode');
    btn.disabled = true;
    btn.textContent = 'Checking...';

    fetch('<?= baseUrl('api/temp-auth/verify') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'code=' + encodeURIComponent(val)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Verify';
        if (data.valid) {
            document.getElementById('authCodeStatus').innerHTML =
                '<span class="text-success">Authorized by ' + data.manager_username + '</span>';
            document.getElementById('btnSubmitRefund').disabled = false;
            code.readOnly = true;
        } else {
            document.getElementById('authCodeStatus').innerHTML =
                '<span class="text-danger">' + (data.error || 'Invalid code') + '</span>';
            document.getElementById('btnSubmitRefund').disabled = true;
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.textContent = 'Verify';
        document.getElementById('authCodeStatus').innerHTML =
            '<span class="text-danger">Verification failed. Try again.</span>';
    });
}
</script>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
