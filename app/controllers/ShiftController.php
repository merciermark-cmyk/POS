<?php
class ShiftController {

    public function open(): void {
        requireAuth();

        $shiftModel = new Shift();

        // Check if already has an open shift
        $user = currentUser();
        $existing = $shiftModel->getOpen($user['id']);
        if ($existing) {
            $_SESSION['pos_shift_id'] = $existing['id'];
            redirect('/');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $openingFloat = round((float)($_POST['opening_float'] ?? 0), 2);

            $shiftId = $shiftModel->open($user['id'], $openingFloat);
            $_SESSION['pos_shift_id'] = $shiftId;

            setFlash('success', 'Shift opened.');
            redirect('/');
            return;
        }

        require APP_PATH . '/views/shift/open.php';
    }

    public function close(): void {
        requireAuth();

        if (empty($_SESSION['pos_shift_id'])) {
            setFlash('error', 'No open shift.');
            redirect('/');
            return;
        }

        $shiftModel = new Shift();
        $shiftId    = $_SESSION['pos_shift_id'];
        $shift      = $shiftModel->findById($shiftId);
        $summary    = $shiftModel->getShiftSummary($shiftId);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $closingCash = round((float)($_POST['closing_cash'] ?? 0), 2);
            $notes       = trim($_POST['notes'] ?? '');

            $result = $shiftModel->close($shiftId, $closingCash, $notes);
            unset($_SESSION['pos_shift_id']);
            clearOperator();

            $_SESSION['closed_shift_result'] = $result;
            $_SESSION['closed_shift_id']     = $shiftId;

            redirect('/shift/report/' . $shiftId);
            return;
        }

        require APP_PATH . '/views/shift/close.php';
    }

    public function report(): void {
        requireAuth();

        $shiftId = (int)($_GET['id'] ?? $_SESSION['closed_shift_id'] ?? 0);
        unset($_SESSION['closed_shift_id']);

        $shiftModel = new Shift();
        $shift      = $shiftModel->findById($shiftId);
        $summary    = $shiftModel->getShiftSummary($shiftId);
        $result     = $_SESSION['closed_shift_result'] ?? null;
        unset($_SESSION['closed_shift_result']);

        $txnModel     = new Transaction();
        $transactions = $txnModel->getForShift($shiftId);

        require APP_PATH . '/views/shift/report.php';
    }

    public function history(): void {
        requireAuth();
        requireManager();

        $shifts = (new Shift())->getHistory();
        require APP_PATH . '/views/shift/history.php';
    }
}
