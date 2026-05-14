<?php
$pageTitle = 'Open Shift';
ob_start();
?>

<div class="container" style="max-width:700px; margin-top:60px">

    <?php if (!empty($terminals)): ?>
        <h3 class="text-center mb-4">Open Shift</h3>
        <div class="row g-3">
            <?php foreach ($terminals as $t): ?>
                <div class="col-6">
                    <?php if ($t['has_open_shift']): ?>
                        <!-- In Use — greyed out -->
                        <div class="card shadow-sm h-100" style="opacity:0.45; border:2px solid #6c757d">
                            <div class="card-body text-center py-5">
                                <h4 class="mb-2"><?= e($t['name']) ?></h4>
                                <span class="badge bg-secondary fs-6 mb-2">In Use</span>
                                <?php if (!empty($t['open_shift']['username'])): ?>
                                    <p class="text-muted mb-0 small">Opened by: <?= e($t['open_shift']['username']) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Available — can open -->
                        <div class="card shadow h-100" style="border:2px solid #198754; cursor:pointer"
                             onclick="this.querySelector('.shift-form').style.display='block'; this.style.cursor='default'">
                            <div class="card-body text-center py-5">
                                <h4 class="mb-3"><?= e($t['name']) ?></h4>
                                <button type="button" class="btn btn-success btn-lg w-100 shift-open-btn">
                                    Open <?= e($t['name']) ?>
                                </button>
                                <div class="shift-form mt-3" style="display:none">
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="terminal_id" value="<?= $t['id'] ?>">
                                        <div class="mb-3">
                                            <label class="form-label">Opening Float ($)</label>
                                            <?php $suggestedFloat = $lastFloats[$t['id']] ?? 100.00; ?>
                                            <input type="number" name="opening_float" class="form-control form-control-lg text-center"
                                                   step="0.01" min="0" value="<?= number_format($suggestedFloat, 2) ?>" required>
                                        </div>
                                        <button type="submit" class="btn btn-success btn-lg w-100">Start Shift</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="text-muted text-center">No active terminals configured.</p>
    <?php endif; ?>

    <?php if (isManager()): ?>
    <hr class="my-3">
    <div class="d-grid gap-2" style="max-width:300px; margin:0 auto">
        <a href="<?= baseUrl('reports/daily') ?>" class="btn btn-outline-secondary">Reports</a>
        <a href="<?= baseUrl('shift/history') ?>" class="btn btn-outline-secondary">Shift History</a>
    </div>
    <?php endif; ?>
</div>

<script>
// Hide the "Open" button when form is revealed
document.querySelectorAll('.shift-open-btn').forEach(btn => {
    btn.addEventListener('click', e => {
        e.stopPropagation();
        btn.style.display = 'none';
        btn.closest('.card-body').querySelector('.shift-form').style.display = 'block';
    });
});
</script>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
