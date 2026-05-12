<?php
class AuthController {

    public function staffPicker(): void {
        // No shift in session — must pick a terminal first
        if (empty($_SESSION['pos_shift_id'])) {
            redirect('/shift/open');
            return;
        }

        $shiftModel = new Shift();
        $shift = $shiftModel->findById($_SESSION['pos_shift_id']);

        // Shift was closed or doesn't exist — pick a new terminal
        if (!$shift || $shift['status'] !== 'open') {
            unset($_SESSION['pos_shift_id'], $_SESSION['pos_terminal_id']);
            redirect('/shift/open');
            return;
        }

        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        $anyOpen = $terminalId ? $shiftModel->getOpenForTerminal($terminalId) : null;
        if ($anyOpen && $anyOpen['id'] == $shift['id']) {
            $shift['username'] = $anyOpen['username'];
        }

        $staff    = (new PosUser())->getActive();
        $settings = (new PosSetting())->getAll();
        $heldOrderCount = (new HeldOrder())->countActiveForShift($_SESSION['pos_shift_id']);

        require APP_PATH . '/views/auth/staff_picker.php';
    }

    public function pickStaff(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/');
            return;
        }
        verifyCsrfToken();

        $userId = (int)($_POST['user_id'] ?? 0);
        $userModel = new PosUser();
        $user = $userModel->findById($userId);

        if (!$user || !$user['is_active']) {
            setFlash('error', 'Invalid staff member.');
            redirect('/');
            return;
        }

        // Verify PIN if the user has one set
        $pin = trim($_POST['pin'] ?? '');
        if (!empty($user['pin'])) {
            if (!$userModel->verifyPin($userId, $pin)) {
                setFlash('error', 'Incorrect PIN.');
                redirect('/');
                return;
            }
        }

        $_SESSION['pos_operator_id']       = $user['id'];
        $_SESSION['pos_operator_username']  = $user['username'];
        $_SESSION['pos_operator_role']      = $user['role'];
        $_SESSION['operator_last_activity'] = time();
        unset($_SESSION['pos_cart']);

        redirect('/sale');
    }

    public function login(): void {
        redirect('/pin');
    }

    public function pinLogin(): void {
        if (isLoggedIn()) {
            redirect('/');
            return;
        }

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $pin = trim($_POST['pin'] ?? '');

            if (preg_match('/^\d{1,3}$/', $pin)) {
                $userModel = new PosUser();
                $user = $userModel->findByPin($pin);

                if ($user) {
                    $_SESSION['pos_user_id']       = $user['id'];
                    $_SESSION['pos_user_username']  = $user['username'];
                    $_SESSION['pos_user_role']      = $user['role'];
                    $_SESSION['last_activity']      = time();

                    // Also set as operator
                    $_SESSION['pos_operator_id']       = $user['id'];
                    $_SESSION['pos_operator_username']  = $user['username'];
                    $_SESSION['pos_operator_role']      = $user['role'];
                    $_SESSION['operator_last_activity'] = time();

                    // Redirect to saved destination or terminal picker
                    $returnTo = $_SESSION['pos_redirect_after_login'] ?? null;
                    unset($_SESSION['pos_redirect_after_login']);
                    if ($returnTo) {
                        redirect($returnTo);
                    } else {
                        redirect('/shift/open');
                    }
                    return;
                }

                $error = 'Invalid PIN.';
            }
        }

        require APP_PATH . '/views/auth/pin.php';
    }

    public function logout(): void {
        session_unset();
        session_destroy();
        session_start();

        setFlash('success', 'You have been logged out.');
        redirect('/pin');
    }

    public function lock(): void {
        session_unset();
        session_start();
        redirect('/pin');
    }
}
