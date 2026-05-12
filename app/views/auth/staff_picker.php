<?php
$pageTitle = 'Select Staff';
$scripts = ['public/js/staff-picker.js'];
ob_start();
?>

<div class="staff-picker">
    <div class="staff-picker-header">
        <h1><?= e($settings['store_name'] ?? APP_NAME) ?></h1>
        <?php if ($shift): ?>
            <div class="shift-info">Shift #<?= (int)$shift['id'] ?> &mdash; Opened by <?= e($shift['username'] ?? '') ?></div>
        <?php endif; ?>
    </div>

    <?php $flash = getFlash('error'); if ($flash): ?>
        <div class="alert alert-danger" style="max-width:500px"><?= e($flash) ?></div>
    <?php endif; ?>
    <?php $flash = getFlash('success'); if ($flash): ?>
        <div class="alert alert-success" style="max-width:500px"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if (($heldOrderCount ?? 0) > 0): ?>
        <div class="alert alert-warning py-2 px-3 d-inline-block" style="max-width:500px">
            <strong><?= $heldOrderCount ?></strong> held order(s) this shift
        </div>
    <?php endif; ?>

    <div class="staff-picker-prompt">Who's ringing in?</div>

    <div class="staff-grid">
        <?php foreach ($staff as $member): ?>
            <div class="staff-card"
                 data-user-id="<?= (int)$member['id'] ?>"
                 data-has-code="<?= empty($member['pin']) ? '0' : '1' ?>"
                 data-pin-length="<?= strlen($member['pin'] ?? '') ?>"
                 data-username="<?= e($member['username']) ?>">
                <div class="staff-avatar"><?= e(mb_strtoupper(mb_substr($member['username'], 0, 2))) ?></div>
                <div class="staff-name"><?= e($member['username']) ?></div>
                <div class="staff-role"><?= e($member['role']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="staff-picker-footer">
        <a href="<?= baseUrl('pin') ?>" class="btn btn-outline-light btn-sm me-2">Manager Login</a>
        <?php if (isLoggedIn()): ?>
            <a href="<?= baseUrl('transactions') ?>" class="btn btn-outline-light btn-sm me-2">Transactions</a>
            <?php if (isManager()): ?>
                <a href="<?= baseUrl('reports/daily') ?>" class="btn btn-outline-light btn-sm me-2">Reports</a>
                <a href="<?= baseUrl('users') ?>" class="btn btn-outline-light btn-sm me-2">Admin</a>
            <?php endif; ?>
            <a href="<?= baseUrl('logout') ?>" class="btn btn-outline-light btn-sm">Logout</a>
        <?php else: ?>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden form for staff pick submission -->
<form method="post" action="<?= baseUrl('pick-staff') ?>" id="staffPickForm" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="user_id" value="">
    <input type="hidden" name="pin" value="">
</form>

<!-- Code entry overlay -->
<div class="code-overlay" id="codeOverlay">
    <div class="code-panel">
        <h3 id="codeOverlayName" class="mb-3"></h3>
        <p class="text-muted mb-3">Enter your PIN</p>
        <div id="codeError" class="alert alert-danger py-1 mb-2" style="display:none">Incorrect PIN</div>
        <div class="code-dots d-flex justify-content-center gap-3 mb-4" id="codeDots">
            <!-- dots generated dynamically based on PIN length -->
        </div>
        <div class="pin-pad">
            <?php for ($i = 1; $i <= 9; $i++): ?>
                <button type="button" class="btn btn-outline-secondary btn-pin btn-code-digit" data-digit="<?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
            <button type="button" class="btn btn-outline-danger btn-pin" data-action="clear">C</button>
            <button type="button" class="btn btn-outline-secondary btn-pin btn-code-digit" data-digit="0">0</button>
            <button type="button" class="btn btn-outline-warning btn-pin" data-action="backspace">&larr;</button>
        </div>
        <button type="button" class="btn btn-outline-secondary mt-3 w-100" data-action="cancel">Cancel</button>
    </div>
</div>

<?php
$content = ob_get_clean();
require APP_PATH . '/views/layouts/pos.php';
