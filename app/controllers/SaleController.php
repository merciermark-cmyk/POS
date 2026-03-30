<?php
class SaleController {

    public function terminal(): void {
        requireOperator();
        requireShift();

        $categories = (new Category())->getAll();
        $products   = (new Product())->getAll();
        $cart       = $_SESSION['pos_cart'] ?? [];
        $wholesale  = !empty($_SESSION['pos_wholesale']);
        $cartTotals = calculateCartTotals($cart, $wholesale);
        $settings   = (new PosSetting())->getAll();

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
        $wholesale  = !empty($_SESSION['pos_wholesale']);
        $cartTotals = calculateCartTotals($cart, $wholesale);
        $totalPaid  = array_sum(array_column($payments, 'amount'));

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
                $wholesale
            );

            // Clear cart + wholesale flag
            unset($_SESSION['pos_cart']);
            unset($_SESSION['pos_wholesale']);

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
        $items       = $txnModel->getItems($txnId);
        $payments    = $txnModel->getPayments($txnId);
        $settings    = (new PosSetting())->getAll();
        $autoPrint   = !empty($_SESSION['auto_print']);

        unset($_SESSION['last_txn_id'], $_SESSION['last_change'], $_SESSION['auto_print']);

        require APP_PATH . '/views/sale/receipt.php';
    }

    private function sendPrintJob(int $txnId, float $change): void {
        try {
            $url = PRINT_SERVICE_URL . '/print/receipt';
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
