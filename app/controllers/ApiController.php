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
        $quantity  = (int)($_POST['quantity'] ?? 1);
        if ($quantity < 1) $quantity = 1;

        $product = (new Product())->findById($productId);
        if (!$product) {
            $this->json(['error' => 'Product not found'], 404);
            return;
        }

        $cart = &$_SESSION['pos_cart'];
        if (!is_array($cart)) $cart = [];

        // Check if product already in cart
        $found = false;
        foreach ($cart as &$item) {
            if ($item['product_id'] === $productId) {
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
            ];
        }

        $wholesale = !empty($_SESSION['pos_wholesale']);
        $totals = calculateCartTotals($cart, $wholesale);
        $this->json($totals);
    }

    /** POST /api/cart/update */
    public function cartUpdate(): void {
        $productId = (int)($_POST['product_id'] ?? 0);
        $quantity  = (int)($_POST['quantity'] ?? 0);

        $cart = &$_SESSION['pos_cart'];
        if (!is_array($cart)) $cart = [];

        if ($quantity <= 0) {
            // Remove from cart
            $cart = array_values(array_filter($cart, fn($i) => $i['product_id'] !== $productId));
        } else {
            foreach ($cart as &$item) {
                if ($item['product_id'] === $productId) {
                    $item['quantity'] = $quantity;
                    break;
                }
            }
            unset($item);
        }

        $wholesale = !empty($_SESSION['pos_wholesale']);
        $totals = calculateCartTotals($cart, $wholesale);
        $this->json($totals);
    }

    /** POST /api/cart/remove */
    public function cartRemove(): void {
        $productId = (int)($_POST['product_id'] ?? 0);

        $cart = &$_SESSION['pos_cart'];
        if (!is_array($cart)) $cart = [];

        $cart = array_values(array_filter($cart, fn($i) => $i['product_id'] !== $productId));
        $_SESSION['pos_cart'] = $cart;

        $wholesale = !empty($_SESSION['pos_wholesale']);
        $totals = calculateCartTotals($cart, $wholesale);
        $this->json($totals);
    }

    /** POST /api/cart/clear */
    public function cartClear(): void {
        $_SESSION['pos_cart'] = [];
        unset($_SESSION['pos_wholesale']);
        $this->json(['items' => [], 'subtotal' => 0, 'gst' => 0, 'pst' => 0, 'total' => 0, 'wholesale' => false]);
    }

    /** POST /api/wholesale/toggle */
    public function wholesaleToggle(): void {
        $_SESSION['pos_wholesale'] = empty($_SESSION['pos_wholesale']);
        $wholesale = !empty($_SESSION['pos_wholesale']);

        $cart = $_SESSION['pos_cart'] ?? [];
        $totals = calculateCartTotals($cart, $wholesale);
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
            $url  = PRINT_SERVICE_URL . '/print/receipt';
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
            $url = PRINT_SERVICE_URL . '/print/open-drawer';
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
            $url  = PRINT_SERVICE_URL . '/pole-display';
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

    private function json(mixed $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
