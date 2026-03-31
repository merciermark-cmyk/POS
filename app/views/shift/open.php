<?php
$pageTitle = 'Open Shift';
ob_start();
?>

<div class="container" style="max-width:450px; margin-top:80px">
    <div class="card shadow">
        <div class="card-body p-4">
            <h3 class="text-center mb-4">Open Shift</h3>
            <p class="text-muted text-center">Enter the opening cash float in the drawer.</p>

            <form method="post">
                <?= csrfField() ?>
                <?php if (!empty($terminals)): ?>
                <div class="mb-3">
                    <label class="form-label fs-5">Terminal</label>
                    <select name="terminal_id" class="form-select form-select-lg">
                        <option value="">— No Terminal —</option>
                        <?php foreach ($terminals as $t): ?>
                            <option value="<?= $t['id'] ?>"
                                <?= ($cookieTerminalId ?? null) == $t['id'] ? 'selected' : '' ?>>
                                <?= e($t['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="mb-4">
                    <label class="form-label fs-5">Opening Float ($)</label>
                    <input type="number" name="opening_float" class="form-control form-control-lg text-center"
                           step="0.01" min="0" value="200.00" required autofocus>
                </div>
                <button type="submit" class="btn btn-success btn-lg w-100">Start Shift</button>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
