<?php
/**
 * Full-screen POS terminal layout (no navbar).
 * Variables: $pageTitle, $content (via output buffering in views)
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <title><?= e($pageTitle ?? 'POS') ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= baseUrl('public/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= baseUrl('public/css/pos.css') ?>">
    <meta name="csrf-token" content="<?= e(generateCsrfToken()) ?>">
    <meta name="base-url" content="<?= baseUrl() ?>">
</head>
<body class="pos-body">
    <?= $content ?? '' ?>
    <script src="<?= baseUrl('public/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <?php if (!empty($scripts)): ?>
        <?php foreach ($scripts as $s): ?>
            <script src="<?= baseUrl($s) ?>?v=<?= filemtime(BASE_PATH . '/' . $s) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
