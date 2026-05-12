<?php
$pageTitle = 'Manual Entry';
ob_start();
?>

<h3>Manual Entry — Cash Register</h3>
<p class="text-muted">Enter end-of-day totals from the cash register. No inventory is deducted.</p>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= baseUrl('manual-entry') ?>" class="needs-validation" novalidate>
    <?= csrfField() ?>

    <div class="row g-3 mb-4" style="max-width: 700px;">
        <!-- Terminal -->
        <div class="col-md-6">
            <label class="form-label">Terminal</label>
            <select name="terminal_id" class="form-select" required>
                <option value="">— Select —</option>
                <?php foreach ($terminals as $t): ?>
                    <option value="<?= $t['id'] ?>"
                        <?= ($data['terminal_id'] == $t['id'] || (!$data['terminal_id'] && stripos($t['name'], 'iced tea') !== false)) ? 'selected' : '' ?>>
                        <?= e($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Date -->
        <div class="col-md-6">
            <label class="form-label">Date</label>
            <input type="date" name="entry_date" class="form-control" value="<?= e($data['entry_date']) ?>" required>
        </div>

        <div class="col-12">
            <h5 class="mb-0">Z Tape Totals</h5>
            <small class="text-muted">Enter the figures from the cash register Z tape.</small>
        </div>

        <!-- Subtotal -->
        <div class="col-md-4">
            <label class="form-label">Subtotal (pre-tax)</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="subtotal" id="me_subtotal"
                       class="form-control" value="<?= e($data['subtotal']) ?>" required>
            </div>
            <small class="text-muted" id="me_gst_check" style="display:none;"></small>
        </div>

        <!-- GST -->
        <div class="col-md-4">
            <label class="form-label">GST</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="gst_amount" id="me_gst"
                       class="form-control" value="<?= e($data['gst_amount']) ?>">
            </div>
        </div>

        <input type="hidden" name="pst_amount" value="0">

        <!-- Total (calculated) -->
        <div class="col-md-4">
            <label class="form-label fw-bold">Total</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="text" id="me_total" class="form-control fw-bold" readonly>
            </div>
        </div>

        <!-- Transaction count -->
        <div class="col-md-4">
            <label class="form-label">Transactions</label>
            <input type="number" step="1" min="0" name="transaction_count"
                   class="form-control" value="<?= e($data['transaction_count']) ?>" placeholder="# of sales">
        </div>

        <div class="col-12"><hr></div>

        <div class="col-12">
            <h5 class="mb-0">Drawer Count</h5>
            <small class="text-muted">Count all cash and coin in the drawer, then enter card terminal totals.</small>
        </div>

        <!-- Cash -->
        <div class="col-md-3">
            <label class="form-label">Cash in Drawer</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="cash_amount" id="me_cash"
                       class="form-control" value="<?= e($data['cash_amount']) ?>">
            </div>
            <small class="text-muted">Includes $150 float</small>
        </div>

        <!-- Card -->
        <div class="col-md-3">
            <label class="form-label">Card Amount</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="card_amount" id="me_card"
                       class="form-control" value="<?= e($data['card_amount']) ?>">
            </div>
        </div>

        <!-- Tips (card only) -->
        <div class="col-md-3">
            <label class="form-label">Card Tips</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="tip_amount" id="me_tips"
                       class="form-control" value="<?= e($data['tip_amount']) ?>">
            </div>
        </div>

        <!-- Payment total display -->
        <div class="col-md-3">
            <label class="form-label">Payment Total (without tips)</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="text" id="me_pay_total" class="form-control" readonly>
            </div>
        </div>

        <!-- Deposit Amount -->
        <div class="col-md-3">
            <label class="form-label">Deposit Amount</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="deposit_amount" id="me_deposit"
                       class="form-control" value="<?= e($data['deposit_amount']) ?>">
            </div>
            <small class="text-muted">Cash for deposit envelope</small>
        </div>

        <!-- Notes -->
        <div class="col-12">
            <label class="form-label">Notes (optional)</label>
            <textarea name="notes" class="form-control" rows="2"><?= e($data['notes']) ?></textarea>
        </div>

        <div class="col-12">
            <button type="submit" class="btn btn-primary" id="me_submit">Save Manual Entry</button>
            <a href="<?= baseUrl('transactions') ?>" class="btn btn-outline-secondary ms-2">Cancel</a>
        </div>
    </div>
</form>

<script>
(function() {
    const $ = id => document.getElementById(id);
    const val = el => parseFloat(el.value) || 0;

    function recalc() {
        const subtotal = val($('me_subtotal'));
        const gst = val($('me_gst'));
        const total = (subtotal + gst).toFixed(2);
        $('me_total').value = total;

        // Cross-check: GST should be ~5% of subtotal
        const gstCheck = $('me_gst_check');
        if (gst > 0 && subtotal > 0) {
            const expectedSubtotal = Math.round(gst / 0.05 * 100) / 100;
            const diff = Math.abs(subtotal - expectedSubtotal);
            if (diff > 0.02) {
                gstCheck.style.display = 'block';
                gstCheck.innerHTML = '<span class="text-warning">Expected ~$' + expectedSubtotal.toFixed(2) + ' from GST</span>';
            } else {
                gstCheck.style.display = 'block';
                gstCheck.innerHTML = '<span class="text-success">GST matches</span>';
            }
        } else {
            gstCheck.style.display = 'none';
        }

        const cash = val($('me_cash'));
        const cashSales = Math.max(0, cash - 150);
        const card = val($('me_card'));
        const tips = val($('me_tips'));
        const payTotal = (cashSales + card).toFixed(2);
        $('me_pay_total').value = payTotal;

        // Highlight if payment < total
        const payEl = $('me_pay_total');
        if (parseFloat(payTotal) < parseFloat(total) && parseFloat(total) > 0) {
            payEl.classList.add('text-danger');
            payEl.classList.remove('text-success');
        } else {
            payEl.classList.remove('text-danger');
            payEl.classList.add('text-success');
        }
    }

    ['me_subtotal','me_gst','me_cash','me_card','me_tips'].forEach(id => {
        $(id).addEventListener('input', recalc);
    });

    recalc();
})();
</script>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
