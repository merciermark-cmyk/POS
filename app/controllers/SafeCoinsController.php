<?php
/**
 * SafeCoinsController — admin page for the safe coin ledger.
 *
 * Routes (all under /safe-coins, dispatched from index.php):
 *   GET  /safe-coins          → index page (panels)
 *   POST /safe-coins/add      → AJAX: insert ledger entry
 *
 * All routes 404 when FEATURE_SAFE_COIN_SYSTEM is off; nav link is hidden.
 */
class SafeCoinsController {

    private SafeCoinLedger $model;

    public function __construct() {
        $this->model = new SafeCoinLedger();
    }

    /** Render main page (Dollar Flow + Bag Inventory + Bank Transactions). */
    public function index(): void {
        requireManager();
        $runningBalance = $this->model->getRunningBalance();
        $byDenom        = $this->model->getBalanceByDenomination();
        $totalsByType   = $this->model->getTotalsByType();
        $recent         = $this->model->getEntries(null, null, 25);
        $bankTxns       = $this->model->getEntries(null, null, 100);

        $pageTitle = 'Safe Coins';
        ob_start();
        require APP_PATH . '/views/safe-coins/index.php';
        $content = ob_get_clean();
        $scripts = ['public/js/safe-coins.js'];
        require APP_PATH . '/views/layouts/admin.php';
    }

    /** AJAX: add a ledger entry. */
    public function add(): void {
        requireManager();
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        $token = $input['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!$expected || !hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
            return;
        }

        $type    = $input['type'] ?? '';
        $denom   = $input['denomination'] ?? '';
        $dollars = isset($input['dollars']) ? (float)$input['dollars'] : 0.0;
        $grams   = isset($input['grams']) && $input['grams'] !== '' ? (float)$input['grams'] : null;
        $note    = isset($input['note']) ? trim((string)$input['note']) : null;
        if ($note === '') $note = null;

        // Sign convention: form sends positive dollars; flip sign for bank_sell.
        if ($type === 'bank_sell') {
            $dollars = -abs($dollars);
        } elseif ($type === 'adjustment') {
            // Allow signed input for adjustments
            $dollars = (float)$dollars;
        } else {
            $dollars = abs($dollars);
        }

        if ($dollars === 0.0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Amount must be non-zero']);
            return;
        }

        $createdBy = $_SESSION['pos_user_id'] ?? null;

        try {
            $id = $this->model->addEntry($type, $denom, $dollars, $grams, $note, $createdBy);
            echo json_encode([
                'success' => true,
                'id' => $id,
                'balance' => $this->model->getRunningBalance(),
            ]);
        } catch (\Throwable $e) {
            error_log('SafeCoins add error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
}
