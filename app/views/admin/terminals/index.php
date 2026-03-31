<?php
$pageTitle = 'Terminals';
ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Terminals</h3>
    <a href="<?= baseUrl('terminals/create') ?>" class="btn btn-primary">Add Terminal</a>
</div>

<table class="table table-striped">
    <thead>
        <tr><th>Name</th><th>Print Service URL</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
        <?php if (empty($terminals)): ?>
            <tr><td colspan="4" class="text-muted text-center">No terminals configured.</td></tr>
        <?php endif; ?>
        <?php foreach ($terminals as $t): ?>
            <tr>
                <td><?= e($t['name']) ?></td>
                <td class="text-muted"><?= e($t['print_service_url']) ?></td>
                <td>
                    <span class="badge bg-<?= $t['is_active'] ? 'success' : 'danger' ?>">
                        <?= $t['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <a href="<?= baseUrl('terminals/edit/' . $t['id']) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <form method="post" action="<?= baseUrl('terminals/delete/' . $t['id']) ?>" class="d-inline"
                          onsubmit="return confirm('Delete this terminal?')">
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
