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

        // Optional custom name + price (e.g. for non-tracked loose tea)
        $customName  = trim($_POST['custom_name'] ?? '');
        $customPrice = isset($_POST['custom_price']) && $_POST['custom_price'] !== ''
            ? round((float)$_POST['custom_price'], 2) : null;

        $cartKey = cartItemKey($productId, $modifiers);
        // Custom name makes each entry unique in the cart
        if ($customName !== '') {
            $cartKey .= '|cn:' . $customName;
        }

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
            $displayName = $product['name'];
            if ($customName !== '') {
                $displayName .= ' — ' . $customName;
            }
            $unitPrice = $customPrice !== null ? $customPrice : (float)$product['unit_price'];
            $entry = [
                'product_id'      => $product['id'],
                'product_name'    => $displayName,
                'product_code'    => $product['product_code'],
                'unit_price'      => $unitPrice,
                'wholesale_price' => isset($product['wholesale_price']) ? (float)$product['wholesale_price'] : null,
                'quantity'        => $quantity,
                'tax_profile'     => $product['tax_profile'] ?? 'tax_free',
                'image'           => $product['image'] ?? null,
                'modifiers'       => $modifiers,
                'cart_key'        => $cartKey,
            ];
            // Flag loose tea items for special pricing (flat tin cost, $6 min at 50g)
            if (!empty($_POST['loose_tea'])) {
                $entry['loose_tea'] = true;
            }
            $cart[] = $entry;
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
        if ($txnId <= 0) {
            $this->json(['error' => 'Invalid transaction_id'], 400);
            return;
        }

        try {
            $txnModel    = new Transaction();
            $transaction = $txnModel->findById($txnId);
            if (!$transaction) {
                $this->json(['error' => 'Transaction not found'], 404);
                return;
            }
            $items    = $txnModel->getItemsWithModifiers($txnId);
            $payments = $txnModel->getPayments($txnId);
            $settings = (new PosSetting())->getAll();

            $payload = [
                'store_name'    => $settings['store_name']    ?? 'Granville Island Tea Co.',
                'store_address' => $settings['store_address'] ?? '',
                'store_phone'   => $settings['store_phone']   ?? '',
                'transaction_id'=> (int)($transaction['id'] ?? 0),
                'daily_number'  => $transaction['daily_number']  ?? null,
                'annual_number' => $transaction['annual_number'] ?? null,
                'date'          => isset($transaction['created_at'])
                                    ? date('Y-m-d H:i', strtotime($transaction['created_at']))
                                    : '',
                'cashier'       => $transaction['username'] ?? '',
                'items'         => array_map(fn($i) => [
                    'name'             => $i['product_name'],
                    'quantity'         => (float)$i['quantity'],
                    'unit_price'       => (float)$i['unit_price'],
                    'line_total'       => (float)$i['line_total'],
                    'gst'              => (float)$i['gst'],
                    'pst'              => (float)$i['pst'],
                    'discount_percent' => (float)($i['discount_percent'] ?? 0),
                    'modifiers'        => array_map(fn($m) => [
                        'name'  => $m['modifier_name'],
                        'price' => (float)$m['modifier_price'],
                        'qty'   => (int)$m['quantity'],
                    ], $i['modifiers'] ?? []),
                ], $items ?? []),
                'subtotal'       => (float)($transaction['subtotal']   ?? 0),
                'gst_amount'     => (float)($transaction['gst_amount'] ?? 0),
                'pst_amount'     => (float)($transaction['pst_amount'] ?? 0),
                'total'          => (float)($transaction['total']      ?? 0),
                'payments'       => array_map(fn($p) => [
                    'method'    => $p['method'],
                    'amount'    => (float)$p['amount'],
                    'reference' => $p['reference'] ?? '',
                ], $payments ?? []),
                'change'         => 0.0,
                'gst_number'     => $settings['gst_number']     ?? '',
                'pst_number'     => $settings['pst_number']     ?? '',
                'receipt_footer' => $settings['receipt_footer'] ?? 'Thank you for your purchase!',
            ];

            $url  = $this->getTerminalPrintUrl() . '/print/receipt';
            $data = json_encode($payload);

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
            // Log manual drawer open
            $db = getDB();
            $stmt = $db->prepare("
                INSERT INTO pos_drawer_opens (shift_id, terminal_id, user_id)
                VALUES (:shift_id, :terminal_id, :user_id)
            ");
            $stmt->execute([
                ':shift_id'    => $_SESSION['pos_shift_id'] ?? null,
                ':terminal_id' => $_SESSION['pos_terminal_id'] ?? null,
                ':user_id'     => $_SESSION['pos_user_id'],
            ]);

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

    /** POST /api/petty-cash/add */
    public function pettyCashAdd(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST required'], 405);
            return;
        }

        $amount      = round((float)($_POST['amount'] ?? 0), 2);
        $description = trim($_POST['description'] ?? '');
        $authBy      = !empty($_POST['authorized_by']) ? (int)$_POST['authorized_by'] : null;

        if ($amount <= 0) {
            $this->json(['error' => 'Invalid amount'], 400);
            return;
        }
        if (!$description) {
            $this->json(['error' => 'Description required'], 400);
            return;
        }

        $shiftId    = $_SESSION['pos_shift_id'] ?? null;
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        $operator   = currentOperator();

        if (!$shiftId) {
            $this->json(['error' => 'No open shift'], 400);
            return;
        }

        $model = new PettyCash();
        $id = $model->create([
            'shift_id'      => $shiftId,
            'terminal_id'   => $terminalId,
            'user_id'       => $operator['id'],
            'authorized_by' => $authBy,
            'amount'        => $amount,
            'description'   => $description,
        ]);

        $this->json(['id' => $id, 'amount' => $amount, 'description' => $description]);
    }

    /** GET /api/petty-cash/list */
    public function pettyCashList(): void {
        $shiftId = $_SESSION['pos_shift_id'] ?? null;
        if (!$shiftId) {
            $this->json(['error' => 'No open shift'], 400);
            return;
        }

        $model   = new PettyCash();
        $summary = $model->getShiftSummary($shiftId);
        $this->json($summary);
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

    // ── Hold Order endpoints ────────────────────────────────────────

    /** POST /api/hold/save — serialize current cart to DB, clear session */
    public function holdSave(): void {
        $cart = $_SESSION['pos_cart'] ?? [];
        if (empty($cart)) {
            $this->json(['error' => 'Cart is empty'], 400);
            return;
        }

        $shiftId    = $_SESSION['pos_shift_id'] ?? null;
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        $operator   = currentOperator();

        if (!$shiftId) {
            $this->json(['error' => 'No open shift'], 400);
            return;
        }

        $wholesale    = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $totals       = calculateCartTotals($cart, $wholesale, $cartDiscount);
        $label        = trim($_POST['label'] ?? '');

        $cartState = [
            'items'         => $cart,
            'wholesale'     => $wholesale,
            'cart_discount'  => $cartDiscount,
        ];

        $model = new HeldOrder();
        $id = $model->hold(
            $shiftId,
            $terminalId,
            $operator['id'],
            $label ?: null,
            $cartState,
            count($cart),
            $totals['total']
        );

        // Clear session cart
        $_SESSION['pos_cart'] = [];
        unset($_SESSION['pos_wholesale']);
        unset($_SESSION['pos_cart_discount']);

        $heldCount = $model->countActiveForShift($shiftId);
        $this->json(['id' => $id, 'held_count' => $heldCount]);
    }

    /** GET /api/hold/list — active held orders for this shift */
    public function holdList(): void {
        $shiftId = $_SESSION['pos_shift_id'] ?? null;
        if (!$shiftId) {
            $this->json(['error' => 'No open shift'], 400);
            return;
        }

        $orders = (new HeldOrder())->getActiveForShift($shiftId);
        $this->json(['orders' => $orders]);
    }

    /** POST /api/hold/resume — restore a held order to the session cart */
    public function holdResume(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            $this->json(['error' => 'ID required'], 400);
            return;
        }

        $model = new HeldOrder();
        $order = $model->findActiveById($id);
        if (!$order) {
            $this->json(['error' => 'Held order not found or already resumed'], 404);
            return;
        }

        $cartState = json_decode($order['cart_json'], true);
        if (!$cartState || !isset($cartState['items'])) {
            $this->json(['error' => 'Invalid cart data'], 500);
            return;
        }

        // Restore session cart
        $_SESSION['pos_cart']          = $cartState['items'];
        $_SESSION['pos_wholesale']     = !empty($cartState['wholesale']);
        $_SESSION['pos_cart_discount'] = !empty($cartState['cart_discount']);

        $operator = currentOperator();
        $model->resume($id, $operator['id']);

        $wholesale    = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $totals       = calculateCartTotals($_SESSION['pos_cart'], $wholesale, $cartDiscount);

        $shiftId   = $_SESSION['pos_shift_id'] ?? 0;
        $heldCount = $model->countActiveForShift($shiftId);

        $this->json(array_merge($totals, ['held_count' => $heldCount]));
    }

    /** POST /api/hold/delete — discard a held order */
    public function holdDelete(): void {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            $this->json(['error' => 'ID required'], 400);
            return;
        }

        $model = new HeldOrder();
        $model->deleteHeld($id);

        $shiftId   = $_SESSION['pos_shift_id'] ?? 0;
        $heldCount = $model->countActiveForShift($shiftId);
        $this->json(['ok' => true, 'held_count' => $heldCount]);
    }

    /** GET /api/hold/count — count for badge */
    public function holdCount(): void {
        $shiftId = $_SESSION['pos_shift_id'] ?? 0;
        $count   = (new HeldOrder())->countActiveForShift($shiftId);
        $this->json(['held_count' => $count]);
    }

    // ── Moneris endpoints ─────────────────────────────────────────

    /** POST /api/moneris/purchase — initiate a card payment via Moneris Go terminal */
    public function monerisPurchase(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST required'], 405);
            return;
        }

        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        if (!$terminalId) {
            $this->json(['error' => 'No terminal selected'], 400);
            return;
        }

        $terminal = (new Terminal())->findById($terminalId);
        if (!$terminal || empty($terminal['moneris_terminal_id'])) {
            $this->json(['error' => 'No Moneris terminal ID configured for this register'], 400);
            return;
        }

        $cart = $_SESSION['pos_cart'] ?? [];
        if (empty($cart)) {
            $this->json(['error' => 'Cart is empty'], 400);
            return;
        }

        $wholesale    = !empty($_SESSION['pos_wholesale']);
        $cartDiscount = !empty($_SESSION['pos_cart_discount']);
        $totals       = calculateCartTotals($cart, $wholesale, $cartDiscount);

        $amount = (float)($_POST['amount'] ?? $totals['total']);
        if ($amount <= 0) {
            $this->json(['error' => 'Invalid amount'], 400);
            return;
        }

        // Proportional tax split if paying partial amount
        $ratio    = $amount / max($totals['total'], 0.01);
        $subtotal = round($totals['subtotal'] * $ratio, 2);
        $gst      = round($totals['gst'] * $ratio, 2);
        $pst      = round($totals['pst'] * $ratio, 2);
        // Adjust subtotal so subtotal + gst + pst = amount exactly
        $subtotal = round($amount - $gst - $pst, 2);

        $operator = currentOperator();

        $moneris = new Moneris();
        $result = $moneris->purchase(
            $terminal['moneris_terminal_id'],
            $amount,
            $subtotal,
            $gst,
            $pst,
            $operator['username']
        );

        $this->json($result);
    }

    /** POST /api/moneris/void — void a Moneris transaction */
    public function monerisVoid(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST required'], 405);
            return;
        }

        $monerisId = (int)($_POST['moneris_transaction_id'] ?? 0);
        if (!$monerisId) {
            $this->json(['error' => 'Moneris transaction ID required'], 400);
            return;
        }

        $moneris = new Moneris();
        $mTxn = $moneris->findMonerisById($monerisId);
        if (!$mTxn) {
            $this->json(['error' => 'Moneris transaction not found'], 404);
            return;
        }

        // Need a Moneris terminal to send the void
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        $monerisTerminalId = null;
        if ($terminalId) {
            $terminal = (new Terminal())->findById($terminalId);
            $monerisTerminalId = $terminal['moneris_terminal_id'] ?? null;
        }
        if (!$monerisTerminalId) {
            // Use the terminal from the original transaction
            $monerisTerminalId = $mTxn['terminal_id'];
        }

        $operator = currentOperator();
        $result = $moneris->void($monerisTerminalId, $mTxn['order_id'], $operator['username']);
        $this->json($result);
    }

    /** GET /api/moneris/status?id= — lookup a Moneris transaction (for reconnection) */
    public function monerisStatus(): void {
        $monerisId = (int)($_GET['id'] ?? 0);
        if (!$monerisId) {
            $this->json(['error' => 'ID required'], 400);
            return;
        }

        $moneris = new Moneris();
        $mTxn = $moneris->findMonerisById($monerisId);
        if (!$mTxn) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $this->json([
            'id'          => (int)$mTxn['id'],
            'completed'   => (bool)$mTxn['completed'],
            'approved'    => $mTxn['status_code'] === '5207',
            'status_code' => $mTxn['status_code'],
            'auth_code'   => $mTxn['auth_code'],
            'card_type'   => $mTxn['card_type'],
            'masked_pan'  => $mTxn['masked_pan'],
            'tender_type' => $mTxn['tender_type'],
            'form_factor' => $mTxn['form_factor'],
        ]);
    }

    /** POST /api/gift-card-sales/add */
    public function giftCardSalesAdd(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['error' => 'POST required'], 405);
            return;
        }

        $amount        = round((float)($_POST['amount'] ?? 0), 2);
        $paymentMethod = $_POST['payment_method'] ?? 'card';
        $notes         = trim($_POST['notes'] ?? '');

        if ($amount <= 0) {
            $this->json(['error' => 'Invalid amount'], 400);
            return;
        }
        if (!in_array($paymentMethod, ['cash', 'card'])) {
            $this->json(['error' => 'Invalid payment method'], 400);
            return;
        }

        $shiftId    = $_SESSION['pos_shift_id'] ?? null;
        $terminalId = $_SESSION['pos_terminal_id'] ?? null;
        $operator   = currentOperator();

        if (!$shiftId) {
            $this->json(['error' => 'No open shift'], 400);
            return;
        }

        $model = new GiftCardSale();
        $id = $model->create([
            'shift_id'       => $shiftId,
            'terminal_id'    => $terminalId,
            'user_id'        => $operator['id'],
            'amount'         => $amount,
            'payment_method' => $paymentMethod,
            'notes'          => $notes ?: null,
        ]);

        $this->json(['id' => $id, 'amount' => $amount, 'payment_method' => $paymentMethod]);
    }

    /** GET /api/gift-card-sales/list */
    public function giftCardSalesList(): void {
        $shiftId = $_SESSION['pos_shift_id'] ?? null;
        if (!$shiftId) {
            $this->json(['error' => 'No open shift'], 400);
            return;
        }

        $model   = new GiftCardSale();
        $summary = $model->getShiftSummary($shiftId);
        $this->json($summary);
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

    /** POST /api/heartbeat — update shift heartbeat for terminal locking */
    public function heartbeat(): void {
        $shiftId = $_SESSION['pos_shift_id'] ?? null;
        if (!$shiftId) {
            $this->json(['error' => 'No active shift'], 400);
            return;
        }
        (new Shift())->updateHeartbeat($shiftId, session_id());
        $this->json(['ok' => true]);
    }

    /** GET /api/currency/usd — today's effective USD→CAD rate */
    public function currencyUsd(): void {
        $settings = new PosSetting();
        $markup   = (float)($settings->get('usd_markup_percent', '2'));

        // Check cache
        $cacheRaw = $settings->get('usd_rate_cache');
        $cache    = $cacheRaw ? json_decode($cacheRaw, true) : null;
        $today    = date('Y-m-d');

        if ($cache && ($cache['date'] ?? '') === $today && !empty($cache['rate'])) {
            $baseRate = (float)$cache['rate'];
        } else {
            // Fetch from Bank of Canada Valet API (USD/CAD daily rate)
            $baseRate = $this->fetchBocRate();

            if ($baseRate > 0) {
                $settings->set('usd_rate_cache', json_encode([
                    'rate' => $baseRate,
                    'date' => $today,
                ]));
            } elseif ($cache && !empty($cache['rate'])) {
                // API down — fall back to last cached rate
                $baseRate = (float)$cache['rate'];
            } else {
                $this->json(['error' => 'Unable to fetch exchange rate'], 503);
                return;
            }
        }

        $effectiveRate = round($baseRate * (1 + $markup / 100), 4);

        $this->json([
            'rate'        => $effectiveRate,
            'base_rate'   => $baseRate,
            'markup'      => $markup,
            'cached_date' => $today,
        ]);
    }

    /** Fetch USD/CAD from Bank of Canada Valet API */
    private function fetchBocRate(): float {
        $url = 'https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json?recent=1';
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header'  => "User-Agent: GranvilleTeaPOS/1.0\r\n",
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if (!$body) return 0.0;

        $data = json_decode($body, true);
        $observations = $data['observations'] ?? [];
        if (empty($observations)) return 0.0;

        $last = end($observations);
        return (float)($last['FXUSDCAD']['v'] ?? 0);
    }

    private function json(mixed $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
