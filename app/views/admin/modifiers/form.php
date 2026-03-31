<?php
$pageTitle = ($editing ?? false) ? 'Edit Modifier' : 'Add Modifier';
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
               value="<?= e($modifier['name'] ?? '') ?>" placeholder="e.g. Oat Milk">
    </div>

    <div class="mb-3">
        <label class="form-label">Price ($)</label>
        <input type="number" name="price" class="form-control" required step="0.01" min="0"
               value="<?= e($modifier['price'] ?? '0.00') ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Sort Order</label>
        <input type="number" name="sort_order" class="form-control" min="0"
               value="<?= (int)($modifier['sort_order'] ?? 0) ?>">
    </div>

    <div class="mb-3 form-check">
        <input type="checkbox" name="is_active" class="form-check-input" id="isActive"
               <?= ($modifier['is_active'] ?? 1) ? 'checked' : '' ?>>
        <label class="form-check-label" for="isActive">Active</label>
    </div>

    <div class="d-flex gap-2">
        <a href="<?= baseUrl('modifiers') ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
