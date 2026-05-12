// ── DayClose JS (embedded in POS) ──────────────────────────
// All functions namespaced under DC to avoid conflicts with POS globals.

const DC = {};

// ── CONFIG ──────────────────────────────────────────
const DC_CUP_WEIGHT = 14;
const DC_COINS = {
    toonie:  { weight: 6.92, value: 2.00, label: 'Toonie' },
    loonie:  { weight: 6.27, value: 1.00, label: 'Loonie' },
    quarter: { weight: 4.40, value: 0.25, label: 'Quarter' },
};
const DC_BILLS = [100, 50, 20, 10, 5];
const DC_REGISTERS = [
    { id: 'r1', name: 'Loose Tea', shortName: 'R1' },
    { id: 'r2', name: 'Tea Bar',   shortName: 'R2' },
];
const DC_R3 = { id: 'r3', name: 'Ice Tea', shortName: 'R3' };
const DC_FLOAT_TARGETS = { r1: 100, r2: 100, r3: 150 };
const DC_FLOAT_MODE = { r1: 'bills_fixed', r2: 'bills_fixed', r3: 'total_fixed' };

// ── STATE ───────────────────────────────────────────
let dcState = { staff: '', staffId: '', date: '', count: {}, float: {}, r3: {},
                r1_card: '', r1_tips: '', r2_card: '', r2_tips: '' };

function dcInitState() {
    DC_REGISTERS.forEach(r => {
        dcState.count[r.id] = { bills: {}, coins: {}, usd: 0 };
        DC_BILLS.forEach(b => dcState.count[r.id].bills[b] = 0);
        Object.keys(DC_COINS).forEach(c => dcState.count[r.id].coins[c] = 0);
        dcState.float[r.id] = { bills: {} };
        DC_BILLS.forEach(b => dcState.float[r.id].bills[b] = 0);
    });
    dcState.r3 = { total_sales: '', txn_count: '', gst: '', cash: '', card: '', tips: '' };
    dcState.r1_card = '';
    dcState.r1_tips = '';
    dcState.r2_card = '';
    dcState.r2_tips = '';
}
dcInitState();

// ── PREFILL FROM DB ─────────────────────────────────
function dcApplyPrefill() {
    if (typeof PREFILL === 'undefined' || !PREFILL) return;

    DC_REGISTERS.forEach(r => {
        const pc = PREFILL.count[r.id];
        if (!pc) return;
        DC_BILLS.forEach(b => { if (pc.bills[b] !== undefined) dcState.count[r.id].bills[b] = pc.bills[b]; });
        Object.keys(DC_COINS).forEach(c => { if (pc.coins[c] !== undefined) dcState.count[r.id].coins[c] = pc.coins[c]; });
        dcState.count[r.id].usd = pc.usd || 0;
    });
    DC_REGISTERS.forEach(r => {
        const pf = PREFILL.float[r.id];
        if (!pf) return;
        DC_BILLS.forEach(b => { if (pf.bills[b] !== undefined) dcState.float[r.id].bills[b] = pf.bills[b]; });
    });
    // R1/R2 card & tips
    dcState.r1_card = PREFILL.r1_card ?? '';
    dcState.r1_tips = PREFILL.r1_tips ?? '';
    dcState.r2_card = PREFILL.r2_card ?? '';
    dcState.r2_tips = PREFILL.r2_tips ?? '';

    if (PREFILL.r3) {
        dcState.r3 = {
            total_sales: PREFILL.r3.total_sales ?? '',
            txn_count:   PREFILL.r3.txn_count ?? '',
            gst:         PREFILL.r3.gst ?? '',
            cash:        PREFILL.r3.cash ?? '',
            card:        PREFILL.r3.card ?? '',
            tips:        PREFILL.r3.tips ?? '',
        };
    }
    dcState.staffId = PREFILL.closed_by;
    dcState.date = PREFILL.close_date;
    dcState.staff = PREFILL.staff_name;
}

// ── HELPERS ─────────────────────────────────────────
function dcFmt(n) {
    return '$' + Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function dcCoinCalc(weightG, coinKey) {
    if (weightG <= DC_CUP_WEIGHT) return { count: 0, value: 0 };
    const c = Math.floor((weightG - DC_CUP_WEIGHT) / DC_COINS[coinKey].weight);
    return { count: c, value: c * DC_COINS[coinKey].value };
}

function dcGetRegBillTotal(regId) {
    let t = 0;
    DC_BILLS.forEach(b => t += (dcState.count[regId].bills[b] || 0) * b);
    return t;
}

function dcGetRegCoinTotal(regId) {
    let t = 0;
    Object.keys(DC_COINS).forEach(c => {
        t += dcCoinCalc(dcState.count[regId].coins[c] || 0, c).value;
    });
    return t;
}

function dcGetRegCADTotal(regId) {
    return dcGetRegBillTotal(regId) + dcGetRegCoinTotal(regId);
}

function dcGetR3Cash() {
    return parseFloat(dcState.r3.cash) || 0;
}

function dcGetGrandCAD() {
    let t = 0;
    DC_REGISTERS.forEach(r => t += dcGetRegCADTotal(r.id));
    t += dcGetR3Cash();
    return t;
}

function dcGetGrandUSD() {
    let t = 0;
    DC_REGISTERS.forEach(r => t += (dcState.count[r.id].usd || 0));
    return t;
}

function dcPoolBills() {
    const pool = {};
    DC_BILLS.forEach(b => {
        pool[b] = 0;
        DC_REGISTERS.forEach(r => pool[b] += (dcState.count[r.id].bills[b] || 0));
    });
    return pool;
}

function dcGetFloatBanknoteTotal(regId) {
    let t = 0;
    DC_BILLS.forEach(b => t += (dcState.float[regId].bills[b] || 0) * b);
    return t;
}

function dcGetFloatTotal(regId) {
    return dcGetFloatBanknoteTotal(regId) + dcGetRegCoinTotal(regId);
}

function dcGetDepositTotal() {
    const pool = dcPoolBills();
    let dep = 0;
    DC_BILLS.forEach(b => {
        let allocated = 0;
        DC_REGISTERS.forEach(r => allocated += (dcState.float[r.id].bills[b] || 0));
        dep += (pool[b] - allocated) * b;
    });
    return dep;
}

// ── ENTRY PAGE: DATE CHECK ──────────────────────────
(function() {
    const dateInput = document.getElementById('closeDate');
    const alertBox = document.getElementById('existingCountAlert');
    if (!dateInput || !alertBox || typeof PAGE_MODE === 'undefined' || PAGE_MODE !== 'entry') return;

    function checkDate() {
        const date = dateInput.value;
        if (!date) { alertBox.style.display = 'none'; return; }

        fetch(BASE_URL + 'dayclose/check-date', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body: JSON.stringify({ date: date })
        })
        .then(r => r.json())
        .then(data => {
            if (data.exists) {
                const isIncomplete = data.status === 'incomplete';
                document.getElementById('existingMsg').textContent = isIncomplete
                    ? 'Incomplete count for ' + date + (data.staff_name ? ' by ' + data.staff_name : '')
                    : 'Count exists for ' + date + ' (completed)' + (data.staff_name ? ' by ' + data.staff_name : '');
                const viewBtn = document.getElementById('viewExistingBtn');
                const editBtn = document.getElementById('editExistingBtn');
                viewBtn.href = BASE_URL + 'dayclose/summary?date=' + date;
                const staffId = document.getElementById('staffSelect').value || data.closed_by;
                editBtn.href = BASE_URL + 'dayclose/count?date=' + date + '&staff=' + staffId;
                // Hide View for incomplete (no summary yet), change Edit label
                viewBtn.style.display = isIncomplete ? 'none' : '';
                editBtn.textContent = isIncomplete ? 'Resume' : 'Edit';
                alertBox.style.display = 'block';

                // Lock info
                const lockEl = document.getElementById('lockMsg');
                if (data.locked && lockEl) {
                    lockEl.textContent = 'Currently locked by ' + data.locker;
                    lockEl.style.display = 'block';
                } else if (lockEl) {
                    lockEl.style.display = 'none';
                }
            } else {
                alertBox.style.display = 'none';
            }
        })
        .catch(() => { alertBox.style.display = 'none'; });
    }

    dateInput.addEventListener('change', checkDate);
    checkDate();

    const btnStart = document.getElementById('btnStartClose');
    if (btnStart) {
        btnStart.addEventListener('click', function() {
            const staffId = document.getElementById('staffSelect').value;
            const date = dateInput.value;
            if (!staffId) { alert('Please select a staff member.'); return; }
            if (!date) { alert('Please select a date.'); return; }
            window.location.href = BASE_URL + 'dayclose/count?date=' +
                encodeURIComponent(date) + '&staff=' + encodeURIComponent(staffId);
        });
    }
})();

// ── NAVIGATION ──────────────────────────────────────
function dcShowScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    window.scrollTo(0, 0);
}

// ── COUNT SCREEN ────────────────────────────────────
function dcBuildCountScreen() {
    const wrap = document.getElementById('countColumns');
    if (!wrap) return;
    wrap.innerHTML = '';

    // R1 & R2 — full bill/coin cards
    DC_REGISTERS.forEach(reg => {
        const col = document.createElement('div');
        col.className = 'col-md-4';
        col.innerHTML = dcBuildRegisterCard(reg);
        wrap.appendChild(col);
    });

    // R3 — manual entry card
    const r3col = document.createElement('div');
    r3col.className = 'col-md-4';
    r3col.innerHTML = dcBuildR3Card();
    wrap.appendChild(r3col);

    dcAttachCountListeners();
    dcUpdateCountTotals();
}

function dcBuildRegisterCard(reg) {
    let html = `<div class="card shadow-sm h-100">
        <div class="dc-card-header">${reg.shortName} — ${reg.name}</div>
        <div class="card-body p-3">`;

    // BILLS
    html += `<div class="dc-section-header">Bills (CAD)</div>`;
    DC_BILLS.forEach(b => {
        const val = dcState.count[reg.id].bills[b] || 0;
        html += `<div class="dc-denom-row">
            <span class="dc-denom-label">$${b}</span>
            <span class="dc-denom-x">&times;</span>
            <input type="number" min="0" class="dc-denom-input count-bill"
                   data-reg="${reg.id}" data-bill="${b}" value="${val}" inputmode="numeric">
            <span class="dc-denom-eq">=</span>
            <span class="dc-denom-amount" id="amt_${reg.id}_b${b}">$0.00</span>
        </div>`;
    });
    html += `<div class="dc-subtotal-row">
        <span>Bills</span>
        <span id="sub_${reg.id}_bills">$0.00</span>
    </div>`;

    // COINS
    html += `<div class="dc-section-header mt-3">Coins (Cup Weight)</div>
             <div class="dc-coin-info mb-1">14 g cup deducted</div>`;
    Object.entries(DC_COINS).forEach(([key, c]) => {
        const val = dcState.count[reg.id].coins[key] || 0;
        html += `<div class="dc-denom-row">
            <span class="dc-denom-label">${c.label}</span>
            <input type="number" min="0" step="any" class="dc-denom-input count-coin"
                   data-reg="${reg.id}" data-coin="${key}" value="${val}" placeholder="g" inputmode="decimal">
            <span class="dc-denom-eq">g</span>
        </div>
        <div class="dc-coin-count ms-2 mb-1" id="cc_${reg.id}_${key}">0 coins = $0.00</div>`;
    });
    html += `<div class="dc-subtotal-row">
        <span>Coins</span>
        <span id="sub_${reg.id}_coins">$0.00</span>
    </div>`;

    // USD
    const usdVal = dcState.count[reg.id].usd || 0;
    html += `<div class="dc-section-header mt-3">US Cash</div>
        <div class="dc-usd-box">
            <div class="dc-denom-row">
                <span class="dc-denom-label">USD $</span>
                <input type="number" min="0" step="0.01" class="dc-denom-input count-usd"
                       data-reg="${reg.id}" value="${usdVal}" style="width:90px;" inputmode="decimal">
            </div>
            <div class="text-end fw-bold mt-1" id="sub_${reg.id}_usd">$0.00 USD</div>
        </div>`;

    html += `</div></div>`;
    return html;
}

function dcBuildR3Card() {
    const r3 = dcState.r3;
    return `<div class="card shadow-sm h-100">
        <div class="dc-card-header">R3 — Ice Tea <span class="ms-2" style="font-weight:400;font-size:0.82rem;">(Manual Entry)</span></div>
        <div class="card-body p-3">
            <div class="dc-section-header">End-of-Day Totals</div>
            <p class="dc-coin-info mb-3">Enter totals from the register tape / POS summary.</p>

            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Total Sales ($)</label>
                <input type="number" step="0.01" min="0" class="form-control r3-field"
                       data-field="total_sales" value="${r3.total_sales}" inputmode="decimal">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Transaction Count</label>
                <input type="number" min="0" class="form-control r3-field"
                       data-field="txn_count" value="${r3.txn_count}" inputmode="numeric">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">GST ($)</label>
                <input type="number" step="0.01" min="0" class="form-control r3-field"
                       data-field="gst" value="${r3.gst}" inputmode="decimal">
            </div>
            <hr>
            <div class="dc-section-header">Payment Breakdown</div>
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Cash Amount ($)</label>
                <input type="number" step="0.01" min="0" class="form-control r3-field"
                       data-field="cash" value="${r3.cash}" inputmode="decimal">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Card Amount ($)</label>
                <input type="number" step="0.01" min="0" class="form-control r3-field"
                       data-field="card" value="${r3.card}" inputmode="decimal">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Tips ($)</label>
                <input type="number" step="0.01" min="0" class="form-control r3-field"
                       data-field="tips" value="${r3.tips}" inputmode="decimal">
            </div>

            <div class="dc-subtotal-row mt-3">
                <span>R3 Cash</span>
                <span id="sub_r3_cash">$0.00</span>
            </div>
        </div>
    </div>`;
}

function dcAttachCountListeners() {
    document.querySelectorAll('.count-bill').forEach(inp => {
        inp.addEventListener('input', function() {
            dcState.count[this.dataset.reg].bills[parseInt(this.dataset.bill)] = parseInt(this.value) || 0;
            dcUpdateCountTotals();
        });
        inp.addEventListener('focus', function() { if (this.value === '0') this.select(); });
    });
    document.querySelectorAll('.count-coin').forEach(inp => {
        inp.addEventListener('input', function() {
            dcState.count[this.dataset.reg].coins[this.dataset.coin] = parseFloat(this.value) || 0;
            dcUpdateCountTotals();
        });
        inp.addEventListener('focus', function() { if (this.value === '0') this.select(); });
    });
    document.querySelectorAll('.count-usd').forEach(inp => {
        inp.addEventListener('input', function() {
            dcState.count[this.dataset.reg].usd = parseFloat(this.value) || 0;
            dcUpdateCountTotals();
        });
        inp.addEventListener('focus', function() { if (this.value === '0') this.select(); });
    });
    document.querySelectorAll('.r3-field').forEach(inp => {
        inp.addEventListener('input', function() {
            dcState.r3[this.dataset.field] = this.value;
            dcUpdateCountTotals();
        });
    });
}

function dcUpdateCountTotals() {
    DC_REGISTERS.forEach(reg => {
        let billTotal = 0;
        DC_BILLS.forEach(b => {
            const cnt = dcState.count[reg.id].bills[b] || 0;
            const amt = cnt * b;
            billTotal += amt;
            const el = document.getElementById(`amt_${reg.id}_b${b}`);
            if (el) el.textContent = dcFmt(amt);
        });
        const subBills = document.getElementById(`sub_${reg.id}_bills`);
        if (subBills) subBills.textContent = dcFmt(billTotal);

        let coinTotal = 0;
        Object.entries(DC_COINS).forEach(([key, c]) => {
            const r = dcCoinCalc(dcState.count[reg.id].coins[key] || 0, key);
            coinTotal += r.value;
            const el = document.getElementById(`cc_${reg.id}_${key}`);
            if (el) el.textContent = `${r.count} coin${r.count !== 1 ? 's' : ''} = ${dcFmt(r.value)}`;
        });
        const subCoins = document.getElementById(`sub_${reg.id}_coins`);
        if (subCoins) subCoins.textContent = dcFmt(coinTotal);

        const subUsd = document.getElementById(`sub_${reg.id}_usd`);
        if (subUsd) subUsd.textContent = dcFmt(dcState.count[reg.id].usd) + ' USD';
    });

    // R3 cash display
    const subR3 = document.getElementById('sub_r3_cash');
    if (subR3) subR3.textContent = dcFmt(dcGetR3Cash());

    // Footer totals
    DC_REGISTERS.forEach(reg => {
        const el = document.getElementById(`ft${reg.shortName}`);
        if (el) el.textContent = dcFmt(dcGetRegCADTotal(reg.id));
    });
    const ftR3 = document.getElementById('ftR3');
    if (ftR3) ftR3.textContent = dcFmt(dcGetR3Cash());
    const ftTotal = document.getElementById('ftTotal');
    if (ftTotal) ftTotal.textContent = 'TOTAL ' + dcFmt(dcGetGrandCAD()) + ' CAD';
}

DC.clearForm = function() {
    if (!confirm('Clear all entered values?')) return;
    dcInitState();
    dcBuildCountScreen();
};

// ── CONFIRMATION MODAL ──────────────────────────────
DC.showConfirmModal = function() {
    const body = document.getElementById('confirmBody');
    let html = '<div class="row g-3">';

    // R1 & R2
    DC_REGISTERS.forEach(reg => {
        html += `<div class="col-md-4"><h6 class="fw-bold" style="color:var(--dc-olive);">${reg.shortName} — ${reg.name}</h6>`;
        html += '<div class="dc-section-header">Bills</div>';
        DC_BILLS.forEach(b => {
            const cnt = dcState.count[reg.id].bills[b] || 0;
            if (cnt > 0) html += `<div class="d-flex justify-content-between"><span>$${b} &times; ${cnt}</span><span class="fw-bold">${dcFmt(cnt * b)}</span></div>`;
        });
        html += `<div class="border-top mt-1 pt-1 fw-bold d-flex justify-content-between"><span>Bills</span><span>${dcFmt(dcGetRegBillTotal(reg.id))}</span></div>`;

        html += '<div class="dc-section-header mt-2">Coins</div>';
        Object.entries(DC_COINS).forEach(([key, c]) => {
            const r = dcCoinCalc(dcState.count[reg.id].coins[key] || 0, key);
            if (r.count > 0) html += `<div class="d-flex justify-content-between"><span>${c.label} &times; ${r.count}</span><span class="fw-bold">${dcFmt(r.value)}</span></div>`;
        });
        html += `<div class="border-top mt-1 pt-1 fw-bold d-flex justify-content-between"><span>Coins</span><span>${dcFmt(dcGetRegCoinTotal(reg.id))}</span></div>`;

        html += `<div class="d-flex justify-content-between mt-2 pt-2" style="border-top:2px solid var(--dc-olive);font-weight:700;font-size:1.05rem;color:var(--dc-olive);">
            <span>${reg.shortName} Total (CAD)</span><span>${dcFmt(dcGetRegCADTotal(reg.id))}</span></div>`;

        if (dcState.count[reg.id].usd > 0) {
            html += `<div class="dc-usd-box mt-2 p-2"><div class="d-flex justify-content-between"><span>US Cash</span><span class="fw-bold">${dcFmt(dcState.count[reg.id].usd)} USD</span></div></div>`;
        }
        html += '</div>';
    });

    // R3
    html += `<div class="col-md-4"><h6 class="fw-bold" style="color:var(--dc-olive);">R3 — Ice Tea</h6>`;
    html += '<div class="dc-section-header">Manual Entry</div>';
    const r3 = dcState.r3;
    if (r3.total_sales) html += `<div class="d-flex justify-content-between"><span>Total Sales</span><span class="fw-bold">${dcFmt(parseFloat(r3.total_sales))}</span></div>`;
    if (r3.txn_count) html += `<div class="d-flex justify-content-between"><span>Transactions</span><span class="fw-bold">${r3.txn_count}</span></div>`;
    if (r3.gst) html += `<div class="d-flex justify-content-between"><span>GST</span><span class="fw-bold">${dcFmt(parseFloat(r3.gst))}</span></div>`;
    if (r3.cash) html += `<div class="d-flex justify-content-between"><span>Cash</span><span class="fw-bold">${dcFmt(parseFloat(r3.cash))}</span></div>`;
    if (r3.card) html += `<div class="d-flex justify-content-between"><span>Card</span><span class="fw-bold">${dcFmt(parseFloat(r3.card))}</span></div>`;
    if (r3.tips) html += `<div class="d-flex justify-content-between"><span>Tips</span><span class="fw-bold">${dcFmt(parseFloat(r3.tips))}</span></div>`;
    html += '</div>';

    html += '</div>';

    // Card batch & tips summary
    const hasCardTips = dcState.r1_card || dcState.r1_tips || dcState.r2_card || dcState.r2_tips || r3.card || r3.tips;
    if (hasCardTips) {
        html += `<hr><div class="row g-3"><div class="col-12"><h6 class="fw-bold" style="color:var(--dc-olive);">Card Batch & Tips</h6></div>`;
        [{reg: 'R1 Loose Tea', card: dcState.r1_card, tips: dcState.r1_tips},
         {reg: 'R2 Tea Bar',   card: dcState.r2_card, tips: dcState.r2_tips},
         {reg: 'R3 Ice Tea',   card: r3.card,         tips: r3.tips}].forEach(item => {
            if (item.card || item.tips) {
                html += `<div class="col-md-4"><strong>${item.reg}</strong><br>`;
                if (item.card) html += `Card Batch: ${dcFmt(parseFloat(item.card))}<br>`;
                if (item.tips) html += `Tips: ${dcFmt(parseFloat(item.tips))}`;
                html += `</div>`;
            }
        });
        html += '</div>';
    }

    html += `<hr><div class="d-flex justify-content-between align-items-center">
        <span class="fw-bold" style="font-size:1.1rem;">Total Cash (CAD)</span>
        <span style="font-family:'Courier New',monospace;font-size:1.4rem;font-weight:700;color:var(--dc-olive);">${dcFmt(dcGetGrandCAD())}</span>
    </div>`;

    body.innerHTML = html;
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
};

DC.goToFloat = function() {
    bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
    dcBuildFloatScreen();
    dcShowScreen('screenFloat');
};

// ── FLOAT PREP SCREEN ───────────────────────────────
function dcAllocateBills(avail, target) {
    const denoms = [...DC_BILLS].reverse(); // smallest first
    const alloc = {};
    DC_BILLS.forEach(b => alloc[b] = 0);
    let remaining = target;

    for (const d of denoms) {
        const use = Math.min(Math.floor(remaining / d), avail[d] || 0);
        alloc[d] = use;
        remaining -= use * d;
    }
    if (remaining <= 0) return alloc;

    for (let i = 0; i < denoms.length && remaining > 0; i++) {
        for (let j = i + 1; j < denoms.length && remaining > 0; j++) {
            const small = denoms[i];
            const large = denoms[j];
            const largeAvail = (avail[large] || 0) - alloc[large];
            if (largeAvail <= 0) continue;
            const giveBack = (large - remaining) / small;
            if (giveBack === Math.floor(giveBack) && giveBack >= 0 && giveBack <= alloc[small]) {
                alloc[small] -= giveBack;
                alloc[large] += 1;
                remaining = 0;
            }
        }
    }
    return alloc;
}

function dcAutoAllocateFloats() {
    let hasPrefill = false;
    DC_REGISTERS.forEach(reg => {
        DC_BILLS.forEach(b => { if (dcState.float[reg.id].bills[b] > 0) hasPrefill = true; });
    });
    if (hasPrefill) return;

    DC_REGISTERS.forEach(reg => {
        const coinTotal = dcGetRegCoinTotal(reg.id);
        let billTarget;
        if (DC_FLOAT_MODE[reg.id] === 'total_fixed') {
            billTarget = Math.max(0, DC_FLOAT_TARGETS[reg.id] - coinTotal);
        } else {
            billTarget = DC_FLOAT_TARGETS[reg.id];
        }
        const avail = {};
        DC_BILLS.forEach(b => avail[b] = dcState.count[reg.id].bills[b] || 0);
        const alloc = dcAllocateBills(avail, billTarget);
        DC_BILLS.forEach(b => dcState.float[reg.id].bills[b] = alloc[b]);
    });
}

function dcBuildFloatScreen() {
    dcAutoAllocateFloats();
    const wrap = document.getElementById('floatColumns');
    if (!wrap) return;
    wrap.innerHTML = '';

    // Only R1 & R2 get float cards (R3 float is fixed at $150)
    DC_REGISTERS.forEach(reg => {
        const col = document.createElement('div');
        col.className = 'col-md-6';
        col.innerHTML = dcBuildFloatCard(reg);
        wrap.appendChild(col);
    });

    dcBuildCardTipsSection();
    dcAttachFloatListeners();
    dcUpdateFloatTotals();
}

function dcBuildCardTipsSection() {
    const wrap = document.getElementById('cardTipsSection');
    if (!wrap) return;
    wrap.innerHTML = '';

    // R1 — editable
    const r1Col = document.createElement('div');
    r1Col.className = 'col-md-4';
    r1Col.innerHTML = `<div class="card shadow-sm h-100">
        <div class="dc-card-header">R1 — Loose Tea</div>
        <div class="card-body p-3">
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Card Batch Total ($)</label>
                <input type="number" step="0.01" min="0" class="form-control cardtips-field"
                       data-field="r1_card" value="${dcState.r1_card}" inputmode="decimal"
                       placeholder="Moneris batch total">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Tips ($)</label>
                <input type="number" step="0.01" min="0" class="form-control cardtips-field"
                       data-field="r1_tips" value="${dcState.r1_tips}" inputmode="decimal"
                       placeholder="Total tips">
            </div>
        </div>
    </div>`;
    wrap.appendChild(r1Col);

    // R2 — editable
    const r2Col = document.createElement('div');
    r2Col.className = 'col-md-4';
    r2Col.innerHTML = `<div class="card shadow-sm h-100">
        <div class="dc-card-header">R2 — Tea Bar</div>
        <div class="card-body p-3">
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Card Batch Total ($)</label>
                <input type="number" step="0.01" min="0" class="form-control cardtips-field"
                       data-field="r2_card" value="${dcState.r2_card}" inputmode="decimal"
                       placeholder="Moneris batch total">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Tips ($)</label>
                <input type="number" step="0.01" min="0" class="form-control cardtips-field"
                       data-field="r2_tips" value="${dcState.r2_tips}" inputmode="decimal"
                       placeholder="Total tips">
            </div>
        </div>
    </div>`;
    wrap.appendChild(r2Col);

    // R3 — read-only (already entered on count screen)
    const r3 = dcState.r3;
    const r3Card = r3.card ? dcFmt(parseFloat(r3.card)) : '—';
    const r3Tips = r3.tips ? dcFmt(parseFloat(r3.tips)) : '—';
    const r3Col = document.createElement('div');
    r3Col.className = 'col-md-4';
    r3Col.innerHTML = `<div class="card shadow-sm h-100">
        <div class="dc-card-header">R3 — Ice Tea <span class="ms-2" style="font-weight:400;font-size:0.82rem;">(from count)</span></div>
        <div class="card-body p-3">
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Card Batch Total</label>
                <div class="form-control-plaintext fw-bold">${r3Card}</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold mb-1">Tips</label>
                <div class="form-control-plaintext fw-bold">${r3Tips}</div>
            </div>
        </div>
    </div>`;
    wrap.appendChild(r3Col);
}

function dcBuildFloatCard(reg) {
    const target = DC_FLOAT_TARGETS[reg.id];
    const mode = DC_FLOAT_MODE[reg.id];
    const targetLabel = mode === 'total_fixed'
        ? `Target: ${dcFmt(target)} total`
        : `Target: ${dcFmt(target)} bills`;
    let html = `<div class="card shadow-sm h-100">
        <div class="dc-card-header">${reg.shortName} — ${reg.name} <span class="ms-2" style="font-weight:400;font-size:0.82rem;">${targetLabel}</span></div>
        <div class="card-body p-3">`;

    html += `<div class="dc-section-header">Banknotes <span style="font-weight:400;font-size:0.75rem;text-transform:none;letter-spacing:0;color:#888;">— from ${reg.shortName} pool</span></div>`;
    DC_BILLS.forEach(b => {
        const allocated = dcState.float[reg.id].bills[b] || 0;
        html += `<div class="dc-denom-row">
            <span class="dc-denom-label">$${b}</span>
            <span class="dc-denom-x">&times;</span>
            <input type="number" min="0" class="dc-denom-input float-bill"
                   data-reg="${reg.id}" data-bill="${b}" value="${allocated}" inputmode="numeric">
            <span class="dc-pool-badge" id="pool_${reg.id}_b${b}"></span>
        </div>`;
    });
    html += `<div class="dc-subtotal-row">
        <span>Banknotes</span>
        <span id="fsub_${reg.id}_bills">$0.00</span>
    </div>`;

    const coinLabel = mode === 'total_fixed'
        ? 'Coins (toward target)'
        : 'Coins (extra — stays in register)';
    html += `<div class="dc-section-header mt-3">${coinLabel}</div>`;
    Object.entries(DC_COINS).forEach(([key, c]) => {
        const r = dcCoinCalc(dcState.count[reg.id].coins[key] || 0, key);
        html += `<div class="d-flex justify-content-between"><span>${c.label}: ${r.count}</span><span>${dcFmt(r.value)}</span></div>`;
    });
    html += `<div class="border-top mt-1 pt-1 fw-bold d-flex justify-content-between"><span>Coins</span><span>${dcFmt(dcGetRegCoinTotal(reg.id))}</span></div>`;

    html += `<div class="dc-subtotal-row mt-2" style="border-top:3px solid var(--dc-olive);">
        <span>Total Float</span>
        <span id="fsub_${reg.id}_total">$0.00</span>
    </div>`;
    html += `<div id="float_${reg.id}_warning"></div>`;

    html += `</div></div>`;
    return html;
}

function dcAttachFloatListeners() {
    document.querySelectorAll('.float-bill').forEach(inp => {
        inp.addEventListener('input', function() {
            dcState.float[this.dataset.reg].bills[parseInt(this.dataset.bill)] = parseInt(this.value) || 0;
            dcUpdateFloatTotals();
        });
        inp.addEventListener('focus', function() { if (this.value === '0') this.select(); });
    });

    // Card/tips inputs
    document.querySelectorAll('.cardtips-field').forEach(inp => {
        inp.addEventListener('input', function() {
            dcState[this.dataset.field] = this.value;
        });
    });

    // Actual deposit input
    const depInput = document.getElementById('actualDeposit');
    if (depInput) {
        depInput.addEventListener('input', dcUpdateDepositVariance);
    }
}

function dcUpdateFloatTotals() {
    DC_REGISTERS.forEach(r => {
        DC_BILLS.forEach(b => {
            const el = document.getElementById(`pool_${r.id}_b${b}`);
            if (el) {
                const counted = dcState.count[r.id].bills[b] || 0;
                const allocated = dcState.float[r.id].bills[b] || 0;
                const remaining = counted - allocated;
                el.textContent = `avail: ${remaining}`;
                el.style.color = remaining < 0 ? '#dc3545' : '#888';
            }
        });
    });

    DC_REGISTERS.forEach(reg => {
        const banknoteTotal = dcGetFloatBanknoteTotal(reg.id);
        const coinTotal = dcGetRegCoinTotal(reg.id);
        const floatTotal = banknoteTotal + coinTotal;
        const target = DC_FLOAT_TARGETS[reg.id];
        const mode = DC_FLOAT_MODE[reg.id];

        const isGood = mode === 'total_fixed'
            ? floatTotal >= target
            : banknoteTotal >= target;

        const subBills = document.getElementById(`fsub_${reg.id}_bills`);
        if (subBills) subBills.innerHTML = dcFmt(banknoteTotal) + (isGood
            ? ' <span style="color:#28a745;font-size:1.1rem;margin-left:4px;">&#10004;</span>' : '');
        const subTotal = document.getElementById(`fsub_${reg.id}_total`);
        if (subTotal) subTotal.textContent = dcFmt(floatTotal);

        const warn = document.getElementById(`float_${reg.id}_warning`);
        if (warn) {
            if (floatTotal < target) {
                const short = target - floatTotal;
                warn.innerHTML = `<div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">
                    <strong>Short ${dcFmt(short)}</strong> — need to buy from another till</div>`;
            } else if (mode === 'bills_fixed' && banknoteTotal < target) {
                const gap = target - banknoteTotal;
                warn.innerHTML = `<div class="alert alert-info py-1 px-2 mt-2 mb-0 small">
                    Bills short ${dcFmt(gap)} — covered by coins</div>`;
            } else {
                warn.innerHTML = '';
            }
        }
    });

    // Footer
    DC_REGISTERS.forEach(reg => {
        const el = document.getElementById(`ff${reg.shortName}`);
        if (el) el.textContent = dcFmt(dcGetFloatTotal(reg.id));
    });
    const ffDep = document.getElementById('ffDeposit');
    if (ffDep) ffDep.textContent = 'Deposit: ' + dcFmt(dcGetDepositTotal()) + ' CAD';

    dcUpdateDepositSection();
}

function dcUpdateDepositSection() {
    const pool = dcPoolBills();
    let html = '';
    let total = 0;

    DC_BILLS.forEach(b => {
        let allocated = 0;
        DC_REGISTERS.forEach(r => allocated += (dcState.float[r.id].bills[b] || 0));
        const depCount = pool[b] - allocated;
        const depAmt = depCount * b;
        total += depAmt;
        if (depCount !== 0) {
            html += `<div class="d-flex justify-content-between">
                <span>$${b} &times; ${depCount}</span>
                <span class="fw-bold">${dcFmt(depAmt)}</span>
            </div>`;
        }
    });

    if (!html) html = '<div class="text-muted">No bills remaining</div>';
    const depBreak = document.getElementById('depositBreakdown');
    if (depBreak) depBreak.innerHTML = html;
    const depTotal = document.getElementById('depositTotal');
    if (depTotal) depTotal.textContent = dcFmt(total);

    // USD summary
    let usdHtml = '';
    DC_REGISTERS.forEach(reg => {
        if (dcState.count[reg.id].usd > 0) {
            usdHtml += `<div class="d-flex justify-content-between"><span>${reg.shortName} ${reg.name}</span><span class="fw-bold">${dcFmt(dcState.count[reg.id].usd)} USD</span></div>`;
        }
    });
    if (!usdHtml) usdHtml = '<div class="text-muted">No US cash</div>';
    const usdSum = document.getElementById('usdSummary');
    if (usdSum) usdSum.innerHTML = usdHtml;

    dcUpdateDepositVariance();
}

function dcUpdateDepositVariance() {
    const depInput = document.getElementById('actualDeposit');
    const varEl = document.getElementById('depositVariance');
    if (!depInput || !varEl) return;

    const actual = parseFloat(depInput.value) || 0;
    const expected = dcGetDepositTotal();
    if (!actual) { varEl.innerHTML = ''; return; }

    const diff = actual - expected;
    if (Math.abs(diff) < 0.01) {
        varEl.innerHTML = '<span class="text-success fw-bold">Matches expected deposit</span>';
    } else {
        const cls = diff > 0 ? 'text-success' : 'text-danger';
        varEl.innerHTML = `<span class="${cls} fw-bold">Variance: ${diff > 0 ? '+' : ''}${dcFmt(diff)}</span>`;
    }
}

DC.backToCount = function() {
    dcShowScreen('screenCount');
};

// ── SUBMIT / SAVE ───────────────────────────────────
DC.saveIncomplete = function() {
    if (!confirm('Save incomplete? Shifts will NOT be closed until you Save & Complete.')) return;
    dcDoSave(false);
};

DC.submitClose = function() {
    // Validate all 3 registers have data
    const r1Cad = dcGetRegCADTotal('r1');
    const r2Cad = dcGetRegCADTotal('r2');
    const r3Cash = parseFloat(dcState.r3.cash) || 0;
    const missing = [];
    if (r1Cad <= 0) missing.push('R1 Loose Tea');
    if (r2Cad <= 0) missing.push('R2 Tea Bar');
    if (!dcState.r3.cash && dcState.r3.cash !== '0') missing.push('R3 Ice Tea');
    if (missing.length > 0) {
        alert('Cannot complete — missing cash count for: ' + missing.join(', '));
        return;
    }
    dcDoSave(true);
};

function dcDoSave(complete) {
    const details = [];
    // R1 & R2 denomination details
    DC_REGISTERS.forEach(reg => {
        DC_BILLS.forEach(b => {
            const cnt = dcState.count[reg.id].bills[b] || 0;
            if (cnt > 0) {
                details.push({
                    register: reg.id,
                    denomination_type: 'bill',
                    denomination: String(b),
                    value: cnt,
                    calculated_amount: cnt * b
                });
            }
        });
        Object.entries(DC_COINS).forEach(([key, c]) => {
            const weight = dcState.count[reg.id].coins[key] || 0;
            if (weight > 0) {
                const calc = dcCoinCalc(weight, key);
                details.push({
                    register: reg.id,
                    denomination_type: 'coin',
                    denomination: key,
                    value: weight,
                    calculated_amount: calc.value
                });
            }
        });
        const usd = dcState.count[reg.id].usd || 0;
        if (usd > 0) {
            details.push({
                register: reg.id,
                denomination_type: 'usd',
                denomination: 'usd',
                value: usd,
                calculated_amount: usd
            });
        }
    });

    const floats = [];
    DC_REGISTERS.forEach(reg => {
        DC_BILLS.forEach(b => {
            const qty = dcState.float[reg.id].bills[b] || 0;
            if (qty > 0) {
                floats.push({
                    register: reg.id,
                    denomination: String(b),
                    quantity: qty
                });
            }
        });
    });

    const notes = document.getElementById('closingNotes')
        ? document.getElementById('closingNotes').value.trim() : '';
    const actualDeposit = document.getElementById('actualDeposit')
        ? document.getElementById('actualDeposit').value.trim() : '';

    const payload = {
        csrf_token: CSRF_TOKEN,
        close_date: dcState.date,
        closed_by: dcState.staffId,
        complete: complete,
        notes: notes,
        actual_deposit: actualDeposit,
        details: details,
        floats: floats,
        r1_card: dcState.r1_card || null,
        r1_tips: dcState.r1_tips || null,
        r2_card: dcState.r2_card || null,
        r2_tips: dcState.r2_tips || null,
        r3_total_sales: dcState.r3.total_sales || null,
        r3_txn_count: dcState.r3.txn_count || null,
        r3_gst: dcState.r3.gst || null,
        r3_cash: dcState.r3.cash || null,
        r3_card: dcState.r3.card || null,
        r3_tips: dcState.r3.tips || null,
    };

    const btnComplete = document.getElementById('btnSubmit');
    const btnIncomplete = document.getElementById('btnSaveIncomplete');
    if (btnComplete) { btnComplete.disabled = true; btnComplete.textContent = 'Saving...'; }
    if (btnIncomplete) { btnIncomplete.disabled = true; }

    fetch(BASE_URL + 'dayclose/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            if (data.complete) {
                window.location.href = BASE_URL + 'dayclose/summary?date=' + encodeURIComponent(dcState.date);
            } else {
                window.location.href = BASE_URL + 'dayclose?saved=incomplete';
            }
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
            if (btnComplete) { btnComplete.disabled = false; btnComplete.textContent = 'Save & Complete'; }
            if (btnIncomplete) { btnIncomplete.disabled = false; }
        }
    })
    .catch(err => {
        alert('Network error: ' + err.message);
        if (btnComplete) { btnComplete.disabled = false; btnComplete.textContent = 'Save & Complete'; }
        if (btnIncomplete) { btnIncomplete.disabled = false; }
    });
}

// ── LOCK HEARTBEAT ──────────────────────────────────
(function() {
    if (typeof PAGE_MODE === 'undefined' || PAGE_MODE !== 'count') return;

    // Heartbeat every 2 minutes
    setInterval(function() {
        if (!dcState.date) return;
        fetch(BASE_URL + 'dayclose/heartbeat?date=' + encodeURIComponent(dcState.date), {
            method: 'GET',
            credentials: 'same-origin'
        }).catch(() => {});
    }, 120000);

    // Release lock on navigate away
    window.addEventListener('beforeunload', function() {
        if (!dcState.date) return;
        navigator.sendBeacon(
            BASE_URL + 'dayclose/release-lock?date=' + encodeURIComponent(dcState.date)
        );
    });
})();

// ── INIT COUNT SCREEN (if on count page) ────────────
(function() {
    if (typeof PAGE_MODE === 'undefined' || PAGE_MODE !== 'count') return;

    dcApplyPrefill();

    // Set state from page vars if no prefill
    if (!PREFILL && typeof DC_INIT_DATE !== 'undefined') {
        dcState.date = DC_INIT_DATE;
        dcState.staffId = DC_INIT_STAFF_ID;
        dcState.staff = DC_INIT_STAFF_NAME;
    }

    if (document.getElementById('countColumns')) {
        dcBuildCountScreen();
    }
})();
