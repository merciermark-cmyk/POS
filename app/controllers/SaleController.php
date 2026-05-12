<?php
class SaleController {

    public function terminal(): void {
        requireOperator();
        requireShift();

        $catModel     = new Category();
        $categories   = $catModel->getAll();
        $categoryTree = $catModel->getAllWithHierarchy();
        $products     = (new Product())->getAll();
        $cart         = $_SESSION['pos_cart'] ?? [];
        $wholesale    = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $cartTotals   = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $settings     = (new PosSetting())->getAll();

        // Beverage modifiers: find beverage category IDs (parent + children)
        $beverageCatIds = [];
        foreach ($categoryTree as $cat) {
            if ($cat['name'] === BEVERAGE_CATEGORY_NAME) {
                $beverageCatIds[] = (int)$cat['id'];
                foreach ($cat['children'] as $child) {
                    $beverageCatIds[] = (int)$child['id'];
                }
                break;
            }
        }

        // Loose tea modifiers: find loose tea category IDs
        $looseTeaCatNames = REPORT_CATEGORY_GROUPS['Loose Tea'] ?? [];
        $looseTeaCatIds = [];
        foreach ($categoryTree as $cat) {
            if (in_array($cat['name'], $looseTeaCatNames, true)) {
                $looseTeaCatIds[] = (int)$cat['id'];
                foreach ($cat['children'] ?? [] as $child) {
                    $looseTeaCatIds[] = (int)$child['id'];
                }
            }
            // Also check children (loose tea cats may be subcategories)
            foreach ($cat['children'] ?? [] as $child) {
                if (in_array($child['name'], $looseTeaCatNames, true)) {
                    $looseTeaCatIds[] = (int)$child['id'];
                }
            }
        }
        $looseTeaCatIds = array_values(array_unique($looseTeaCatIds));

        $activeModifiers = (new Modifier())->getActiveModifiers();
        $standaloneRefundThreshold = (float)($settings['standalone_refund_threshold'] ?? '50.00');

        // Held order count for badge
        $heldOrderCount = (new HeldOrder())->countActiveForShift($_SESSION['pos_shift_id']);

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
        $payMethods    = $_POST['pay_method'] ?? [];
        $payAmounts    = $_POST['pay_amount'] ?? [];
        $payRefs       = $_POST['pay_reference'] ?? [];
        $payMonerisIds = $_POST['pay_moneris_id'] ?? [];

        for ($i = 0; $i < count($payMethods); $i++) {
            if (!empty($payMethods[$i]) && (float)($payAmounts[$i] ?? 0) > 0) {
                $payments[] = [
                    'method'                 => $payMethods[$i],
                    'amount'                 => round((float)$payAmounts[$i], 2),
                    'reference'              => $payRefs[$i] ?? null,
                    'moneris_transaction_id' => !empty($payMonerisIds[$i]) ? (int)$payMonerisIds[$i] : null,
                ];
            }
        }

        // Validate payments cover total
        $wholesale    = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $cartTotals   = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $totalPaid    = array_sum(array_column($payments, 'amount'));

        $allCash = !empty($payments) && empty(array_filter($payments, fn($p) => $p['method'] !== 'cash'));
        $minRequired = $allCash ? nickelRound($cartTotals['total']) : $cartTotals['total'];
        if ($totalPaid < $minRequired) {
            setFlash('error', 'Payment amount insufficient.');
            redirect('/sale');
            return;
        }

        $change = round($totalPaid - $cartTotals['total'], 2);
        if ($hasCash && $change > 0) {
            $change = nickelRound($change);
        }

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

            // Link Moneris transactions to the POS transaction
            $monerisModel = new Moneris();
            foreach ($payments as $pay) {
                if (!empty($pay['moneris_transaction_id'])) {
                    $monerisModel->linkToTransaction((int)$pay['moneris_transaction_id'], $txnId);
                }
            }

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

        // Terminal print URL (same pattern as terminal() method)
        $terminalPrintUrl = PRINT_SERVICE_URL;
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        if ($terminalId) {
            $terminal = (new Terminal())->findById($terminalId);
            if ($terminal && !empty($terminal['print_service_url'])) {
                $terminalPrintUrl = rtrim($terminal['print_service_url'], '/');
            }
        }

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
