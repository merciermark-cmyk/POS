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
        $cartKey   = $_POST['cart_key'] ?? '';
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity  = (float)($_POST['quantity'] ?? 0);

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
                    break;
                } elseif (!$cartKey && $item['product_id'] === $productId) {
                    $item['quantity'] = $quantity;
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
