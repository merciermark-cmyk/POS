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
                        <?= ($data['terminal_id'] == $t['id'] || (!$data['terminal_id'] && stripos($t['name'], 'cash register') !== false)) ? 'selected' : '' ?>>
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

        <!-- Subtotal -->
        <div class="col-md-4">
            <label class="form-label">Subtotal (pre-tax)</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="subtotal" id="me_subtotal"
                       class="form-control" value="<?= e($data['subtotal']) ?>" required>
            </div>
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

        <!-- PST -->
        <div class="col-md-4">
            <label class="form-label">PST</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="pst_amount" id="me_pst"
                       class="form-control" value="<?= e($data['pst_amount']) ?>">
            </div>
        </div>

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

        <!-- Cash -->
        <div class="col-md-4">
            <label class="form-label">Cash Amount</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="cash_amount" id="me_cash"
                       class="form-control" value="<?= e($data['cash_amount']) ?>">
            </div>
        </div>

        <!-- Card -->
        <div class="col-md-4">
            <label class="form-label">Card Amount</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="number" step="0.01" min="0" name="card_amount" id="me_card"
                       class="form-control" value="<?= e($data['card_amount']) ?>">
            </div>
        </div>

        <!-- Payment total display -->
        <div class="col-md-4">
            <label class="form-label">Payment Total</label>
            <div class="input-group">
                <span class="input-group-text">$</span>
                <input type="text" id="me_pay_total" class="form-control" readonly>
            </div>
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
        const pst = val($('me_pst'));
        const total = (subtotal + gst + pst).toFixed(2);
        $('me_total').value = total;

        const cash = val($('me_cash'));
        const card = val($('me_card'));
        const payTotal = (cash + card).toFixed(2);
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

    ['me_subtotal','me_gst','me_pst','me_cash','me_card'].forEach(id => {
        $(id).addEventListener('input', recalc);
    });

    recalc();
})();
</script>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
