<?php
$pageTitle = ($editing ?? false) ? 'Edit Terminal' : 'Add Terminal';
ob_start();
?>

<h3><?= e($pageTitle) ?></h3>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?>
            <div><?= e($err) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" style="max-width:500px">
    <?= csrfField() ?>

    <div class="mb-3">
        <label class="form-label">Name</label>
        <input type="text" name="name" class="form-control" required
               value="<?= e($terminal['name'] ?? '') ?>" placeholder="e.g. Register 1">
    </div>

    <div class="mb-3">
        <label class="form-label">Print Service URL</label>
        <input type="text" name="print_service_url" class="form-control" required
               value="<?= e($terminal['print_service_url'] ?? 'http://localhost:5000') ?>"
               placeholder="http://localhost:5000">
        <div class="form-text">The URL of the print service running on this terminal's machine.</div>
    </div>

    <div class="mb-3">
        <label class="form-label">Moneris Terminal ID</label>
        <input type="text" name="moneris_terminal_id" class="form-control"
               value="<?= e($terminal['moneris_terminal_id'] ?? '') ?>"
               placeholder="e.g. P0401234 (leave blank if not using Moneris)">
        <div class="form-text">The Moneris Go terminal ID assigned to this register.</div>
    </div>

    <div class="mb-3 form-check">
        <input type="checkbox" name="is_active" class="form-check-input" id="isActive"
               <?= ($terminal['is_active'] ?? 1) ? 'checked' : '' ?>>
        <label class="form-check-label" for="isActive">Active</label>
    </div>

    <div class="d-flex gap-2">
        <a href="<?= baseUrl('terminals') ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
