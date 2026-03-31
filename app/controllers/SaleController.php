<?php
class SaleController {

    public function terminal(): void {
        requireOperator();
        requireShift();

        $categories = (new Category())->getAll();
        $products   = (new Product())->getAll();
        $cart         = $_SESSION['pos_cart'] ?? [];
        $wholesale    = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $cartTotals   = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $settings     = (new PosSetting())->getAll();

        // Beverage modifiers: find beverage category ID and load active modifiers
        $beverageCatId = null;
        foreach ($categories as $cat) {
            if ($cat['name'] === BEVERAGE_CATEGORY_NAME) {
                $beverageCatId = (int)$cat['id'];
                break;
            }
        }
        $activeModifiers = (new Modifier())->getActiveModifiers();

        // Terminal info for header badge and print URL
        $terminalName     = null;
        $terminalPrintUrl = PRINT_SERVICE_URL;
        $terminalId       = $_SESSION['pos_terminal_id'] ?? null;
        if ($terminalId) {
            $terminal = (new Terminal())->findById($terminalId);
            if ($terminal) {
                $terminalName     = $terminal['name'];
                $terminalPrintUrl = rtrim($terminal['print_service_url'], '/');
            }
        }

        require APP_PATH . '/views/sale/terminal.php';
    }

    public function complete(): void {
        requireOperator();
        requireShift();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/sale');
            return;
        }

        verifyCsrfToken();

        $cart = $_SESSION['pos_cart'] ?? [];
        if (empty($cart)) {
            setFlash('error', 'Cart is empty.');
            redirect('/sale');
            return;
        }

        // Parse payments from POST
        $payments = [];
        $payMethods = $_POST['pay_method'] ?? [];
        $payAmounts = $_POST['pay_amount'] ?? [];
        $payRefs    = $_POST['pay_reference'] ?? [];

        for ($i = 0; $i < count($payMethods); $i++) {
            if (!empty($payMethods[$i]) && (float)($payAmounts[$i] ?? 0) > 0) {
                $payments[] = [
                    'method'    => $payMethods[$i],
                    'amount'    => round((float)$payAmounts[$i], 2),
                    'reference' => $payRefs[$i] ?? null,
                ];
            }
        }

        // Validate payments cover total
        $wholesale    = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $cartTotals   = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $totalPaid    = array_sum(array_column($payments, 'amount'));

        if ($totalPaid < $cartTotals['total']) {
            setFlash('error', 'Payment amount insufficient.');
            redirect('/sale');
            return;
        }

        $change = round($totalPaid - $cartTotals['total'], 2);

        // Get location
        $locationId = (new PosSetting())->getShopLocationId();
        if (!$locationId) {
            setFlash('error', 'Shop location not configured. Go to Settings.');
            redirect('/sale');
            return;
        }

        $user = currentOperator();

        try {
            $txnId = (new Transaction())->create(
                $_SESSION['pos_shift_id'],
                $user['id'],
                $cartTotals['items'],
                $payments,
                $locationId,
                $wholesale,
                $cartDiscount
            );

            // Clear cart + wholesale + discount flags
            unset($_SESSION['pos_cart']);
            unset($_SESSION['pos_wholesale']);
            unset($_SESSION['pos_cart_discount']);

            $_SESSION['last_txn_id']  = $txnId;
            $_SESSION['last_change']  = $change;
            $_SESSION['auto_print']   = true;

            redirect('/sale/receipt/' . $txnId);

        } catch (Exception $e) {
            setFlash('error', 'Sale failed: ' . $e->getMessage());
            redirect('/sale');
        }
    }

    public function receipt(): void {
        requireOperator();

        $txnId  = (int)($_GET['id'] ?? $_SESSION['last_txn_id'] ?? 0);
        $change = $_SESSION['last_change'] ?? 0;

        $txnModel    = new Transaction();
        $transaction = $txnModel->findById($txnId);
        $items       = $txnModel->getItemsWithModifiers($txnId);
        $payments    = $txnModel->getPayments($txnId);
        $settings    = (new PosSetting())->getAll();
        $autoPrint   = !empty($_SESSION['auto_print']);

        unset($_SESSION['last_txn_id'], $_SESSION['last_change'], $_SESSION['auto_print']);

        require APP_PATH . '/views/sale/receipt.php';
    }

    private function sendPrintJob(int $txnId, float $change): void {
        try {
            $printUrl = PRINT_SERVICE_URL;
            $terminalId = $_SESSION['pos_terminal_id'] ?? null;
            if ($terminalId) {
                $terminal = (new Terminal())->findById($terminalId);
                if ($terminal && !empty($terminal['print_service_url'])) {
                    $printUrl = rtrim($terminal['print_service_url'], '/');
                }
            }
            $url = $printUrl . '/print/receipt';
            $data = json_encode(['transaction_id' => $txnId, 'change' => $change]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            // Print failure shouldn't block the sale
            error_log('Print service error: ' . $e->getMessage());
        }
    }
}
