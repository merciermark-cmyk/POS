<?php
$pageTitle = 'Modifiers';
ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Beverage Modifiers</h3>
    <a href="<?= baseUrl('modifiers/create') ?>" class="btn btn-primary">Add Modifier</a>
</div>

<table class="table table-striped">
    <thead>
        <tr><th>Name</th><th class="text-end">Price</th><th class="text-center">Sort</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
        <?php foreach ($modifiers as $m): ?>
            <tr>
                <td><?= e($m['name']) ?></td>
                <td class="text-end">$<?= number_format($m['price'], 2) ?></td>
                <td class="text-center"><?= $m['sort_order'] ?></td>
                <td>
                    <span class="badge bg-<?= $m['is_active'] ? 'success' : 'danger' ?>">
                        <?= $m['is_active'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <a href="<?= baseUrl('modifiers/edit/' . $m['id']) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <form method="post" action="<?= baseUrl('modifiers/delete/' . $m['id']) ?>" class="d-inline"
                          onsubmit="return confirm('Delete this modifier?')">
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
