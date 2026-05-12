<?php
/**
 * DayClose entry page — date picker + staff dropdown.
 * Variables: $staff (array of pos_users)
 */
?>
<div id="screenPicker" class="screen active">
    <div class="dc-picker-card card shadow-sm">
        <div class="card-body text-center p-4">
            <h4 class="mb-1" style="color:var(--dc-olive);font-weight:700;">Day Close</h4>
            <p class="text-muted mb-4">End-of-day cash reconciliation</p>

            <div class="mb-3 text-start">
                <label class="form-label fw-semibold">Date</label>
                <input type="date" id="closeDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>

            <div id="existingCountAlert" class="dc-existing-alert mb-3" style="display:none;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong id="existingMsg"></strong>
                        <div id="lockMsg" class="text-danger small mt-1" style="display:none;"></div>
                    </div>
                    <div>
                        <a id="viewExistingBtn" href="#" class="btn btn-sm btn-outline-secondary me-1">View</a>
                        <a id="editExistingBtn" href="#" class="btn btn-sm btn-dc-tan">Edit</a>
                    </div>
                </div>
            </div>

            <div class="mb-4 text-start">
                <label class="form-label fw-semibold">Closed by</label>
                <select id="staffSelect" class="form-select">
                    <option value="">Select staff member...</option>
                    <?php foreach ($staff as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= e($s['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button class="btn btn-dc-tan w-100 py-2" id="btnStartClose">Start Close</button>

            <hr class="my-3">
            <a href="<?= baseUrl('dayclose/history') ?>" class="btn btn-outline-secondary btn-sm">View History</a>
        </div>
    </div>
</div>

<script>
    const BASE_URL = <?= json_encode(baseUrl()) ?>;
    const CSRF_TOKEN = <?= json_encode(generateCsrfToken()) ?>;
    const PREFILL = null;
    const PAGE_MODE = 'entry';
</script>
