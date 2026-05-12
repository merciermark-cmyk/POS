<?php
class SettingsController {

    public function index(): void {
        requireManager();

        $settingsModel = new PosSetting();
        $settings = $settingsModel->getAll();

        // Get locations for dropdown
        $db = getDB();
        $locations = $db->query('SELECT id, name FROM locations ORDER BY name')->fetchAll();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();

            $keys = [
                'shop_location_id', 'store_name', 'store_address',
                'store_phone', 'gst_number', 'pst_number',
                'print_service_url', 'receipt_footer',
                'standalone_refund_threshold',
                'moneris_api_token', 'moneris_store_id', 'moneris_ist_config_code',
                'usd_markup_percent',
            ];

            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    $settingsModel->set($key, trim($_POST[$key]));
                }
            }

            // Checkbox fields default to '0' when unchecked
            $checkboxKeys = ['moneris_enabled', 'moneris_sandbox'];
            foreach ($checkboxKeys as $key) {
                $settingsModel->set($key, isset($_POST[$key]) ? '1' : '0');
            }

            setFlash('success', 'Settings saved.');
            redirect('/settings');
            return;
        }

        require APP_PATH . '/views/admin/settings.php';
    }
}
