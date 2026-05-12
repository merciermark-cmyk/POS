<?php
/**
 * Sync missing POS product images from PrestaShop.
 * Matches by ps_product_lang.name LIKE inventory product name
 * Uses large_default (540x540) resized to 300x300.
 * Run once, then delete.
 */

// ── Config ──────────────────────────────────────────────────────────────────
$invDb = new PDO('mysql:host=localhost;dbname=gitte512_git_inventory;charset=utf8mb4',
    'gitte512_inventory_manager', 'Starlifter44*');
$invDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$psDb = new PDO('mysql:host=localhost;dbname=gitte512_dev_staging;charset=utf8mb4',
    'gitte512_mark', '8q4grp0mdf');
$psDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$posImgDir = __DIR__ . '/public/uploads/pos';
$psImgBase = '/home/gitte512/public_html/img/p';
$targetSize = 300;

if (!is_dir($posImgDir)) {
    mkdir($posImgDir, 0755, true);
}

// ── Find POS products missing images ────────────────────────────────────────
$missing = $invDb->query("
    SELECT p.id, p.product_code, p.name
    FROM products p
    LEFT JOIN product_images pi ON pi.product_id = p.id
    WHERE pi.id IS NULL
    ORDER BY p.name
")->fetchAll(PDO::FETCH_ASSOC);

echo count($missing) . " products missing images.\n\n";

$synced = 0;
$skipped = 0;

foreach ($missing as $prod) {
    $code = $prod['product_code'];
    $name = $prod['name'];

    // Strip common suffixes for matching (e.g. "CM teaboxes" -> "CM")
    $searchName = preg_replace('/\s+teaboxes$/i', '', $name);
    $searchName = preg_replace('/\s+(Regular|Large)$/i', '', $searchName);

    // Find matching PS product by name
    $ps = $psDb->prepare("
        SELECT p.id_product, i.id_image, pl.name AS ps_name
        FROM ps_product p
        JOIN ps_product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = 1
        JOIN ps_image i ON i.id_product = p.id_product AND i.cover = 1
        WHERE pl.name LIKE ?
        LIMIT 1
    ");
    $ps->execute(['%' . $searchName . '%']);
    $match = $ps->fetch(PDO::FETCH_ASSOC);

    if (!$match) {
        echo "SKIP [{$code}] {$prod['name']} (searched: {$searchName}) — no PS match\n";
        $skipped++;
        continue;
    }

    $imgId = $match['id_image'];

    // Build PS image path: split digits into directories
    $digits = str_split((string)$imgId);
    $path = $psImgBase . '/' . implode('/', $digits) . '/' . $imgId . '-large_default.jpg';

    if (!file_exists($path)) {
        // Try home_default2x as fallback
        $path = $psImgBase . '/' . implode('/', $digits) . '/' . $imgId . '-home_default2x.jpg';
        if (!file_exists($path)) {
            echo "SKIP [{$code}] {$prod['name']} — image file not found (id_image={$imgId})\n";
            $skipped++;
            continue;
        }
    }

    // Resize to 300x300 and save
    $filename = 'product_' . $prod['id'] . '_' . time() . '.jpg';
    $destPath = $posImgDir . '/' . $filename;

    // Detect real image format (some .jpg files are actually PNG)
    $info = @getimagesize($path);
    if (!$info) {
        echo "SKIP [{$code}] {$prod['name']} — could not read image info\n";
        $skipped++;
        continue;
    }
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($path); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($path);  break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($path); break;
        default: $src = false;
    }
    if (!$src) {
        echo "SKIP [{$code}] {$prod['name']} — unsupported image type ({$info[2]})\n";
        $skipped++;
        continue;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Center crop to square, then resize
    $cropSize = min($srcW, $srcH);
    $cropX = (int)(($srcW - $cropSize) / 2);
    $cropY = (int)(($srcH - $cropSize) / 2);

    $dst = imagecreatetruecolor($targetSize, $targetSize);
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $targetSize, $targetSize, $cropSize, $cropSize);
    imagejpeg($dst, $destPath, 90);
    imagedestroy($src);
    imagedestroy($dst);

    // Insert into product_images
    $stmt = $invDb->prepare("
        INSERT INTO product_images (product_id, filename, sort_order)
        VALUES (?, ?, 0)
    ");
    $stmt->execute([$prod['id'], $filename]);

    $psName = $match['ps_name'] ?? '';
    echo "OK   [{$code}] {$prod['name']} -> PS: {$psName} — {$filename}\n";
    $synced++;
}

echo "\nDone. Synced: {$synced}, Skipped: {$skipped}\n";
