<?php
/**
 * DayClose controller — embedded in POS app.
 */
class DayCloseController {

    private DayClose $model;

    public function __construct() {
        $this->model = new DayClose();
    }

    /** Entry page — date picker + staff dropdown */
    public function index(): void {
        requireAuth();
        $userModel = new PosUser();
        $staff = $userModel->getActive();

        $pageTitle = 'Day Close';
        ob_start();
        require APP_PATH . '/views/dayclose/index.php';
        $content = ob_get_clean();
        $scripts = ['public/js/dayclose.js', 'public/js/dayclose-poll.js'];
        require APP_PATH . '/views/layouts/admin.php';
    }

    /** Count form — acquires lock */
    public function count(): void {
        requireAuth();
        $date    = trim($_GET['date'] ?? '');
        $staffId = (int)($_GET['staff'] ?? 0);

        if (!$date || !$staffId) {
            setFlash('error', 'Date and staff are required.');
            redirect('/dayclose');
            return;
        }

        // Resolve staff name
        $userModel = new PosUser();
        $staffUser = $userModel->findById($staffId);
        $staffName = $staffUser['username'] ?? 'Unknown';

        $sessionId = session_id();

        // Check lock
        $lock = $this->model->getLockInfo($date);
        if ($lock && $lock['lock_session'] !== $sessionId) {
            $pageTitle = 'Day Close — Locked';
            $lockerName = $lock['locker_name'] ?? 'Someone';
            $lockedAt = $lock['locked_at'];
            ob_start();
            require APP_PATH . '/views/dayclose/locked.php';
            $content = ob_get_clean();
            $scripts = ['public/js/dayclose-poll.js'];
            require APP_PATH . '/views/layouts/admin.php';
            return;
        }

        // Acquire lock
        $userId = $_SESSION['pos_user_id'] ?? $_SESSION['pos_operator_id'] ?? $staffId;
        if (!$this->model->acquireLock($date, (int)$userId, $sessionId)) {
            setFlash('error', 'Could not acquire lock. Another session may be active.');
            redirect('/dayclose');
            return;
        }

        // Check for existing count (prefill)
        $prefill = null;
        $existing = $this->model->getCountByDate($date);
        if ($existing && in_array($existing['status'], ['completed', 'incomplete'])) {
            $details = $this->model->getCountDetails((int)$existing['id']);
            $floats  = $this->model->getCountFloats((int)$existing['id']);
            $prefill = $this->model->buildPrefillData($existing, $details, $floats);
        }

        $pageTitle = 'Day Close — Count';
        ob_start();
        require APP_PATH . '/views/dayclose/count.php';
        $content = ob_get_clean();
        $scripts = ['public/js/dayclose.js', 'public/js/dayclose-poll.js'];
        require APP_PATH . '/views/layouts/admin.php';
    }

    /** Read-only summary after save */
    public function summary(): void {
        requireAuth();
        $date = trim($_GET['date'] ?? '');
        if (!$date) { redirect('/dayclose'); return; }

        $count = $this->model->getCountByDate($date);
        if (!$count || $count['status'] !== 'completed') {
            setFlash('error', 'No completed count found for ' . $date);
            redirect('/dayclose');
            return;
        }

        $details = $this->model->getCountDetails((int)$count['id']);
        $floats  = $this->model->getCountFloats((int)$count['id']);
        $shifts  = $this->model->getShiftReconciliation($date);

        $pageTitle = 'Day Close Summary — ' . $date;
        ob_start();
        require APP_PATH . '/views/dayclose/summary.php';
        $content = ob_get_clean();
        $scripts = ['public/js/dayclose-poll.js'];
        require APP_PATH . '/views/layouts/admin.php';
    }

    /** History table */
    public function history(): void {
        requireAuth();
        $from = trim($_GET['from'] ?? date('Y-m-01'));
        $to   = trim($_GET['to']   ?? date('Y-m-d'));
        $counts = $this->model->getCountsByRange($from, $to);

        $pageTitle = 'Day Close History';
        ob_start();
        require APP_PATH . '/views/dayclose/history.php';
        $content = ob_get_clean();
        $scripts = ['public/js/dayclose-poll.js'];
        require APP_PATH . '/views/layouts/admin.php';
    }

    /** AJAX: check if count exists for date */
    public function checkDate(): void {
        requireAuth();
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        $date = trim($input['date'] ?? '');

        if (!$date) {
            echo json_encode(['exists' => false]);
            return;
        }

        $count = $this->model->getCountByDate($date);
        $lock  = $this->model->getLockInfo($date);

        // Report as "exists" if completed or incomplete (not just a lock placeholder)
        $hasData = $count && in_array($count['status'] ?? '', ['completed', 'incomplete']);

        echo json_encode([
            'exists'     => $hasData,
            'status'     => $count['status'] ?? null,
            'closed_by'  => $count['closed_by'] ?? null,
            'staff_name' => $count['staff_name'] ?? null,
            'locked'     => (bool)$lock,
            'locker'     => $lock['locker_name'] ?? null,
        ]);
    }

    /** AJAX: full save */
    public function save(): void {
        requireAuth();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        // Verify CSRF
        $token = $input['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!$expected || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
            return;
        }

        // Server-side recalculate coin amounts
        $details = $input['details'] ?? [];
        foreach ($details as &$d) {
            if ($d['denomination_type'] === 'coin') {
                $calc = $this->model->coinCalc((float)$d['value'], $d['denomination']);
                $d['calculated_amount'] = $calc['value'];
            }
        }
        unset($d);
        $input['details'] = $details;

        $complete = !empty($input['complete']);

        try {
            $countId = $this->model->saveCount($input, $complete);
            echo json_encode([
                'success'  => true,
                'count_id' => $countId,
                'complete' => $complete,
            ]);
        } catch (\Throwable $e) {
            error_log('DayClose save error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Save failed: ' . $e->getMessage()]);
        }
    }

    /** AJAX: refresh lock timestamp */
    public function heartbeat(): void {
        requireAuth();
        header('Content-Type: application/json');
        $date = trim($_GET['date'] ?? '');
        $sessionId = session_id();
        if ($date) {
            $this->model->heartbeatLock($date, $sessionId);
        }
        echo json_encode(['ok' => true]);
    }

    /** AJAX: release lock on navigate away */
    public function releaseLock(): void {
        requireAuth();
        header('Content-Type: application/json');
        $date = trim($_GET['date'] ?? '');
        $sessionId = session_id();
        if ($date) {
            $this->model->releaseLock($date, $sessionId);
        }
        echo json_encode(['ok' => true]);
    }
}
