<?php
/**
 * POS authentication helpers.
 */

function requireAuth(): void {
    if (empty($_SESSION['pos_user_id'])) {
        redirect('/login');
    }
}

function requireManager(): void {
    requireAuth();
    if (($_SESSION['pos_user_role'] ?? '') !== ROLE_MANAGER) {
        http_response_code(403);
        die('Access denied. Manager privileges required.');
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
    return ($_SESSION['pos_user_role'] ?? '') === ROLE_MANAGER;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['pos_user_id']);
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
        $_SESSION['pos_cart']
    );
    unset($_SESSION['operator_last_activity']);
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
