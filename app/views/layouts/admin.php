<?php
/**
 * Standard Bootstrap navbar layout for admin/report pages.
 * Variables: $pageTitle, $content
 */
$currentPath = $_GET['url'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Admin') ?> — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= baseUrl('public/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= baseUrl('public/css/pos.css') ?>">
    <meta name="csrf-token" content="<?= e(generateCsrfToken()) ?>">
    <meta name="base-url" content="<?= baseUrl() ?>">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= baseUrl() ?>">POS</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#posNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="posNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= baseUrl('sale') ?>">Terminal</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= baseUrl('transactions') ?>">Transactions</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= str_starts_with($currentPath, 'dayclose') ? 'active' : '' ?>"
                           href="<?= baseUrl('dayclose') ?>">Close Registers</a>
                    </li>
                    <?php if (isManager()): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Reports</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= baseUrl('reports/daily') ?>">Daily Sales</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('reports/monthly') ?>">Monthly Sales</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('reports/product-sales') ?>">Product Sales</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('reports/transaction-search') ?>">Transaction Search</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('reports/hourly-sales') ?>">Hourly Sales</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('reports/cash-spot-check') ?>">Cash Spot Check</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('shift/history') ?>">Shift History</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= baseUrl('remote-auth') ?>">Remote Auth</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Admin</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= baseUrl('users') ?>">Users</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('terminals') ?>">Terminals</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('products') ?>">Products</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('modifiers') ?>">Modifiers</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('images') ?>">Product Images</a></li>
                            <li><a class="dropdown-item" href="<?= baseUrl('settings') ?>">Settings</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <?php
                    $__terminals = (new Terminal())->getActive();
                    $__currentTid = $_SESSION['pos_terminal_id'] ?? null;
                    $__currentName = 'No Terminal';
                    foreach ($__terminals as $__t) { if ($__t['id'] == $__currentTid) { $__currentName = $__t['name']; break; } }
                    ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle text-warning" href="#" data-bs-toggle="dropdown"><?= e($__currentName) ?></a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php foreach ($__terminals as $__t): ?>
                                <li><a class="dropdown-item <?= $__t['id'] == $__currentTid ? 'active' : '' ?>" href="<?= baseUrl('set-terminal/' . $__t['id']) ?>"><?= e($__t['name']) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <span class="nav-link text-light"><?= e(currentUser()['username'] ?: (currentOperator()['username'] ?? '')) ?></span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= baseUrl('sale') ?>">Back to Terminal</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-3">
        <?php $flash = getFlash('success'); if ($flash): ?>
            <div class="alert alert-success alert-dismissible fade show"><?= e($flash) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php $flash = getFlash('error'); if ($flash): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?= e($flash) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?= $content ?? '' ?>
    </div>

    <script src="<?= baseUrl('public/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <?php if (!empty($scripts)): ?>
        <?php foreach ($scripts as $s): ?>
            <script src="<?= baseUrl($s) ?>?v=<?= filemtime(BASE_PATH . '/' . $s) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
