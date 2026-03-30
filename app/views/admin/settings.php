<?php
$pageTitle = 'Settings';
ob_start();
?>

<h3>POS Settings</h3>

<form method="post" style="max-width:600px">
    <?= csrfField() ?>

    <div class="mb-3">
        <label class="form-label">Shop Location</label>
        <select name="shop_location_id" class="form-select">
            <option value="">— Select —</option>
            <?php foreach ($locations as $loc): ?>
                <option value="<?= $loc['id'] ?>" <?= ($settings['shop_location_id'] ?? '') == $loc['id'] ? 'selected' : '' ?>>
                    <?= e($loc['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">Inventory location that POS deducts stock from.</div>
    </div>

    <div class="mb-3">
        <label class="form-label">Store Name</label>
        <input type="text" name="store_name" class="form-control" value="<?= e($settings['store_name'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Store Address</label>
        <input type="text" name="store_address" class="form-control" value="<?= e($settings['store_address'] ?? '') ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Store Phone</label>
        <input type="text" name="store_phone" class="form-control" value="<?= e($settings['store_phone'] ?? '') ?>">
    </div>

    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">GST Number</label>
            <input type="text" name="gst_number" class="form-control" value="<?= e($settings['gst_number'] ?? '') ?>">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">PST Number</label>
            <input type="text" name="pst_number" class="form-control" value="<?= e($settings['pst_number'] ?? '') ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label">Print Service URL</label>
        <input type="text" name="print_service_url" class="form-control"
               value="<?= e($settings['print_service_url'] ?? 'http://localhost:5000') ?>">
    </div>

    <div class="mb-3">
        <label class="form-label">Receipt Footer</label>
        <textarea name="receipt_footer" class="form-control" rows="2"><?= e($settings['receipt_footer'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
</form>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
