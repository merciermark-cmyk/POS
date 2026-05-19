<?php
class ShiftController {

    public function open(): void {
        requireAuth();

        $shiftModel    = new Shift();
        $terminalModel = new Terminal();
        $user          = currentUser();

        // Auto-rejoin: terminal has an open DB shift but session lost the binding
        // (Chrome restart wipes the PHPSESSID session-cookie while the 10-year
        // pos_terminal_id cookie survives — see [[pos-session-config]]). Restore
        // the session keys server-side and redirect to /sale so staff never see
        // the grey-badge dead end on /shift/open. Only on GET — POST means user
        // is actively trying to open a NEW shift.
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_SESSION['pos_shift_id'])) {
            $cookieTerminalId = (int)($_COOKIE['pos_terminal_id'] ?? 0);
            if ($cookieTerminalId > 0) {
                $openShift = $shiftModel->getOpenForTerminal($cookieTerminalId);
                if ($openShift) {
                    $_SESSION['pos_shift_id']    = (int)$openShift['id'];
                    $_SESSION['pos_terminal_id'] = $cookieTerminalId;
                    $shiftModel->updateHeartbeat((int)$openShift['id'], session_id());
                    redirect('/');
                    return;
                }
            }
        }

        // Block opening a second shift if one is already active in this session
        $existingShiftId = $_SESSION['pos_shift_id'] ?? null;
        if ($existingShiftId) {
            $existingShift = $shiftModel->findById((int)$existingShiftId);
            if ($existingShift && $existingShift['status'] === 'open') {
                $existingTerminal = $terminalModel->findById((int)$existingShift['terminal_id']);
                $terminalName = $existingTerminal['name'] ?? 'Unknown';
                setFlash('error', 'You already have a shift open on "' . $terminalName . '". Close it first or go to the terminal.');
                redirect('/');
                return;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $selectedTerminalId = (int)($_POST['terminal_id'] ?? 0);

            $terminal = $terminalModel->findById($selectedTerminalId);
            if (!$terminal || !$terminal['is_active']) {
                setFlash('error', 'Invalid terminal.');
                redirect('/shift/open');
                return;
            }

            if ($shiftModel->getOpenForTerminal($selectedTerminalId)) {
                setFlash('error', 'A shift is already open on "' . $terminal['name'] . '". Close it first.');
                redirect('/shift/open');
                return;
            }

            $openingFloat = round((float)($_POST['opening_float'] ?? 0), 2);
            $shiftId = $shiftModel->open($user['id'], $openingFloat, $selectedTerminalId);
            $_SESSION['pos_shift_id'] = $shiftId;
            $_SESSION['pos_terminal_id'] = $selectedTerminalId;
            $shiftModel->updateHeartbeat($shiftId, session_id());

            setFlash('success', 'Shift opened on ' . $terminal['name'] . '.');
            redirect('/');
            return;
        }

        // GET — build terminal list with open-shift status
        $terminals = $terminalModel->getForShifts();
        foreach ($terminals as &$t) {
            $openShift = $shiftModel->getOpenForTerminal($t['id']);
            $t['open_shift'] = $openShift;
            $t['has_open_shift'] = (bool)$openShift;
            $t['in_use'] = $openShift ? $shiftModel->isInUse($openShift['id'], session_id()) : false;
        }
        unset($t);

        // Pre-fill opening float from last DayClose
        $dcModel = new DayClose();
        $lastFloats = $dcModel->getLastFloatTotals() ?? [];

        require APP_PATH . '/views/shift/open.php';
    }

    public function rejoin(): void {
        requireAuth();
        $terminalId = (int)($_COOKIE['pos_terminal_id'] ?? 0);
        if ($terminalId <= 0) {
            setFlash('error', 'No terminal cookie on this device. Visit /set-terminal/{id} first.');
            redirect('/shift/open');
        }
        $shiftModel = new Shift();
        $shift = $shiftModel->getOpenForTerminal($terminalId);
        if (!$shift) {
            setFlash('error', "No open shift on terminal $terminalId. Use Open Shift instead.");
            redirect('/shift/open');
        }
        $_SESSION['pos_shift_id']    = (int)$shift['id'];
        $_SESSION['pos_terminal_id'] = $terminalId;
        $shiftModel->updateHeartbeat((int)$shift['id'], session_id());
        setFlash('success', "Rejoined shift #{$shift['id']}.");
        redirect('/');
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

        // Non-tracked product sales (track_inventory = 0)
        $nonTrackedSales = [];
        if ($shift) {
            $db = getDB();
            $stmt = $db->prepare(
                'SELECT p.name AS product_name, SUM(ti.quantity) AS qty,
                        SUM(ti.line_total) AS total
                 FROM pos_transaction_items ti
                 JOIN pos_transactions t ON t.id = ti.transaction_id
                 JOIN products p ON p.id = ti.product_id
                 WHERE t.shift_id = ? AND t.status = "completed"
                   AND p.track_inventory = 0
                 GROUP BY p.id, p.name
                 ORDER BY p.name'
            );
            $stmt->execute([$shiftId]);
            $nonTrackedSales = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        // Web sales (shipped orders from PrestaShop)
        $webSales = ['orders' => [], 'count' => 0, 'total' => 0.0];
        if (PS_DB_NAME && $shift) {
            $shiftDate = date('Y-m-d', strtotime($shift['opened_at']));
            $webSales  = (new WebOrder())->getSummaryForDate($shiftDate);
        }

        require APP_PATH . '/views/shift/report.php';
    }

    public function edit(): void {
        requireAuth();
        requireManager();

        $shiftId = (int)($_GET['id'] ?? 0);
        $shiftModel = new Shift();
        $shift = $shiftModel->findById($shiftId);

        if (!$shift) {
            setFlash('error', 'Shift not found.');
            redirect('/shift/history');
            return;
        }

        $summary = $shiftModel->getShiftSummary($shiftId);
        $terminalName = null;
        if ($shift['terminal_id']) {
            $terminal = (new Terminal())->findById($shift['terminal_id']);
            $terminalName = $terminal['name'] ?? null;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();

            $openingFloat = trim($_POST['opening_float'] ?? '') !== '' ? round((float)$_POST['opening_float'], 2) : null;
            $closingCard = trim($_POST['closing_card'] ?? '') !== '' ? round((float)$_POST['closing_card'], 2) : null;
            $closingTips = trim($_POST['closing_tips'] ?? '') !== '' ? round((float)$_POST['closing_tips'], 2) : null;
            $cashDeposit = trim($_POST['cash_deposit'] ?? '') !== '' ? round((float)$_POST['cash_deposit'], 2) : null;
            $notes       = trim($_POST['notes'] ?? '');

            // Recalculate card reconciliation (tips are included in terminal batch, so subtract them)
            $expectedCard  = null;
            $cardOverShort = null;
            if ($closingCard !== null) {
                $cardPayments = $shiftModel->getCardPaymentsTotal($shiftId);
                $cardRefunds  = $shiftModel->getCardRefundsTotal($shiftId);
                $standaloneCardRefunds = (new StandaloneRefund())->getCardRefundsTotal($shiftId);
                $gcCardTotal = (new GiftCardSale())->getCardTotal($shiftId);
                $expectedCard  = round($cardPayments - $cardRefunds - $standaloneCardRefunds + $gcCardTotal, 2);
                $tips = $closingTips ?? 0;
                $cardOverShort = round($closingCard - $expectedCard - $tips, 2);
            }

            $db = getDB();
            $sets = 'opening_float = ?, cash_deposit = ?, closing_card = ?, expected_card = ?, card_over_short = ?, closing_tips = ?, notes = ?';
            $params = [$openingFloat ?? $shift['opening_float'], $cashDeposit, $closingCard, $expectedCard, $cardOverShort, $closingTips, $notes, $shiftId];
            $stmt = $db->prepare("UPDATE pos_shifts SET $sets WHERE id = ?");
            $stmt->execute($params);

            setFlash('success', 'Shift updated.');
            redirect('/shift/report/' . $shiftId);
            return;
        }

        require APP_PATH . '/views/shift/edit.php';
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
