<?php
class ApiController {

    public function __construct() {
        // All API endpoints require an operator or authenticated user
        if (!hasOperator()) {
            $this->json(['error' => 'Unauthorized'], 401);
            exit;
        }
    }

    /** GET /api/products?category_id=&search= */
    public function products(): void {
        $categoryId = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : null;
        $search     = trim($_GET['search'] ?? '');

        $products = (new Product())->getAll($categoryId, $search ?: null);
        $this->json($products);
    }

    /** POST /api/cart/add */
    public function cartAdd(): void {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity  = (float)($_POST['quantity'] ?? 1);
        if ($quantity < 0.01) $quantity = 1;

        // Parse optional modifiers JSON: [{"id":1,"name":"Oat Milk","price":0.75,"qty":1}, ...]
        $modifiers = [];
        $modifiersRaw = $_POST['modifiers'] ?? '';
        if ($modifiersRaw && is_string($modifiersRaw)) {
            $decoded = json_decode($modifiersRaw, true);
            if (is_array($decoded)) $modifiers = $decoded;
        }

        $product = (new Product())->findById($productId);
        if (!$product) {
            $this->json(['error' => 'Product not found'], 404);
            return;
        }

        $cartKey = cartItemKey($productId, $modifiers);

        $cart = &$_SESSION['pos_cart'];
        if (!is_array($cart)) $cart = [];

        // Check if same cart_key already in cart
        $found = false;
        foreach ($cart as &$item) {
            $itemKey = $item['cart_key'] ?? (string)$item['product_id'];
            if ($itemKey === $cartKey) {
                $item['quantity'] += $quantity;
                $found = true;
                break;
            }
        }
        unset($item);

        if (!$found) {
            $cart[] = [
                'product_id'   => $product['id'],
                'product_name' => $product['name'],
                'product_code' => $product['product_code'],
                'unit_price'   => (float)$product['unit_price'],
                'quantity'     => $quantity,
                'tax_profile'  => $product['tax_profile'] ?? 'tax_free',
                'image'        => $product['image'] ?? null,
                'modifiers'    => $modifiers,
                'cart_key'     => $cartKey,
            ];
        }

        $wholesale = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $totals = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $this->json($totals);
    }

    /** POST /api/cart/update */
    public function cartUpdate(): void {
        $cartKey      = $_POST['cart_key'] ?? '';
        $productId    = (int)($_POST['product_id'] ?? 0);
        $quantity     = (float)($_POST['quantity'] ?? 0);
        $dollarAmount = isset($_POST['dollar_amount']) && $_POST['dollar_amount'] !== ''
            ? round((float)$_POST['dollar_amount'], 2) : null;

        $cart = &$_SESSION['pos_cart'];
        if (!is_array($cart)) $cart = [];

        if ($quantity <= 0) {
            // Remove from cart by cart_key or product_id
            $cart = array_values(array_filter($cart, function($i) use ($cartKey, $productId) {
                if ($cartKey) return ($i['cart_key'] ?? (string)$i['product_id']) !== $cartKey;
                return $i['product_id'] !== $productId;
            }));
        } else {
            foreach ($cart as &$item) {
                $itemKey = $item['cart_key'] ?? (string)$item['product_id'];
                if ($cartKey && $itemKey === $cartKey) {
                    $item['quantity'] = $quantity;
                    $item['dollar_amount'] = $dollarAmount;
                    break;
                } elseif (!$cartKey && $item['product_id'] === $productId) {
                    $item['quantity'] = $quantity;
                    $item['dollar_amount'] = $dollarAmount;
                    break;
                }
            }
            unset($item);
        }

        $wholesale = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $totals = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $this->json($totals);
    }

    /** POST /api/cart/remove */
    public function cartRemove(): void {
        $cartKey   = $_POST['cart_key'] ?? '';
        $productId = (int)($_POST['product_id'] ?? 0);

        $cart = &$_SESSION['pos_cart'];
        if (!is_array($cart)) $cart = [];

        $cart = array_values(array_filter($cart, function($i) use ($cartKey, $productId) {
            if ($cartKey) return ($i['cart_key'] ?? (string)$i['product_id']) !== $cartKey;
            return $i['product_id'] !== $productId;
        }));
        $_SESSION['pos_cart'] = $cart;

        $wholesale = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $totals = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $this->json($totals);
    }

    /** POST /api/cart/clear */
    public function cartClear(): void {
        $_SESSION['pos_cart'] = [];
        unset($_SESSION['pos_wholesale']);
        unset($_SESSION['pos_cart_discount']);
        $this->json(['items' => [], 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0, 'wholesale' => false, 'cart_discount' => false]);
    }

    /** POST /api/wholesale/toggle */
    public function wholesaleToggle(): void {
        $_SESSION['pos_wholesale'] = empty($_SESSION['pos_wholesale']);
        $wholesale = !empty($_SESSION['pos_wholesale']);

        // Wholesale clears any discount
        if ($wholesale) {
            unset($_SESSION['pos_cart_discount']);
            $cart = &$_SESSION['pos_cart'];
            if (is_array($cart)) {
                foreach ($cart as &$item) {
                    unset($item['discount']);
                }
                unset($item);
            }
        }

        $cart = $_SESSION['pos_cart'] ?? [];
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $totals = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $this->json($totals);
    }

    /** POST /api/discount/toggle — Toggle cart-wide 10% discount */
    public function discountToggle(): void {
        if (!empty($_SESSION['pos_wholesale'])) {
            $this->json(['error' => 'Cannot apply discount while wholesale is active'], 400);
            return;
        }

        $_SESSION['pos_cart_discount'] = empty($_SESSION['pos_cart_discount']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);

        // Cart-wide discount clears per-item discounts
        $cart = &$_SESSION['pos_cart'];
        if (is_array($cart)) {
            foreach ($cart as &$item) {
                unset($item['discount']);
            }
            unset($item);
        }

        $wholesale = !empty($_SESSION['pos_wholesale']);
        $totals = calculateCartTotals($cart ?? [], $wholesale, $cartDiscount);
        $this->json($totals);
    }

    /** POST /api/discount/item — Toggle 10% discount on a single cart item */
    public function discountItem(): void {
        if (!empty($_SESSION['pos_wholesale'])) {
            $this->json(['error' => 'Cannot apply discount while wholesale is active'], 400);
            return;
        }

        $cartKey = $_POST['cart_key'] ?? '';
        if (!$cartKey) {
            $this->json(['error' => 'cart_key required'], 400);
            return;
        }

        $cart = &$_SESSION['pos_cart'];
        if (!is_array($cart)) $cart = [];

        foreach ($cart as &$item) {
            $itemKey = $item['cart_key'] ?? (string)$item['product_id'];
            if ($itemKey === $cartKey) {
                $item['discount'] = empty($item['discount']);
                break;
            }
        }
        unset($item);

        // Per-item discount clears cart-wide discount
        unset($_SESSION['pos_cart_discount']);

        $wholesale = !empty($_SESSION['pos_wholesale']);
        $totals = calculateCartTotals($cart, $wholesale, false);
        $this->json($totals);
    }

    /** GET /api/gift-card/check?code= */
    public function giftCardCheck(): void {
        $code = trim($_GET['code'] ?? '');
        if (!$code) {
            $this->json(['error' => 'Code required'], 400);
            return;
        }

        try {
            $card = (new GiftCard())->checkBalance($code);
            if ($card) {
                $this->json(['code' => $card['code'], 'balance' => (float)$card['balance']]);
            } else {
                $this->json(['error' => 'Gift card not found or expired'], 404);
            }
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /** POST /api/print/receipt */
    public function printReceipt(): void {
        $txnId = (int)($_POST['transaction_id'] ?? 0);

        try {
            $url  = $this->getTerminalPrintUrl() . '/print/receipt';
            $data = json_encode(['transaction_id' => $txnId]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            $result = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $this->json(['status' => $code == 200 ? 'ok' : 'error', 'response' => $result]);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /** POST /api/print/open-drawer */
    public function printOpenDrawer(): void {
        try {
            $url = $this->getTerminalPrintUrl() . '/print/open-drawer';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
            $this->json(['status' => 'ok']);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /** POST /api/verify-manager-pin */
    public function verifyManagerPin(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST required'], 405);
            return;
        }

        $pin = trim($_POST['pin'] ?? '');
        if (!$pin) {
            $this->json(['error' => 'PIN required'], 400);
            return;
        }

        $manager = (new PosUser())->findManagerByPin($pin);
        if (!$manager) {
            $this->json(['error' => 'Invalid manager PIN'], 403);
            return;
        }

        $this->json(['id' => (int)$manager['id'], 'username' => $manager['username']]);
    }

    /** POST /api/standalone-refund */
    public function standaloneRefund(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST required'], 405);
            return;
        }

        $amount       = round((float)($_POST['amount'] ?? 0), 2);
        $customerName = trim($_POST['customer_name'] ?? '');
        $reason       = trim($_POST['reason'] ?? '');
        $method       = $_POST['payment_method'] ?? '';
        $authBy       = !empty($_POST['authorized_by']) ? (int)$_POST['authorized_by'] : null;

        // Validate
        if ($amount <= 0) {
            $this->json(['error' => 'Invalid amount'], 400);
            return;
        }
        if (!$customerName) {
            $this->json(['error' => 'Customer name required'], 400);
            return;
        }
        if (!$reason) {
            $this->json(['error' => 'Reason required'], 400);
            return;
        }
        if (!in_array($method, ['cash', 'card'])) {
            $this->json(['error' => 'Invalid payment method'], 400);
            return;
        }

        // Check threshold
        $threshold = (float)(new PosSetting())->get('standalone_refund_threshold', '50.00');
        if ($amount > $threshold && !$authBy) {
            $this->json(['error' => 'Manager authorization required for refunds over $' . number_format($threshold, 2)], 403);
            return;
        }

        $shiftId    = $_SESSION['pos_shift_id'] ?? null;
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        $operator   = currentOperator();

        if (!$shiftId) {
            $this->json(['error' => 'No open shift'], 400);
            return;
        }

        $refundModel = new StandaloneRefund();
        $refundId = $refundModel->create([
            'shift_id'       => $shiftId,
            'terminal_id'    => $terminalId,
            'processed_by'   => $operator['id'],
            'authorized_by'  => $authBy,
            'amount'         => $amount,
            'payment_method' => $method,
            'reason'         => $reason,
            'customer_name'  => $customerName,
        ]);

        // Build receipt JSON for the print service
        $settings = (new PosSetting())->getAll();
        $authName = null;
        if ($authBy) {
            $authUser = (new PosUser())->findById($authBy);
            $authName = $authUser['username'] ?? null;
        }

        $receipt = [
            'store_name'    => $settings['store_name'] ?? 'Granville Island Tea Co.',
            'store_address' => $settings['store_address'] ?? '',
            'store_phone'   => $settings['store_phone'] ?? '',
            'is_refund'     => true,
            'refund_id'     => $refundId,
            'date'          => date('Y-m-d h:i A'),
            'cashier'       => $operator['username'],
            'reason'        => $reason,
            'items'         => [
                [
                    'name'       => 'Standalone Refund',
                    'quantity'   => 1,
                    'unit_price' => $amount,
                    'line_total' => $amount,
                    'gst'        => 0,
                    'pst'        => 0,
                ]
            ],
            'subtotal'       => $amount,
            'gst_amount'     => 0,
            'pst_amount'     => 0,
            'total'          => $amount,
            'payments'       => [['method' => $method, 'amount' => $amount]],
            'change'         => 0,
            'gst_number'     => $settings['gst_number'] ?? '',
            'pst_number'     => $settings['pst_number'] ?? '',
            'receipt_footer' => $settings['receipt_footer'] ?? 'Thank you!',
            'no_drawer'      => ($method === 'card'),
        ];

        if ($authName) {
            $receipt['authorized_by'] = $authName;
        }

        $this->json([
            'refund_id' => $refundId,
            'receipt'   => $receipt,
        ]);
    }

    /** POST /api/pole-display */
    public function poleDisplay(): void {
        $line1 = $_POST['line1'] ?? '';
        $line2 = $_POST['line2'] ?? '';

        try {
            $url  = $this->getTerminalPrintUrl() . '/pole-display';
            $data = json_encode(['line1' => $line1, 'line2' => $line2]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 2,
            ]);
            curl_exec($ch);
            curl_close($ch);
            $this->json(['status' => 'ok']);
        } catch (Exception $e) {
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /** POST /api/temp-auth/verify — Verify a temporary authorization code */
    public function verifyTempAuth(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST required'], 405);
            return;
        }

        $code = trim($_POST['code'] ?? '');
        if (!$code || !preg_match('/^\d{6}$/', $code)) {
            $this->json(['error' => 'Invalid code format'], 400);
            return;
        }

        $auth = (new TempAuth())->verify($code);
        if (!$auth) {
            $this->json(['error' => 'Invalid or expired code'], 403);
            return;
        }

        $this->json([
            'valid'            => true,
            'auth_id'          => (int)$auth['id'],
            'manager_username' => $auth['manager_username'],
        ]);
    }

    /** POST /api/temp-auth/generate — Generate a code (manager only) */
    public function generateTempAuth(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST required'], 405);
            return;
        }

        if (!isManager()) {
            $this->json(['error' => 'Manager access required'], 403);
            return;
        }

        $user = currentUser();
        $result = (new TempAuth())->generate($user['id']);
        $this->json($result);
    }

    private function getTerminalPrintUrl(): string {
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        if ($terminalId) {
            $terminal = (new Terminal())->findById($terminalId);
            if ($terminal && !empty($terminal['print_service_url'])) {
                return rtrim($terminal['print_service_url'], '/');
            }
        }
        return PRINT_SERVICE_URL;
    }

    private function json(mixed $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
