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
require_once APP_PATH . '/models/StandaloneRefund.php';
require_once APP_PATH . '/models/PettyCash.php';
require_once APP_PATH . '/models/TempAuth.php';
require_once APP_PATH . '/models/WebOrder.php';
require_once APP_PATH . '/models/HeldOrder.php';
require_once APP_PATH . '/models/Moneris.php';
require_once APP_PATH . '/models/GiftCardSale.php';
require_once APP_PATH . '/models/ScheduleAttendance.php';
require_once APP_PATH . '/models/DayClose.php';

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
require_once APP_PATH . '/controllers/ProductController.php';
require_once APP_PATH . '/controllers/TerminalController.php';
require_once APP_PATH . '/controllers/ManualEntryController.php';
require_once APP_PATH . '/controllers/TempAuthController.php';
require_once APP_PATH . '/controllers/ApiController.php';
require_once APP_PATH . '/controllers/DayCloseController.php';

// ── Session ───────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', SESSION_TIMEOUT_DEFAULT * 60); // match POS timeout (8 hr)
    session_start();
}

// Session inactivity timeout (manager session — 8 hr)
if (isset($_SESSION['pos_user_id'])) {
    $lastActivity = $_SESSION['last_activity'] ?? time();
    if ((time() - $lastActivity) > (SESSION_TIMEOUT_DEFAULT * 60)) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash_error'] = 'Your session has expired. Please log in again.';
        redirect('/pin');
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
                'report'  => (function() use ($sc, $seg2) { $_GET['id'] = $seg2; $sc->report(); })(),
                'edit'    => (function() use ($sc, $seg2) { $_GET['id'] = $seg2; $sc->edit(); })(),
                'history' => $sc->history(),
                default   => redirect('/shift/open'),
            };
            break;

        // Terminal binding for a physical POS machine. Sets cookie and redirects to /.
        // Safe to call on every boot — cookie just gets renewed. Kiosk URL can be this.
        case 'set-terminal':
            $tid = (int)$seg1;
            if ($tid > 0) {
                setcookie('pos_terminal_id', (string)$tid, time() + (86400 * 365 * 10), '/');
                header('Location: /');
                exit;
            }
            echo '<h1>Usage: /set-terminal/{id}</h1>';
            break;

        // Transactions
        case 'transactions':
            $tc = new TransactionController();
            match ($seg1) {
                'view'           => (function() use ($tc, $seg2) { $_GET['id'] = $seg2; $tc->view(); })(),
                'void'           => $tc->void(),
                'refund'         => $tc->refund(),
                'refund-receipt' => (function() use ($tc, $seg2) { $_GET['id'] = $seg2; $tc->refundReceipt(); })(),
                'change-payment' => $tc->changePayment(),
                default          => $tc->index(),
            };
            break;

        // Reports
        case 'reports':
            $rc = new ReportController();
            match ($seg1) {
                'daily'              => $rc->daily(),
                'monthly'            => $rc->monthly(),
                'product-sales'      => $rc->productSales(),
                'transaction-search' => $rc->transactionSearch(),
                'hourly-sales'       => $rc->hourlySales(),
                'cash-spot-check'    => $rc->cashSpotCheck(),
                default              => $rc->daily(),
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

        // Products (admin — price editor)
        case 'products':
            $pc = new ProductController();
            match ($seg1) {
                'update-price'           => $pc->updatePrice(),
                'update-wholesale-price' => $pc->updateWholesalePrice(),
                'toggle-visibility'      => $pc->toggleVisibility(),
                default             => $pc->index(),
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

        // Remote Auth (temp authorization codes)
        case 'remote-auth':
            $tac = new TempAuthController();
            match ($seg1) {
                'generate' => $tac->generate(),
                default    => $tac->dashboard(),
            };
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

        // Session keep-alive (no auth required — just prevents PHP GC)
        case 'keep-alive':
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            break;

        // DayClose status check (no auth — polled by POS stations)
        case 'dayclose-status':
            header('Content-Type: application/json');
            $terminalId = (int)($_COOKIE['pos_terminal_id'] ?? 0);
            if (!$terminalId) {
                echo json_encode([
                    'dayclose_complete' => false,
                    'shift_open' => false,
                    'needs_close' => false,
                ]);
                break;
            }
            $today = date('Y-m-d');
            $dc = (new DayClose())->getCountByDate($today);
            $complete = $dc && $dc['status'] === 'completed';
            $shiftModel = new Shift();
            $shift = $shiftModel->getOpenForTerminal($terminalId);

            // needs_close: definitive server-side signal that this POS session
            // still references a shift closed today and dayclose is complete.
            // Bypasses the client-side sawShiftOpen race when the page was
            // loaded (or reloaded) after Save & Complete already closed shifts.
            $needsClose = false;
            $sessionShiftId = (int)($_SESSION['pos_shift_id'] ?? 0);
            if ($complete && !$shift && $sessionShiftId) {
                $sessionShift = $shiftModel->findById($sessionShiftId);
                if ($sessionShift
                    && (int)$sessionShift['terminal_id'] === $terminalId
                    && $sessionShift['status'] === 'closed'
                    && !empty($sessionShift['closed_at'])
                    && substr((string)$sessionShift['closed_at'], 0, 10) === $today) {
                    $needsClose = true;
                }
            }

            echo json_encode([
                'dayclose_complete' => $complete,
                'shift_open'        => (bool)$shift,
                'shift_id'          => $shift ? (int)$shift['id'] : ($needsClose ? $sessionShiftId : null),
                'needs_close'       => $needsClose,
            ]);
            break;

        // DayClose register close (no auth — called from POS overlay)
        case 'dayclose-close':
            header('Content-Type: application/json');
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['success' => false, 'error' => 'POST required']);
                break;
            }
            $shiftModel = new Shift();
            $terminalId = (int)($_COOKIE['pos_terminal_id'] ?? 0);
            if ($terminalId) {
                $shift = $shiftModel->getOpenForTerminal($terminalId);
                if ($shift) {
                    $closedBy = $_SESSION['pos_operator_id'] ?? $_SESSION['pos_user_id'] ?? null;
                    $db = getDB();
                    $db->prepare("UPDATE pos_shifts SET status = 'closed', closed_at = NOW(), closed_by = ? WHERE id = ? AND status = 'open'")
                       ->execute([$closedBy, (int)$shift['id']]);
                    $shiftModel->clearHeartbeat((int)$shift['id']);
                    (new HeldOrder())->expireForShift((int)$shift['id']);
                }
            }
            unset($_SESSION['pos_shift_id'], $_SESSION['pos_terminal_id']);
            clearOperator();
            echo json_encode(['success' => true]);
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
                'verify-manager-pin/' => $api->verifyManagerPin(),
                'standalone-refund/'  => $api->standaloneRefund(),
                'pole-display/'    => $api->poleDisplay(),
                'petty-cash/add'   => $api->pettyCashAdd(),
                'petty-cash/list'  => $api->pettyCashList(),
                'temp-auth/verify' => $api->verifyTempAuth(),
                'temp-auth/generate' => $api->generateTempAuth(),
                'hold/save'    => $api->holdSave(),
                'hold/list'    => $api->holdList(),
                'hold/resume'  => $api->holdResume(),
                'hold/delete'  => $api->holdDelete(),
                'hold/count'   => $api->holdCount(),
                'moneris/purchase' => $api->monerisPurchase(),
                'moneris/void'     => $api->monerisVoid(),
                'moneris/status'   => $api->monerisStatus(),
                'gift-card-sales/add'  => $api->giftCardSalesAdd(),
                'gift-card-sales/list' => $api->giftCardSalesList(),
                'currency/usd'         => $api->currencyUsd(),
                'heartbeat/'           => $api->heartbeat(),
                default            => (function() {
                    http_response_code(404);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Not found']);
                })(),
            };
            break;

        // DayClose (embedded cash counting)
        case 'dayclose':
            $dcc = new DayCloseController();
            match ($seg1) {
                'count'        => $dcc->count(),
                'summary'      => $dcc->summary(),
                'history'      => $dcc->history(),
                'check-date'   => $dcc->checkDate(),
                'save'         => $dcc->save(),
                'heartbeat'    => $dcc->heartbeat(),
                'release-lock' => $dcc->releaseLock(),
                default        => $dcc->index(),
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
