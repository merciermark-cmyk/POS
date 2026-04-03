<?php
class TempAuthController {

    /** GET /remote-auth — Manager dashboard for generating codes */
    public function dashboard(): void {
        requireAuth();
        requireManager();

        $recentCodes = (new TempAuth())->getRecent(20);

        $pageTitle = 'Remote Authorization';
        ob_start();
        require APP_PATH . '/views/temp_auth/dashboard.php';
        $content = ob_get_clean();
        require APP_PATH . '/views/layouts/admin.php';
    }

    /** POST /remote-auth/generate — AJAX code generation */
    public function generate(): void {
        requireAuth();
        requireManager();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/remote-auth');
            return;
        }

        verifyCsrfToken();

        $user = currentUser();
        $result = (new TempAuth())->generate($user['id']);

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}
