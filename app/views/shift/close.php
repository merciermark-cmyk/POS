<?php
$pageTitle = 'Close Shift';
ob_start();
?>

<div class="container" style="max-width:600px; margin-top:40px">
    <div class="card shadow">
        <div class="card-body p-4">
            <h3 class="text-center mb-4">Close Shift</h3>

            <div class="row mb-4">
                <div class="col-6">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <small class="text-muted">Transactions</small>
                            <div class="fs-3"><?= $summary['transaction_count'] ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <small class="text-muted">Total Sales</small>
                            <div class="fs-3">$<?= number_format($summary['total'], 2) ?></div>
                        </div>
                    </div>
                </div>
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

            <form method="post">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label fs-5">Closing Cash Count ($)</label>
                    <input type="number" name="closing_cash" class="form-control form-control-lg text-center"
                           step="0.01" min="0" required autofocus>
                    <div class="form-text">Count the cash in the drawer and enter the total.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
                <div class="d-flex gap-2">
                    <a href="<?= baseUrl('sale') ?>" class="btn btn-outline-secondary flex-fill">Cancel</a>
                    <button type="submit" class="btn btn-warning btn-lg flex-fill">Close Shift</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
