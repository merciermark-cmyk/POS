<?php
$pageTitle = 'Edit Shift';
$isOpen = $shift['status'] === 'open';
ob_start();
?>

<div class="container" style="max-width:600px; margin-top:40px">
    <div class="card shadow">
        <div class="card-body p-4">
            <h3 class="text-center mb-2">Edit Shift</h3>
            <?php if (!empty($terminalName)): ?>
                <p class="text-center"><span class="badge bg-primary"><?= e($terminalName) ?></span></p>
            <?php endif; ?>
            <p class="text-center text-muted">
                <?= date('M j, Y g:i A', strtotime($shift['opened_at'])) ?>
                <?php if ($shift['closed_at']): ?>
                    — <?= date('g:i A', strtotime($shift['closed_at'])) ?>
                <?php else: ?>
                    — <span class="text-success">Open</span>
                <?php endif; ?>
            </p>

            <?php if (!$isOpen): ?>
                <div class="alert alert-info">
                    <strong>Cash was recorded:</strong> $<?= number_format($shift['closing_cash'], 2) ?>
                    (expected $<?= number_format($shift['expected_cash'], 2) ?>)
                </div>

                <h5>Payment Summary</h5>
                <table class="table table-sm mb-4">
                    <?php foreach ($summary['payments'] as $p): ?>
                        <tr>
                            <td><?= e(ucfirst(str_replace('_', ' ', $p['method']))) ?></td>
                            <td class="text-end">$<?= number_format($p['total'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>

            <form method="post" id="editForm">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label fs-5">Opening Float ($)</label>
                    <input type="number" name="opening_float" id="opening_float" class="form-control form-control-lg text-center"
                           step="0.01" min="0" inputmode="decimal"
                           value="<?= number_format((float)$shift['opening_float'], 2, '.', '') ?>">
                    <div class="form-text">The cash float the shift started with.</div>
                </div>
                <?php if (!$isOpen): ?>
                <div class="mb-3">
                    <label class="form-label fs-5">Deposit Envelope ($)</label>
                    <input type="number" name="cash_deposit" id="cash_deposit" class="form-control form-control-lg text-center"
                           step="0.01" min="0" inputmode="decimal"
                           value="<?= $shift['cash_deposit'] !== null ? number_format((float)$shift['cash_deposit'], 2, '.', '') : '' ?>">
                    <div class="form-text">Cash deposit amount for this shift's envelope.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fs-5">Card Terminal Batch Total ($)</label>
                    <input type="number" name="closing_card" id="closing_card" class="form-control form-control-lg text-center"
                           step="0.01" min="0" inputmode="decimal"
                           value="<?= $shift['closing_card'] !== null ? number_format((float)$shift['closing_card'], 2, '.', '') : '' ?>">
                    <div class="form-text">Enter the batch total from the card terminal.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fs-5">Tips from Terminal ($)</label>
                    <input type="number" name="closing_tips" id="closing_tips" class="form-control form-control-lg text-center"
                           step="0.01" min="0" inputmode="decimal"
                           value="<?= $shift['closing_tips'] !== null ? number_format((float)$shift['closing_tips'], 2, '.', '') : '' ?>">
                    <div class="form-text">Enter the tip total from the card terminal batch receipt.</div>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label fs-5">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($shift['notes'] ?? '') ?></textarea>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= baseUrl('shift/report/' . $shift['id']) ?>" class="btn btn-outline-secondary flex-fill">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-lg flex-fill">Save</button>
                </div>
            </form>
            <script>
            document.querySelectorAll('#editForm input').forEach(function(el) {
                el.addEventListener('focus', function() {
                    setTimeout(function() {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 300);
                });
            });
            </script>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
