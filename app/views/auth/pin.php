<?php $pageTitle = 'PIN Login'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <title>PIN Login — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= baseUrl('public/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= baseUrl('public/css/pos.css') ?>">
</head>
<body class="bg-dark d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow" style="width:360px">
    <div class="card-body p-4 text-center">
        <h3 class="mb-4"><?= e(APP_NAME) ?></h3>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" id="pinForm">
            <input type="hidden" name="pin" id="pinInput" value="">

            <div class="pin-display mb-3">
                <div class="pin-dots d-flex justify-content-center gap-3">
                    <span class="pin-dot" data-index="0"></span>
                    <span class="pin-dot" data-index="1"></span>
                    <span class="pin-dot" data-index="2"></span>
                    <span class="pin-dot" data-index="3"></span>
                </div>
            </div>

            <div class="pin-pad">
                <?php for ($i = 1; $i <= 9; $i++): ?>
                    <button type="button" class="btn btn-outline-light btn-pin" data-digit="<?= $i ?>"><?= $i ?></button>
                <?php endfor; ?>
                <button type="button" class="btn btn-outline-danger btn-pin" data-action="clear">C</button>
                <button type="button" class="btn btn-outline-light btn-pin" data-digit="0">0</button>
                <button type="button" class="btn btn-outline-warning btn-pin" data-action="backspace">&larr;</button>
            </div>
        </form>

        <div class="mt-3">
            <a href="<?= baseUrl('login') ?>" class="text-muted">Use password instead</a>
        </div>
    </div>
</div>
<script src="<?= baseUrl('public/js/pin-pad.js') ?>"></script>
</body>
</html>
