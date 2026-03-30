<?php
$pageTitle = ($editing ?? false) ? 'Edit User' : 'Add User';
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
        <label class="form-label">Username</label>
        <input type="text" name="username" class="form-control" required minlength="3"
               value="<?= e($user['username'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Password <?= ($editing ?? false) ? '(leave blank to keep)' : '' ?></label>
        <input type="password" name="password" class="form-control"
               <?= ($editing ?? false) ? '' : 'required minlength="6"' ?>>
    </div>

    <div class="mb-3">
        <label class="form-label">Quick PIN (4 digits, optional)</label>
        <input type="text" name="pin" class="form-control" maxlength="4" pattern="\d{4}"
               value="<?= e($user['pin'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Staff Code (3 digits, optional)</label>
        <input type="text" name="staff_code" class="form-control" maxlength="3" pattern="\d{3}"
               value="<?= e($user['staff_code'] ?? '') ?>"
               placeholder="Used to verify identity on staff picker">
    </div>

    <div class="mb-3">
        <label class="form-label">Role</label>
        <select name="role" class="form-select">
            <option value="cashier" <?= ($user['role'] ?? '') === 'cashier' ? 'selected' : '' ?>>Cashier</option>
            <option value="manager" <?= ($user['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
        </select>
    </div>

    <div class="mb-3 form-check">
        <input type="checkbox" name="is_active" class="form-check-input" id="isActive"
               <?= ($user['is_active'] ?? 1) ? 'checked' : '' ?>>
        <label class="form-check-label" for="isActive">Active</label>
    </div>

    <div class="d-flex gap-2">
        <a href="<?= baseUrl('users') ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
