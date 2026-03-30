<?php
/**
 * POS application configuration.
 */

// Load .env
function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\"'");
        if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

loadEnv(dirname(__DIR__, 2) . '/.env');

// --- Timezone ---
date_default_timezone_set('Etc/GMT+7');

// --- Paths ---
define('BASE_PATH',    dirname(__DIR__, 2));
define('APP_PATH',     BASE_PATH . '/app');
define('PUBLIC_PATH',  BASE_PATH . '/public');
define('LOG_PATH',     BASE_PATH . '/logs/app.log');
define('UPLOAD_PATH',  BASE_PATH . '/public/uploads/pos');

// --- App ---
define('APP_NAME',    getenv('APP_NAME') ?: 'Granville Island Tea Co. POS');
define('APP_SECRET',  getenv('APP_SECRET') ?: 'changeme');
define('APP_ENV',     getenv('APP_ENV')    ?: 'production');

// --- Session ---
define('SESSION_TIMEOUT_DEFAULT', 480); // 8 hours for POS shifts
define('OPERATOR_TIMEOUT', 45);         // seconds of inactivity before operator cleared (client-side)
define('RECEIPT_REDIRECT_SECONDS', 5);  // seconds before auto-redirect on receipt page

// --- Security ---
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 60);

// --- Tax ---
define('TAX_GST_RATE', 0.05); // 5% GST
define('TAX_PST_RATE', 0.07); // 7% PST

// --- PrestaShop (gift card cross-DB) ---
define('PS_DB_NAME',   getenv('PS_DB_NAME') ?: '');
define('PS_DB_PREFIX', getenv('PS_DB_PREFIX') ?: 'ps_');

// --- Print service ---
define('PRINT_SERVICE_URL', 'http://localhost:5000');

// --- Report category groups ---
define('REPORT_CATEGORY_GROUPS', [
    'Loose Tea'   => ['Black Tea blends','Decaf Tea','Flavoured Black Tea','Flavoured Green Tea',
                       'Green Tea','Herbal Tea','Mate','Oolong Tea','Pu erh Tea',
                       'Rooibos Tea','Single Estate Black Tea','Tea Boxes','Tisane','White Tea'],
    'Accessories' => ['Accessories'],
    'Wholesale'   => ['Wholesale'],
    'Beverages'   => ['Beverages'],
]);

// --- Output helper ---
function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// --- Base URL ---
function baseUrl(string $path = ''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return "$scheme://$host$base/" . ltrim($path, '/');
}
