<?php
declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/app/config/config.php';
require_once __DIR__ . '/app/config/database.php';
require_once __DIR__ . '/app/config/permissions.php';

// Models
require_once APP_PATH . '/models/BaseModel.php';
require_once APP_PATH . '/models/PosUser.php';
require_once APP_PATH . '/models/Shift.php';
require_once APP_PATH . '/models/Transaction.php';
require_once APP_PATH . '/models/Product.php';
require_once APP_PATH . '/models/Category.php';
require_once APP_PATH . '/models/Inventory.php';
require_once APP_PATH . '/models/AuditLog.php';
require_once APP_PATH . '/models/ProductImage.php';
require_once APP_PATH . '/models/GiftCard.php';
require_once APP_PATH . '/models/Modifier.php';
require_once APP_PATH . '/models/Terminal.php';
require_once APP_PATH . '/models/PosSetting.php';

// Helpers
require_once APP_PATH . '/helpers/csrf_helper.php';
require_once APP_PATH . '/helpers/auth_helper.php';
require_once APP_PATH . '/helpers/tax_helper.php';

// Controllers
require_once APP_PATH . '/controllers/AuthController.php';
require_once APP_PATH . '/controllers/SaleController.php';
require_once APP_PATH . '/controllers/ShiftController.php';
require_once APP_PATH . '/controllers/TransactionController.php';
require_once APP_PATH . '/controllers/ReportController.php';
require_once APP_PATH . '/controllers/UserController.php';
require_once APP_PATH . '/controllers/SettingsController.php';
require_once APP_PATH . '/controllers/ImageController.php';
require_once APP_PATH . '/controllers/ModifierController.php';
require_once APP_PATH . '/controllers/TerminalController.php';
require_once APP_PATH . '/controllers/ManualEntryController.php';
require_once APP_PATH . '/controllers/ApiController.php';

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session inactivity timeout (manager session — 8 hr)
if (isset($_SESSION['pos_user_id'])) {
    $lastActivity = $_SESSION['last_activity'] ?? time();
    if ((time() - $lastActivity) > (SESSION_TIMEOUT_DEFAULT * 60)) {
        $shiftId    = $_SESSION['pos_shift_id'] ?? null;
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        session_unset();
        session_destroy();
        session_start();
        if ($shiftId) {
            $_SESSION['locked_shift_id'] = $shiftId;
        }
        if ($terminalId) {
            $_SESSION['pos_terminal_id'] = $terminalId;
        }
        $_SESSION['flash_error'] = 'Your session has expired. Please log in again.';
        redirect('/login');
    }
    $_SESSION['last_activity'] = time();
}

// Operator timeout is now handled client-side (idle-timer.js)

// ── Security headers ─────────────────────────────────────────────────────────
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── Router ────────────────────────────────────────────────────────────────────
$url = $_GET['url'] ?? '';
$url = rtrim($url, '/');
$url = filter_var($url, FILTER_SANITIZE_URL);

function dispatch(string $url): void {
    $url   = rtrim($url, '/');
    $parts = array_filter(explode('/', $url), fn($s) => $s !== '');
    $parts = array_values($parts);

    $seg0 = $parts[0] ?? '';
    $seg1 = $parts[1] ?? '';
    $seg2 = $parts[2] ?? '';

    switch ($seg0) {
        case '':
            // Staff picker if no operator; redirect to terminal if operator active
            if (hasOperator()) {
                redirect('/sale');
            } else {
                (new AuthController())->staffPicker();
            }
            break;

        case 'sale':
            if ($seg1 === 'complete') {
                (new SaleController())->complete();
            } elseif ($seg1 === 'receipt') {
                $_GET['id'] = $seg2;
                (new SaleController())->receipt();
            } else {
                (new SaleController())->terminal();
            }
            break;

        // Staff picker
        case 'pick-staff':
            (new AuthController())->pickStaff();
            break;
        case 'switch-user':
            clearOperator();
            redirect('/');
            break;
        case 'next-customer':
            // Clear cart + wholesale + discount but keep current operator
            unset($_SESSION['pos_cart']);
            unset($_SESSION['pos_wholesale']);
            unset($_SESSION['pos_cart_discount']);
            redirect('/sale');
            break;

        // Auth
        case 'login':
            (new AuthController())->login();
            break;
        case 'pin':
            (new AuthController())->pinLogin();
            break;
        case 'logout':
            (new AuthController())->logout();
            break;
        case 'lock':
            (new AuthController())->lock();
            break;

        // Shifts
        case 'shift':
            $sc = new ShiftController();
            match ($seg1) {
                'open'    => $sc->open(),
                'close'   => $sc->close(),
                'report'  => (function() use ($sc, $seg2) { $_GET['id'] = $seg2; $sc->report(); })(),
                'history' => $sc->history(),
                default   => redirect('/shift/open'),
            };
            break;

        // Transactions
        case 'transactions':
            $tc = new TransactionController();
            match ($seg1) {
                'view'           => (function() use ($tc, $seg2) { $_GET['id'] = $seg2; $tc->view(); })(),
                'void'           => $tc->void(),
                'refund'         => $tc->refund(),
                'refund-receipt' => (function() use ($tc, $seg2) { $_GET['id'] = $seg2; $tc->refundReceipt(); })(),
                default          => $tc->index(),
            };
            break;

        // Reports
        case 'reports':
            $rc = new ReportController();
            match ($seg1) {
                'daily'         => $rc->daily(),
                'monthly'       => $rc->monthly(),
                'product-sales' => $rc->productSales(),
                default         => $rc->daily(),
            };
            break;

        // Users (admin)
        case 'users':
            $uc = new UserController();
            match ($seg1) {
                'create' => $uc->create(),
                'edit'   => $uc->edit((int)$seg2),
                'delete' => $uc->delete((int)$seg2),
                default  => $uc->index(),
            };
            break;

        // Modifiers (admin)
        case 'modifiers':
            $mc = new ModifierController();
            match ($seg1) {
                'create' => $mc->create(),
                'edit'   => $mc->edit((int)$seg2),
                'delete' => $mc->delete((int)$seg2),
                default  => $mc->index(),
            };
            break;

        // Terminals (admin)
        case 'terminals':
            $tc = new TerminalController();
            match ($seg1) {
                'create' => $tc->create(),
                'edit'   => $tc->edit((int)$seg2),
                'delete' => $tc->delete((int)$seg2),
                default  => $tc->index(),
            };
            break;

        // Manual Entry
        case 'manual-entry':
            $mc = new ManualEntryController();
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $mc->save();
            } else {
                $mc->form();
            }
            break;

        // Settings
        case 'settings':
            (new SettingsController())->index();
            break;

        // Images
        case 'images':
            $ic = new ImageController();
            match ($seg1) {
                'upload' => $ic->upload(),
                'delete' => $ic->delete(),
                default  => $ic->index(),
            };
            break;

        // API (JSON endpoints)
        case 'api':
            $api = new ApiController();
            match ("$seg1/$seg2") {
                'products/'        => $api->products(),
                'cart/add'         => $api->cartAdd(),
                'cart/update'      => $api->cartUpdate(),
                'cart/remove'      => $api->cartRemove(),
                'cart/clear'       => $api->cartClear(),
                'wholesale/toggle' => $api->wholesaleToggle(),
                'discount/toggle'  => $api->discountToggle(),
                'discount/item'    => $api->discountItem(),
                'gift-card/check'  => $api->giftCardCheck(),
                'print/receipt'    => $api->printReceipt(),
                'print/open-drawer'=> $api->printOpenDrawer(),
                'pole-display/'    => $api->poleDisplay(),
                default            => (function() {
                    http_response_code(404);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Not found']);
                })(),
            };
            break;

        default:
            http_response_code(404);
            echo '<h1>404 Not Found</h1><p><a href="/">Go home</a></p>';
    }
}

function redirect(string $path): never {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    header('Location: ' . $base . $path);
    exit;
}

dispatch($url);
