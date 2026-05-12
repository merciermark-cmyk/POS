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

    <div class="mb-3">
        <label class="form-label">Standalone Refund Threshold ($)</label>
        <input type="number" name="standalone_refund_threshold" class="form-control" step="0.01" min="0"
               value="<?= e($settings['standalone_refund_threshold'] ?? '50.00') ?>">
        <div class="form-text">Refunds above this amount require manager PIN authorization.</div>
    </div>

    <hr class="my-4">
    <h5>Currency</h5>

    <div class="mb-3">
        <label class="form-label">USD Markup (%)</label>
        <input type="number" name="usd_markup_percent" class="form-control" step="0.1" min="0" max="20"
               value="<?= e($settings['usd_markup_percent'] ?? '2') ?>">
        <div class="form-text">Markup above Bank of Canada rate for USD cash payments. Default 2%.</div>
    </div>

    <hr class="my-4">
    <h5>Moneris Integration</h5>

    <div class="mb-3 form-check">
        <input type="checkbox" name="moneris_enabled" class="form-check-input" id="monerisEnabled"
               <?= ($settings['moneris_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="monerisEnabled">Enable Moneris Integration</label>
        <div class="form-text">Send card payments directly to Moneris Go terminal from POS.</div>
    </div>

    <div class="mb-3 form-check">
        <input type="checkbox" name="moneris_sandbox" class="form-check-input" id="monerisSandbox"
               <?= ($settings['moneris_sandbox'] ?? '1') === '1' ? 'checked' : '' ?>>
        <label class="form-check-label" for="monerisSandbox">Sandbox Mode (testing)</label>
    </div>

    <div class="mb-3">
        <label class="form-label">API Token</label>
        <input type="text" name="moneris_api_token" class="form-control"
               value="<?= e($settings['moneris_api_token'] ?? '') ?>" placeholder="e.g. 6R7HpKWlk6CVqINuk4YM">
    </div>

    <div class="mb-3">
        <label class="form-label">Store ID</label>
        <input type="text" name="moneris_store_id" class="form-control"
               value="<?= e($settings['moneris_store_id'] ?? '') ?>" placeholder="e.g. mogo145083">
    </div>

    <div class="mb-3">
        <label class="form-label">IST Config Code</label>
        <input type="text" name="moneris_ist_config_code" class="form-control"
               value="<?= e($settings['moneris_ist_config_code'] ?? '') ?>">
        <div class="form-text">Provided by Moneris during integration setup.</div>
    </div>

    <button type="submit" class="btn btn-primary">Save Settings</button>
</form>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/admin.php';
