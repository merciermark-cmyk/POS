<?php
/**
 * POS authentication helpers.
 */

function requireAuth(): void {
    if (empty($_SESSION['pos_user_id']) && empty($_SESSION['pos_operator_id'])) {
        // Preserve intended destination through login flow
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        if ($base) {
            $uri = substr($uri, strlen($base));
        }
        if ($uri && $uri !== '/' && $uri !== '/login' && $uri !== '/pin') {
            $_SESSION['pos_redirect_after_login'] = $uri;
        }
        redirect('/login');
    }
}

function requireManager(): void {
    requireAuth();
    if (!isManager()) {
        setFlash('error', 'Access denied. Manager privileges required.');
        redirect('/');
    }
}

function requireShift(): void {
    requireAuth();
    if (empty($_SESSION['pos_shift_id'])) {
        redirect('/shift/open');
    }
}

function currentUser(): array {
    return [
        'id'       => $_SESSION['pos_user_id']       ?? 0,
        'username' => $_SESSION['pos_user_username']  ?? '',
        'role'     => $_SESSION['pos_user_role']      ?? '',
    ];
}

function isManager(): bool {
    $role = $_SESSION['pos_user_role'] ?? $_SESSION['pos_operator_role'] ?? '';
    return $role === ROLE_MANAGER;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['pos_user_id']) || !empty($_SESSION['pos_operator_id']);
}

// ── Operator helpers ─────────────────────────────────────────────────────────

function hasOperator(): bool {
    return !empty($_SESSION['pos_operator_id']) || !empty($_SESSION['pos_user_id']);
}

function hasOpenShift(): bool {
    return !empty($_SESSION['pos_shift_id']);
}

function requireOperator(): void {
    if (!hasOperator()) {
        redirect('/');
    }
}

function currentOperator(): array {
    if (!empty($_SESSION['pos_operator_id'])) {
        return [
            'id'       => $_SESSION['pos_operator_id'],
            'username' => $_SESSION['pos_operator_username'] ?? '',
            'role'     => $_SESSION['pos_operator_role'] ?? '',
        ];
    }
    return currentUser();
}

function clearOperator(): void {
    unset(
        $_SESSION['pos_operator_id'],
        $_SESSION['pos_operator_username'],
        $_SESSION['pos_operator_role'],
        $_SESSION['pos_user_id'],
        $_SESSION['pos_user_username'],
        $_SESSION['pos_user_role'],
        $_SESSION['pos_cart'],
        $_SESSION['pos_wholesale'],
        $_SESSION['pos_cart_discount']
    );
    unset($_SESSION['operator_last_activity']);
    unset($_SESSION['last_activity']);
}

/** Flash message helpers */
function setFlash(string $key, string $message): void {
    $_SESSION['flash_' . $key] = $message;
}

function getFlash(string $key): ?string {
    $k = 'flash_' . $key;
    if (isset($_SESSION[$k])) {
        $msg = $_SESSION[$k];
        unset($_SESSION[$k]);
        return $msg;
    }
    return null;
}
