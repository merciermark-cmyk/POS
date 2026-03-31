<?php
class AuthController {

    public function staffPicker(): void {
        $shiftModel = new Shift();

        // Adopt open shift for this terminal (cookie-based) or any open shift
        if (empty($_SESSION['pos_shift_id'])) {
            $terminalId = !empty($_COOKIE['pos_terminal_id']) ? (int)$_COOKIE['pos_terminal_id'] : null;
            $openShift = $terminalId
                ? ($shiftModel->getOpenForTerminal($terminalId) ?: $shiftModel->getAnyOpen())
                : $shiftModel->getAnyOpen();
            if ($openShift) {
                $_SESSION['pos_shift_id'] = $openShift['id'];
                if ($openShift['terminal_id']) {
                    $_SESSION['pos_terminal_id'] = $openShift['terminal_id'];
                }
            }
        }

        // No shift open — must login and open one
        if (empty($_SESSION['pos_shift_id'])) {
            setFlash('error', 'No shift is open. A manager must open a shift first.');
            redirect('/login');
            return;
        }

        $shift    = $shiftModel->findById($_SESSION['pos_shift_id']);
        // Attach username from the shift opener
        $terminalId = !empty($_COOKIE['pos_terminal_id']) ? (int)$_COOKIE['pos_terminal_id'] : null;
        $anyOpen = $terminalId
            ? ($shiftModel->getOpenForTerminal($terminalId) ?: $shiftModel->getAnyOpen())
            : $shiftModel->getAnyOpen();
        if ($anyOpen && $anyOpen['id'] == $shift['id']) {
            $shift['username'] = $anyOpen['username'];
        }

        $staff    = (new PosUser())->getActive();
        $settings = (new PosSetting())->getAll();

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

        // Verify staff code if the user has one set
        $staffCode = trim($_POST['staff_code'] ?? '');
        if (!empty($user['staff_code'])) {
            if (!$userModel->verifyStaffCode($userId, $staffCode)) {
                setFlash('error', 'Incorrect staff code.');
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
        if (isLoggedIn()) {
            redirect('/');
            return;
        }

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            verifyCsrfToken();
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            $userModel = new PosUser();
            $user = $userModel->findByUsername($username);

            if ($user && $userModel->verifyPassword($user, $password)) {
                $_SESSION['pos_user_id']       = $user['id'];
                $_SESSION['pos_user_username']  = $user['username'];
                $_SESSION['pos_user_role']      = $user['role'];
                $_SESSION['last_activity']      = time();

                // Also set as operator so manager can ring sales immediately
                $_SESSION['pos_operator_id']       = $user['id'];
                $_SESSION['pos_operator_username']  = $user['username'];
                $_SESSION['pos_operator_role']      = $user['role'];
                $_SESSION['operator_last_activity'] = time();

                // Adopt open shift for this terminal (cookie) or any open shift
                $shiftModel = new Shift();
                $terminalId = !empty($_COOKIE['pos_terminal_id']) ? (int)$_COOKIE['pos_terminal_id'] : null;
                $shift = $terminalId
                    ? ($shiftModel->getOpenForTerminal($terminalId) ?: $shiftModel->getAnyOpen())
                    : $shiftModel->getAnyOpen();
                if ($shift) {
                    $_SESSION['pos_shift_id'] = $shift['id'];
                    if ($shift['terminal_id']) {
                        $_SESSION['pos_terminal_id'] = $shift['terminal_id'];
                    }
                }

                redirect('/');
                return;
            }

            $error = 'Invalid username or password.';
        }

        require APP_PATH . '/views/auth/login.php';
    }

    public function pinLogin(): void {
        if (isLoggedIn()) {
            redirect('/');
            return;
        }

        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $pin = trim($_POST['pin'] ?? '');

            if (preg_match('/^\d{4}$/', $pin)) {
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

                    // Adopt open shift for this terminal (cookie) or any open shift
                    $shiftModel = new Shift();
                    $terminalId = !empty($_COOKIE['pos_terminal_id']) ? (int)$_COOKIE['pos_terminal_id'] : null;
                    $shift = $terminalId
                        ? ($shiftModel->getOpenForTerminal($terminalId) ?: $shiftModel->getAnyOpen())
                        : $shiftModel->getAnyOpen();
                    if ($shift) {
                        $_SESSION['pos_shift_id'] = $shift['id'];
                        if ($shift['terminal_id']) {
                            $_SESSION['pos_terminal_id'] = $shift['terminal_id'];
                        }
                    }

                    redirect('/');
                    return;
                }

                $error = 'Invalid PIN.';
            }
        }

        require APP_PATH . '/views/auth/pin.php';
    }

    public function logout(): void {
        // Preserve shift + terminal so staff picker still works
        $shiftId    = $_SESSION['pos_shift_id'] ?? null;
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;

        session_unset();
        session_destroy();
        session_start();

        if ($shiftId) {
            $_SESSION['pos_shift_id'] = $shiftId;
        }
        if ($terminalId) {
            $_SESSION['pos_terminal_id'] = $terminalId;
        }

        setFlash('success', 'You have been logged out.');
        redirect('/');
    }

    public function lock(): void {
        // Keep shift + terminal info but require re-auth
        $shiftId    = $_SESSION['pos_shift_id'] ?? null;
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        $userId     = $_SESSION['pos_user_id'] ?? null;

        session_unset();
        session_start();

        if ($shiftId) {
            $_SESSION['locked_shift_id'] = $shiftId;
            $_SESSION['locked_user_id']  = $userId;
        }
        if ($terminalId) {
            $_SESSION['pos_terminal_id'] = $terminalId;
        }

        redirect('/pin');
    }
}
