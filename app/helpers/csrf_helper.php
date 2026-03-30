<?php
/**
 * CSRF token generation and verification.
 */

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

function verifyCsrfToken(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }
}
