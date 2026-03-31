<?php
class ShiftController {

    public function open(): void {
        requireAuth();

        $shiftModel    = new Shift();
        $terminalModel = new Terminal();

        // Check if already has an open shift
        $user = currentUser();
        $existing = $shiftModel->getOpen($user['id']);
        if ($existing) {
            $_SESSION['pos_shift_id'] = $existing['id'];
            if ($existing['terminal_id']) {
                $_SESSION['pos_terminal_id'] = $existing['terminal_id'];
            }
            redirect('/');
            return;
        }

        $terminals = $terminalModel->getActive();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $openingFloat = round((float)($_POST['opening_float'] ?? 0), 2);
            $terminalId   = !empty($_POST['terminal_id']) ? (int)$_POST['terminal_id'] : null;

            // Validate terminal selection
            if ($terminalId) {
                $terminal = $terminalModel->findById($terminalId);
                if (!$terminal || !$terminal['is_active']) {
                    setFlash('error', 'Invalid terminal selected.');
                    require APP_PATH . '/views/shift/open.php';
                    return;
                }
                // Prevent two shifts on the same terminal
                if ($terminal && $shiftModel->getOpenForTerminal($terminalId)) {
                    setFlash('error', 'A shift is already open on "' . $terminal['name'] . '". Close it first.');
                    require APP_PATH . '/views/shift/open.php';
                    return;
                }
            }

            $shiftId = $shiftModel->open($user['id'], $openingFloat, $terminalId);
            $_SESSION['pos_shift_id'] = $shiftId;

            if ($terminalId) {
                $_SESSION['pos_terminal_id'] = $terminalId;
                // Set cookie for 30 days so this machine remembers its terminal
                setcookie('pos_terminal_id', (string)$terminalId, time() + (86400 * 30), '/');
            }

            setFlash('success', 'Shift opened.');
            redirect('/');
            return;
        }

        // Pre-select terminal from cookie
        $cookieTerminalId = !empty($_COOKIE['pos_terminal_id']) ? (int)$_COOKIE['pos_terminal_id'] : null;

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
            unset($_SESSION['pos_terminal_id']);
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

        // Load terminal name for display
        $terminalName = null;
        if ($shift && $shift['terminal_id']) {
            $terminal = (new Terminal())->findById($shift['terminal_id']);
            $terminalName = $terminal['name'] ?? null;
        }

        $txnModel     = new Transaction();
        $transactions = $txnModel->getForShift($shiftId);

        require APP_PATH . '/views/shift/report.php';
    }

    public function history(): void {
        requireAuth();
        requireManager();

        $terminalId = !empty($_GET['terminal_id']) ? (int)$_GET['terminal_id'] : null;
        $terminals  = (new Terminal())->getAll();
        $shifts     = (new Shift())->getHistory(50, $terminalId);

        require APP_PATH . '/views/shift/history.php';
    }
}
