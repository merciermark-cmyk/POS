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
            ];

            foreach ($keys as $key) {
                if (isset($_POST[$key])) {
                    $settingsModel->set($key, trim($_POST[$key]));
                }
            }

            setFlash('success', 'Settings saved.');
            redirect('/settings');
            return;
        }

        require APP_PATH . '/views/admin/settings.php';
    }
}
