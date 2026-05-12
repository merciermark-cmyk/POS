<?php
$pageTitle = 'Users';
ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>POS Users</h3>
    <a href="<?= baseUrl('users/create') ?>" class="btn btn-primary">Add User</a>
</div>

<table class="table table-striped">
    <thead>
        <tr><th>Username</th><th>Role</th><th>PIN</th><th>Schedule</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['username']) ?></td>
                <td><span class="badge bg-<?= $u['role'] === 'manager' ? 'primary' : 'secondary' ?>"><?= e($u['role']) ?></span></td>
                <td><?= $u['pin'] ? '****' : '—' ?></td>
                <td><?= !empty($u['schedule_user_id']) && isset($schedMap[$u['schedule_user_id']]) ? e($schedMap[$u['schedule_user_id']]) : '—' ?></td>
                <td>
                    <span class="badge bg-<?= $u['is_active'] ? 'success' : 'danger' ?>">
                        <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <a href="<?= baseUrl('users/edit/' . $u['id']) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <form method="post" action="<?= baseUrl('users/delete/' . $u['id']) ?>" class="d-inline"
                          onsubmit="return confirm('Delete this user?')">
                        <?= csrfField() ?>
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
