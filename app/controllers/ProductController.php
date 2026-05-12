<?php
class ProductController {

    public function index(): void {
        requireManager();

        $productModel = new Product();
        $categories   = (new Category())->getAll();
        $grouped      = $productModel->getAllGroupedByCategory();

        // Optional category filter
        $filterCat = $_GET['category'] ?? '';
        if ($filterCat !== '') {
            $grouped = array_filter($grouped, function($catName) use ($filterCat) {
                return $catName === $filterCat;
            }, ARRAY_FILTER_USE_KEY);
        }

        require APP_PATH . '/views/admin/products.php';
    }

    public function updatePrice(): void {
        requireManager();

        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        // CSRF check via header (AJAX pattern)
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        $expected  = $_SESSION['csrf_token'] ?? '';
        if (!$expected || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $id    = (int)($_POST['id'] ?? 0);
        $price = $_POST['price'] ?? '';

        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid product ID']);
            return;
        }
        if (!is_numeric($price) || (float)$price < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Price must be a non-negative number']);
            return;
        }

        $model   = new Product();
        $product = $model->findById($id);
        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        $model->updatePrice($id, (float)$price);
        echo json_encode(['ok' => true, 'price' => number_format((float)$price, 2)]);
    }

    public function updateWholesalePrice(): void {
        requireManager();
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        $expected  = $_SESSION['csrf_token'] ?? '';
        if (!$expected || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $id    = (int)($_POST['id'] ?? 0);
        $raw   = trim($_POST['wholesale_price'] ?? '');
        $price = $raw === '' ? null : (float)$raw;

        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid product ID']);
            return;
        }
        if ($price !== null && $price < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Price must be non-negative']);
            return;
        }

        $model = new Product();
        if (!$model->findById($id)) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        $model->updateWholesalePrice($id, $price);
        echo json_encode(['ok' => true, 'wholesale_price' => $price !== null ? number_format($price, 2) : '']);
    }

    public function toggleVisibility(): void {
        requireManager();

        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        $expected  = $_SESSION['csrf_token'] ?? '';
        if (!$expected || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $id      = (int)($_POST['id'] ?? 0);
        $visible = (int)($_POST['visible'] ?? 1);

        if ($id < 1) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid product ID']);
            return;
        }

        $model   = new Product();
        $product = $model->findById($id);
        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        $model->toggleVisibility($id, (bool)$visible);
        echo json_encode(['ok' => true, 'visible' => $visible]);
    }
}
