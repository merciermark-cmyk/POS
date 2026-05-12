<?php
/**
 * One-time script: copy PrestaShop images for Signature Tea Bag products into POS.
 * Run on server, then delete.
 */

$db = new PDO(
    'mysql:host=localhost;dbname=gitte512_git_inventory;charset=utf8mb4',
    'gitte512_inventory_manager',
    'Starlifter44*',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$psImgBase  = '/home/gitte512/public_html/img/p';
$posUpload  = __DIR__ . '/public/uploads/pos';
$ts         = time();

// inventory product_id => PS image_id (first image for each)
$mapping = [
    25  => 751,   // CM teaboxes -> Canadian Maple
    52  => 742,   // EBB teaboxes -> English Bay Breakfast
    63  => 743,   // GI blend teaboxes -> Granville Island Blend
    64  => 749,   // GIG teaboxes -> Gulf Island Green
    166 => 747,   // SPA teaboxes -> Stanley Park Afternoon
    186 => 755,   // VW teaboxes -> Vancouver Waterfront
    189 => 745,   // WW teaboxes -> Whistler Wellness
];

foreach ($mapping as $prodId => $imgId) {
    // PS stores images at /img/p/D/I/G/I/T/S/imgId.jpg
    $digits = str_split((string)$imgId);
    $psPath = $psImgBase . '/' . implode('/', $digits) . '/' . $imgId . '.jpg';

    if (!file_exists($psPath)) {
        echo "MISSING: $psPath\n";
        continue;
    }

    $filename = "product_{$prodId}_{$ts}.jpg";
    $destPath = $posUpload . '/' . $filename;

    // Resize to 300x300 cropped square
    $src = imagecreatefromjpeg($psPath);
    if (!$src) {
        echo "FAIL: could not read $psPath\n";
        continue;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $size = min($w, $h);
    $x = ($w - $size) / 2;
    $y = ($h - $size) / 2;

    $thumb = imagecreatetruecolor(300, 300);
    imagecopyresampled($thumb, $src, 0, 0, (int)$x, (int)$y, 300, 300, $size, $size);
    imagejpeg($thumb, $destPath, 85);
    imagedestroy($src);
    imagedestroy($thumb);

    // Insert DB record
    $stmt = $db->prepare('INSERT INTO product_images (product_id, filename, sort_order) VALUES (?, ?, 0)');
    $stmt->execute([$prodId, $filename]);

    echo "OK: product $prodId <- PS image $imgId ($filename)\n";
}

echo "\nDone. Delete this script.\n";
