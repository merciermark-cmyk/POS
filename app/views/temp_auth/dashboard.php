<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">

        <h3 class="mb-4">Remote Authorization</h3>

        <!-- Generate Section -->
        <div class="card mb-4">
            <div class="card-body text-center">
                <p class="text-muted mb-3">Generate a single-use code for staff to process a refund.</p>

                <button id="btnGenerate" class="btn btn-primary btn-lg w-100 mb-3" onclick="generateCode()">
                    Generate Authorization Code
                </button>

                <div id="codeDisplay" class="d-none">
                    <div id="codeValue" class="display-1 fw-bold font-monospace text-primary my-3 user-select-all"></div>
                    <div class="text-muted mb-2">
                        Expires in <span id="countdown" class="fw-bold text-danger"></span>
                    </div>
                    <p class="small text-muted">Single-use. Share this code with the cashier by phone.</p>
                </div>

                <div id="codeError" class="alert alert-danger d-none mt-3"></div>
            </div>
        </div>

        <!-- Recent Codes Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Recent Codes</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Generated</th>
                            <th>Status</th>
                            <th>Used By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentCodes)): ?>
                            <tr><td colspan="4" class="text-muted text-center py-3">No codes generated yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentCodes as $rc): ?>
                                <?php
                                $isUsed = !empty($rc['used_at']);
                                $isExpired = !$isUsed && strtotime($rc['expires_at']) < time();
                                $isActive = !$isUsed && !$isExpired;
                                $maskedCode = substr($rc['code'], 0, 2) . '****';
                                ?>
                                <tr>
                                    <td class="font-monospace"><?= $isActive ? e($rc['code']) : $maskedCode ?></td>
                                    <td><?= date('M j g:i A', strtotime($rc['created_at'])) ?></td>
                                    <td>
                                        <?php if ($isUsed): ?>
                                            <span class="badge bg-secondary">Used</span>
                                        <?php elseif ($isExpired): ?>
                                            <span class="badge bg-warning text-dark">Expired</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $isUsed ? e($rc['used_by_name']) : '—' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
let countdownInterval = null;

function generateCode() {
    const btn = document.getElementById('btnGenerate');
    const display = document.getElementById('codeDisplay');
    const errorDiv = document.getElementById('codeError');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    btn.disabled = true;
    btn.textContent = 'Generating...';
    errorDiv.classList.add('d-none');

    fetch('<?= baseUrl('remote-auth/generate') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + encodeURIComponent(csrfToken)
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            errorDiv.textContent = data.error;
            errorDiv.classList.remove('d-none');
            btn.disabled = false;
            btn.textContent = 'Generate Authorization Code';
            return;
        }

        document.getElementById('codeValue').textContent = data.code;
        display.classList.remove('d-none');
        btn.textContent = 'Generate New Code';
        btn.disabled = false;

        // Start countdown
        startCountdown(data.expires_at);
    })
    .catch(err => {
        errorDiv.textContent = 'Failed to generate code. Please try again.';
        errorDiv.classList.remove('d-none');
        btn.disabled = false;
        btn.textContent = 'Generate Authorization Code';
    });
}

function startCountdown(expiresAt) {
    if (countdownInterval) clearInterval(countdownInterval);

    const expiryTime = new Date(expiresAt + ' GMT-0700').getTime();
    const countdownEl = document.getElementById('countdown');

    function update() {
        const now = Date.now();
        const remaining = Math.max(0, Math.floor((expiryTime - now) / 1000));

        if (remaining <= 0) {
            countdownEl.textContent = 'EXPIRED';
            clearInterval(countdownInterval);
            return;
        }

        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;
        countdownEl.textContent = mins + ':' + String(secs).padStart(2, '0');
    }

    update();
    countdownInterval = setInterval(update, 1000);
}
</script>
