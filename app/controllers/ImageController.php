<?php
class ImageController {

    public function index(): void {
        requireManager();

        $products = (new Product())->getAll();
        $imageModel = new ProductImage();

        // Attach images to each product
        foreach ($products as &$p) {
            $p['images'] = $imageModel->getForProduct($p['id']);
        }
        unset($p);

        require APP_PATH . '/views/admin/images.php';
    }

    public function upload(): void {
        requireManager();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/images');
            return;
        }

        verifyCsrfToken();

        $productId = (int)($_POST['product_id'] ?? 0);
        if (!$productId) {
            setFlash('error', 'Product not specified.');
            redirect('/images');
            return;
        }

        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'No file uploaded.');
            redirect('/images');
            return;
        }

        $file = $_FILES['image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];

        if (!in_array($file['type'], $allowed)) {
            setFlash('error', 'Only JPEG, PNG, and WebP images are allowed.');
            redirect('/images');
            return;
        }

        if ($file['size'] > 5 * 1024 * 1024) {
            setFlash('error', 'File too large (max 5MB).');
            redirect('/images');
            return;
        }

        // Generate filename
        $ext = match ($file['type']) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        };
        $filename = 'product_' . $productId . '_' . time() . '.' . $ext;
        $destPath = UPLOAD_PATH . '/' . $filename;

        // Resize to 300x300
        $this->resizeImage($file['tmp_name'], $destPath, 300, 300, $file['type']);

        // Save to DB
        $imageModel = new ProductImage();
        $sortOrder  = $imageModel->getNextSortOrder($productId);
        $imageModel->create($productId, $filename, $sortOrder);

        setFlash('success', 'Image uploaded.');
        redirect('/images');
    }

    public function delete(): void {
        requireManager();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/images');
            return;
        }

        verifyCsrfToken();

        $imageId = (int)($_POST['image_id'] ?? 0);
        $imageModel = new ProductImage();
        $image = $imageModel->findById($imageId);

        if ($image) {
            $filePath = UPLOAD_PATH . '/' . $image['filename'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $imageModel->delete($imageId);
            setFlash('success', 'Image deleted.');
        }

        redirect('/images');
    }

    private function resizeImage(string $source, string $dest, int $maxW, int $maxH, string $mime): void {
        // Detect actual image type from file contents (don't trust browser MIME)
        $info = getimagesize($source);
        if ($info) {
            $mime = $info['mime'];
        }

        $img = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($source),
            'image/png'  => imagecreatefrompng($source),
            'image/webp' => imagecreatefromwebp($source),
            default      => null,
        };

        if (!$img) {
            copy($source, $dest);
            return;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // Calculate new dimensions maintaining aspect ratio, then crop to square
        $size = min($w, $h);
        $srcX = ($w - $size) / 2;
        $srcY = ($h - $size) / 2;

        $thumb = imagecreatetruecolor($maxW, $maxH);

        // Preserve transparency for PNG/WebP
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $img, 0, 0, (int)$srcX, (int)$srcY, $maxW, $maxH, $size, $size);

        match ($mime) {
            'image/jpeg' => imagejpeg($thumb, $dest, 85),
            'image/png'  => imagepng($thumb, $dest, 8),
            'image/webp' => imagewebp($thumb, $dest, 85),
        };

        imagedestroy($img);
        imagedestroy($thumb);
    }
}
