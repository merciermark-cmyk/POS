<?php $pageTitle = 'Login'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= baseUrl('public/vendor/bootstrap/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= baseUrl('public/css/pos.css') ?>">
</head>
<body class="bg-dark d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card shadow" style="width:400px">
    <div class="card-body p-4">
        <h3 class="text-center mb-4"><?= e(APP_NAME) ?></h3>

        <?php $flash = getFlash('success'); if ($flash): ?>
            <div class="alert alert-success"><?= e($flash) ?></div>
        <?php endif; ?>
        <?php $flash = getFlash('error') ?: ($_SESSION['flash_error'] ?? null);
              unset($_SESSION['flash_error']);
              if ($flash): ?>
            <div class="alert alert-danger"><?= e($flash) ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control form-control-lg" required autofocus
                       value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <input type="password" name="password" id="password" class="form-control form-control-lg" required>
                    <button type="button" class="btn btn-outline-secondary" tabindex="-1"
                            onclick="let p=document.getElementById('password');let s=p.type==='password';p.type=s?'text':'password';this.textContent=s?'Hide':'Show'"
                            >Show</button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100">Log In</button>
        </form>

        <div class="text-center mt-3">
            <a href="<?= baseUrl('pin') ?>" class="text-muted">Use PIN instead</a>
        </div>
    </div>
</div>
</body>
</html>
