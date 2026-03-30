<?php
$pageTitle = 'Product Images';
ob_start();
?>

<h3>Product Images</h3>
<p class="text-muted">Upload images for POS product tiles (300x300, auto-cropped).</p>

<!-- Upload Form -->
<div class="card mb-4" style="max-width:500px">
    <div class="card-body">
        <h5>Upload Image</h5>
        <form method="post" action="<?= baseUrl('images/upload') ?>" enctype="multipart/form-data">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label">Product</label>
                <select name="product_id" class="form-select" required>
                    <option value="">— Select —</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['product_code'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Image (JPEG, PNG, or WebP, max 5MB)</label>
                <input type="file" name="image" class="form-control" accept="image/jpeg,image/png,image/webp" required>
            </div>
            <button type="submit" class="btn btn-primary">Upload</button>
        </form>
    </div>
</div>

<!-- Image Grid -->
<div class="row">
    <?php foreach ($products as $p): ?>
        <?php if (!empty($p['images'])): ?>
            <div class="col-md-3 col-sm-4 mb-3">
                <div class="card">
                    <div class="card-body p-2 text-center">
                        <strong class="d-block mb-1" style="font-size:0.85rem"><?= e($p['name']) ?></strong>
                        <?php foreach ($p['images'] as $img): ?>
                            <div class="mb-2 position-relative">
                                <img src="<?= baseUrl('public/uploads/pos/' . $img['filename']) ?>"
                                     class="img-fluid rounded" style="max-height:150px">
                                <form method="post" action="<?= baseUrl('images/delete') ?>" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="image_id" value="<?= $img['id'] ?>">
                                    <button class="btn btn-sm btn-danger position-absolute top-0 end-0"
                                            onclick="return confirm('Delete this image?')">&times;</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
