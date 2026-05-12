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
        <label class="form-label">PIN (1–3 digits, optional)</label>
        <input type="text" name="pin" class="form-control" maxlength="3" pattern="\d{1,3}"
               value="<?= e($user['pin'] ?? '') ?>"
               placeholder="Used for login and staff picker">
    </div>

    <div class="mb-3">
        <label class="form-label">Role</label>
        <select name="role" class="form-select">
            <option value="cashier" <?= ($user['role'] ?? '') === 'cashier' ? 'selected' : '' ?>>Cashier</option>
            <option value="manager" <?= ($user['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
        </select>
    </div>

    <?php if ($editing ?? false): ?>
    <div class="mb-3">
        <label class="form-label">Schedule Account</label>
        <select name="schedule_user_id" class="form-select">
            <option value="">— None —</option>
            <?php foreach ($schedUsers ?? [] as $su): ?>
                <option value="<?= (int)$su['id'] ?>"
                    <?= (($user['schedule_user_id'] ?? '') == $su['id']) ? 'selected' : '' ?>>
                    <?= e($su['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">Link to schedule app for clock in/out</div>
    </div>
    <?php endif; ?>

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
