<?php
/**
 * Lock screen — shown when another session has DayClose open.
 * Variables: $lockerName, $lockedAt, $date
 */
?>
<div class="container" style="max-width:500px; margin-top:100px;">
    <div class="card shadow-sm">
        <div class="card-body text-center p-4">
            <h4 class="text-warning mb-3"><i class="bi bi-lock-fill"></i> Day Close In Use</h4>
            <p class="mb-2">
                <strong><?= e($lockerName) ?></strong> is currently working on the Day Close for
                <strong><?= e($date) ?></strong>.
            </p>
            <p class="text-muted small">
                Started at <?= e($lockedAt) ?>. Lock auto-expires after 30 minutes of inactivity.
            </p>
            <a href="<?= baseUrl('dayclose') ?>" class="btn btn-outline-secondary mt-3">Back</a>
        </div>
    </div>
</div>
