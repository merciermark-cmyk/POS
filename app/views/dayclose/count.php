<?php
/**
 * Count page — count screen + confirmation modal + float prep screen.
 * Variables: $date, $staffId, $staffName, $prefill (array|null)
 *
 * Layout/JS classes match the rebuilt standalone (no dc- prefix);
 * embedded CSS file (public/css/dayclose.css) provides matching styles.
 */
?>
<link rel="stylesheet" href="<?= baseUrl('public/css/dayclose.css') ?>?v=<?= filemtime(BASE_PATH . '/public/css/dayclose.css') ?>">
<!-- SCREEN: Cash Count -->
<div id="screenCount" class="screen active">
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0" style="color:var(--olive);font-weight:700;">
                Cash Count — <?= e($staffName) ?> — <?= e($date) ?>
            </h5>
            <a href="<?= baseUrl('dayclose') ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </div>
        <div class="row g-3" id="countColumns"></div>
    </div>
    <div class="sticky-footer" id="countFooter">
        <div class="d-flex gap-4 flex-wrap">
            <div class="register-total">R1 Loose Tea: <span id="ftR1">$0.00</span></div>
            <div class="register-total">R2 Tea Bar: <span id="ftR2">$0.00</span></div>
            <div class="register-total">R3 Ice Tea: <span id="ftR3">$0.00</span></div>
        </div>
        <div class="grand-total" id="ftTotal">$0.00</div>
    </div>
    <div class="container-fluid pb-3 pt-2">
        <div class="d-flex justify-content-between">
            <button class="btn btn-outline-secondary" onclick="DC.clearForm()">Clear form</button>
            <button class="btn btn-tan px-4" onclick="DC.showConfirmModal()">Continue to float prep</button>
        </div>
    </div>
</div>

<!-- CONFIRMATION MODAL -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--olive);color:#fff;">
                <h5 class="modal-title">Confirm Cash Count</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body modal-body-scroll" id="confirmBody"></div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-tan" onclick="DC.goToFloat()">Continue to float prep</button>
            </div>
        </div>
    </div>
</div>

<!-- SCREEN: Float Prep + Deposit -->
<div id="screenFloat" class="screen">
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0" style="color:var(--olive);font-weight:700;">Prepare next-day floats</h5>
            <button class="btn btn-outline-secondary btn-sm" onclick="DC.backToCount()">Back</button>
        </div>
        <div class="row g-3" id="floatColumns"></div>

        <hr class="my-4">
        <h5 class="mb-3" style="color:var(--olive);font-weight:700;">Deposit &amp; Notes</h5>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="deposit-box">
                    <div class="section-header">Deposit Envelope (CAD only)</div>
                    <div id="depositBreakdown"></div>
                    <div class="subtotal-row mt-2">
                        <span>Total Deposit</span>
                        <span id="depositTotal">$0.00</span>
                    </div>
                    <div class="mt-2 coin-info"><i class="bi bi-info-circle"></i> Coins stay in their registers — not deposited. US cash is held separately.</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="usd-box mb-3">
                    <div class="section-header" style="color:#856404;">US Cash — Held Separately</div>
                    <div id="usdSummary"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Notes (optional)</label>
                    <textarea id="closingNotes" class="form-control" rows="3"
                              placeholder="e.g. R2 was short a $5 we couldn't track down."><?= e($prefill['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-between mt-3 mb-5">
            <button class="btn btn-outline-secondary" onclick="DC.backToCount()">Back to count</button>
            <button class="btn btn-tan px-4" onclick="DC.submitClose()">Save &amp; Complete</button>
        </div>
    </div>
    <div class="sticky-footer" id="floatFooter">
        <div class="d-flex gap-4 flex-wrap">
            <div class="register-total">R1 Float: <span id="ffR1">$0.00</span></div>
            <div class="register-total">R2 Float: <span id="ffR2">$0.00</span></div>
            <div class="register-total">R3 Float: <span id="ffR3">$0.00</span></div>
        </div>
        <div class="grand-total" id="ffDeposit">Deposit: $0.00</div>
    </div>
</div>

<div id="dcKeypad"></div>

<script>
    const BASE_URL = <?= json_encode(baseUrl()) ?>;
    const CSRF_TOKEN = <?= json_encode(generateCsrfToken()) ?>;
    const PREFILL = <?= json_encode($prefill) ?>;
    window.FEATURE_SAFE_COIN = <?= defined('FEATURE_SAFE_COIN_SYSTEM') && FEATURE_SAFE_COIN_SYSTEM ? 'true' : 'false' ?>;
    // Set initial state (only used if no PREFILL)
    if (typeof PREFILL === 'undefined' || !PREFILL) {
        var DC_INIT_DATE       = <?= json_encode($date) ?>;
        var DC_INIT_STAFF_ID   = <?= json_encode($staffId) ?>;
        var DC_INIT_STAFF_NAME = <?= json_encode($staffName) ?>;
    }
</script>
