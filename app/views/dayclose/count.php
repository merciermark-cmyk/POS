<?php
/**
 * Count page — count screen + confirmation modal + float prep screen.
 * Variables: $date, $staffId, $staffName, $prefill (array|null)
 */
?>
<!-- SCREEN: Cash Count -->
<div id="screenCount" class="screen active">
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0" style="color:var(--dc-olive);font-weight:700;">
                Cash Count — <?= e($staffName) ?> — <?= e($date) ?>
            </h5>
            <a href="<?= baseUrl('dayclose') ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </div>
        <div class="row g-3" id="countColumns"></div>
    </div>
    <div class="dc-sticky-footer" id="countFooter">
        <div class="d-flex gap-4 flex-wrap">
            <div class="dc-register-total">R1 Loose Tea: <span id="ftR1">$0.00</span></div>
            <div class="dc-register-total">R2 Tea Bar: <span id="ftR2">$0.00</span></div>
            <div class="dc-register-total">R3 Ice Tea: <span id="ftR3">$0.00</span></div>
        </div>
        <div class="dc-grand-total" id="ftTotal">$0.00</div>
    </div>
    <div class="container-fluid pb-3 pt-2">
        <div class="d-flex justify-content-between">
            <button class="btn btn-outline-secondary" onclick="DC.clearForm()">Clear form</button>
            <button class="btn btn-dc-tan px-4" onclick="DC.showConfirmModal()">Continue to float prep</button>
        </div>
    </div>
</div>

<!-- CONFIRMATION MODAL -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--dc-olive);color:#fff;">
                <h5 class="modal-title">Confirm Cash Count</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body dc-modal-body-scroll" id="confirmBody"></div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-dc-tan" onclick="DC.goToFloat()">Continue to float prep</button>
            </div>
        </div>
    </div>
</div>

<!-- SCREEN: Float Prep + Deposit -->
<div id="screenFloat" class="screen">
    <div class="container-fluid py-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0" style="color:var(--dc-olive);font-weight:700;">Prepare next-day floats</h5>
            <button class="btn btn-outline-secondary btn-sm" onclick="DC.backToCount()">Back</button>
        </div>
        <div class="row g-3" id="floatColumns"></div>

        <hr class="my-4">
        <h5 class="mb-3" style="color:var(--dc-olive);font-weight:700;">Card Batch & Tips</h5>
        <div class="row g-3" id="cardTipsSection"></div>

        <hr class="my-4">
        <h5 class="mb-3" style="color:var(--dc-olive);font-weight:700;">Deposit & Notes</h5>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="dc-deposit-box">
                    <div class="dc-section-header">Expected Deposit (CAD only)</div>
                    <div id="depositBreakdown"></div>
                    <div class="dc-subtotal-row mt-2">
                        <span>Expected Deposit</span>
                        <span id="depositTotal">$0.00</span>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Actual Deposit Envelope ($)</label>
                        <input type="number" id="actualDeposit" class="form-control" step="0.01" min="0"
                               placeholder="Enter actual amount in envelope"
                               value="<?= e($prefill['actual_deposit'] ?? '') ?>">
                        <div id="depositVariance" class="small mt-1"></div>
                    </div>
                    <div class="mt-2 dc-coin-info">Coins stay in their registers — not deposited. US cash is held separately.</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="dc-usd-box mb-3">
                    <div class="dc-section-header" style="color:#856404;">US Cash — Held Separately</div>
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
            <div>
                <button class="btn btn-outline-secondary" onclick="DC.backToCount()">Back to count</button>
                <button class="btn btn-outline-warning ms-2" id="btnSaveIncomplete" onclick="DC.saveIncomplete()">Save Incomplete</button>
            </div>
            <button class="btn btn-dc-tan px-4" id="btnSubmit" onclick="DC.submitClose()">Save & Complete</button>
        </div>
    </div>
    <div class="dc-sticky-footer" id="floatFooter">
        <div class="d-flex gap-4 flex-wrap">
            <div class="dc-register-total">R1 Float: <span id="ffR1">$0.00</span></div>
            <div class="dc-register-total">R2 Float: <span id="ffR2">$0.00</span></div>
        </div>
        <div class="dc-grand-total" id="ffDeposit">Deposit: $0.00</div>
    </div>
</div>

<script>
    const BASE_URL = <?= json_encode(baseUrl()) ?>;
    const CSRF_TOKEN = <?= json_encode(generateCsrfToken()) ?>;
    const PREFILL = <?= json_encode($prefill) ?>;
    const PAGE_MODE = 'count';
    // Set state from URL params (for new counts)
    if (!PREFILL) {
        var DC_INIT_DATE = <?= json_encode($date) ?>;
        var DC_INIT_STAFF_ID = <?= json_encode($staffId) ?>;
        var DC_INIT_STAFF_NAME = <?= json_encode($staffName) ?>;
    }
</script>
