// Close Registers (embedded in POS app) — port of standalone rebuild.
// Routes use BASE_URL + 'dayclose/...' for embedded routing.

// ── CONFIG ──────────────────────────────────────────
const CUP_WEIGHT = 14;
const COINS = {
    toonie:  { weight: 6.92, value: 2.00, label: 'Toonie' },
    loonie:  { weight: 6.27, value: 1.00, label: 'Loonie' },
    quarter: { weight: 4.40, value: 0.25, label: 'Quarter' },
};
const BILLS = [100, 50, 20, 10, 5];
const REGISTERS = [
    { id: 'r1', name: 'Loose Tea', shortName: 'R1', hasTips: false, hasRegisterTape: false },
    { id: 'r2', name: 'Tea Bar',   shortName: 'R2', hasTips: true,  hasRegisterTape: false },
    { id: 'r3', name: 'Ice Tea',   shortName: 'R3', hasTips: true,  hasRegisterTape: true  },
];
const FLOAT_TARGETS = { r1: 100, r2: 100, r3: 150 };
// bills_fixed: target is always in bills, coins are extra on top
// total_fixed: target is total (coins count toward it, bills fill the gap)
const FLOAT_MODE = { r1: 'bills_fixed', r2: 'bills_fixed', r3: 'total_fixed' };

// ── STATE ───────────────────────────────────────────
let state = {
    staff: '', staffId: '', date: '',
    count: {}, float: {},
    tips: { r2: '', r3: '' },
    cardBatch: { r1: '', r2: '', r3: '' },
    registerTape: { total_sales: '', txn_count: '', gst: '', cash_sales: '', card_sales: '' },
};

function initState() {
    REGISTERS.forEach(r => {
        state.count[r.id] = { bills: {}, coins: {}, usd: 0 };
        BILLS.forEach(b => state.count[r.id].bills[b] = 0);
        Object.keys(COINS).forEach(c => state.count[r.id].coins[c] = 0);
        state.float[r.id] = { bills: {} };
        BILLS.forEach(b => state.float[r.id].bills[b] = 0);
    });
    state.tips = { r2: '', r3: '' };
    state.cardBatch = { r1: '', r2: '', r3: '' };
    state.registerTape = { total_sales: '', txn_count: '', gst: '', cash_sales: '', card_sales: '' };
}
initState();

// ── PREFILL FROM DB ─────────────────────────────────
if (typeof PREFILL !== 'undefined' && PREFILL) {
    REGISTERS.forEach(r => {
        const pc = PREFILL.count[r.id];
        if (!pc) return;
        BILLS.forEach(b => { if (pc.bills[b] !== undefined) state.count[r.id].bills[b] = pc.bills[b]; });
        Object.keys(COINS).forEach(c => { if (pc.coins[c] !== undefined) state.count[r.id].coins[c] = pc.coins[c]; });
        state.count[r.id].usd = pc.usd || 0;
    });
    REGISTERS.forEach(r => {
        const pf = PREFILL.float[r.id];
        if (!pf) return;
        BILLS.forEach(b => { if (pf.bills[b] !== undefined) state.float[r.id].bills[b] = pf.bills[b]; });
    });
    state.staffId = PREFILL.closed_by;
    state.date = PREFILL.close_date;
    state.staff = PREFILL.staff_name;

    if (PREFILL.tips) {
        state.tips.r2 = PREFILL.tips.r2 || '';
        state.tips.r3 = PREFILL.tips.r3 || '';
    }
    if (PREFILL.cardBatch) {
        state.cardBatch.r1 = PREFILL.cardBatch.r1 || '';
        state.cardBatch.r2 = PREFILL.cardBatch.r2 || '';
        state.cardBatch.r3 = PREFILL.cardBatch.r3 || '';
    }
    if (PREFILL.registerTape) {
        Object.keys(state.registerTape).forEach(k => {
            if (PREFILL.registerTape[k] !== undefined) state.registerTape[k] = PREFILL.registerTape[k];
        });
    }
}
// Initial state from embedded count.php script block (when no PREFILL)
if (typeof DC_INIT_DATE !== 'undefined' && !state.date)     state.date    = DC_INIT_DATE;
if (typeof DC_INIT_STAFF_ID !== 'undefined' && !state.staffId) state.staffId = DC_INIT_STAFF_ID;
if (typeof DC_INIT_STAFF_NAME !== 'undefined' && !state.staff) state.staff   = DC_INIT_STAFF_NAME;

// ── HELPERS ─────────────────────────────────────────
function fmt(n) {
    return '$' + Math.abs(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function coinCalc(weightG, coinKey) {
    if (weightG <= CUP_WEIGHT) return { count: 0, value: 0 };
    const c = Math.floor((weightG - CUP_WEIGHT) / COINS[coinKey].weight);
    return { count: c, value: c * COINS[coinKey].value };
}

function getRegBillTotal(regId) {
    let t = 0;
    BILLS.forEach(b => t += (state.count[regId].bills[b] || 0) * b);
    return t;
}

function getRegCoinTotal(regId) {
    let t = 0;
    Object.keys(COINS).forEach(c => {
        t += coinCalc(state.count[regId].coins[c] || 0, c).value;
    });
    return t;
}

function getRegCADTotal(regId) {
    return getRegBillTotal(regId) + getRegCoinTotal(regId);
}

function getGrandCAD() {
    let t = 0;
    REGISTERS.forEach(r => t += getRegCADTotal(r.id));
    return t;
}

function getGrandUSD() {
    let t = 0;
    REGISTERS.forEach(r => t += (state.count[r.id].usd || 0));
    return t;
}

function poolBills() {
    const pool = {};
    BILLS.forEach(b => {
        pool[b] = 0;
        REGISTERS.forEach(r => pool[b] += (state.count[r.id].bills[b] || 0));
    });
    return pool;
}

function getFloatBanknoteTotal(regId) {
    let t = 0;
    BILLS.forEach(b => t += (state.float[regId].bills[b] || 0) * b);
    return t;
}

function getFloatTotal(regId) {
    return getFloatBanknoteTotal(regId) + getRegCoinTotal(regId);
}

function getDepositTotal() {
    const pool = poolBills();
    let dep = 0;
    BILLS.forEach(b => {
        let allocated = 0;
        REGISTERS.forEach(r => allocated += (state.float[r.id].bills[b] || 0));
        dep += (pool[b] - allocated) * b;
    });
    return dep;
}

// ── ENTRY PAGE: DATE CHECK ──────────────────────────
(function() {
    const dateInput = document.getElementById('closeDate');
    const alertBox = document.getElementById('existingCountAlert');
    if (!dateInput || !alertBox) return;

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
                document.getElementById('existingMsg').textContent =
                    'Count exists for ' + date + ' (' + data.status + ')';
                document.getElementById('viewExistingBtn').href = BASE_URL + 'dayclose/summary?date=' + date;
                document.getElementById('editExistingBtn').href = BASE_URL + 'dayclose/count?date=' + date + '&staff=' +
                    (document.getElementById('staffSelect').value || data.closed_by);
                alertBox.style.display = 'block';
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
            const staffSel = document.getElementById('staffSelect');
            const staffId = staffSel.value;
            const date = dateInput.value;
            if (!staffId) { alert('Please select a staff member.'); return; }
            if (!date) { alert('Please select a date.'); return; }
            window.location.href = BASE_URL + 'dayclose/count?date=' + encodeURIComponent(date) + '&staff=' + encodeURIComponent(staffId);
        });
    }
})();

// ── NAVIGATION ──────────────────────────────────────
function showScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    window.scrollTo(0, 0);
}

// ── COUNT SCREEN ────────────────────────────────────
function buildCountScreen() {
    const wrap = document.getElementById('countColumns');
    if (!wrap) return;
    wrap.innerHTML = '';
    REGISTERS.forEach(reg => {
        const col = document.createElement('div');
        col.className = 'col-md-4';
        let html = buildRegisterCard(reg);
        html += buildExtras(reg);
        col.innerHTML = html;
        wrap.appendChild(col);
    });
    attachCountListeners();
    updateCountTotals();
}

function buildRegisterCard(reg) {
    let html = `<div class="card shadow-sm">
        <div class="card-header-register">${reg.shortName} — ${reg.name}</div>
        <div class="card-body p-3">`;

    html += `<div class="section-header">Bills (CAD)</div>`;
    BILLS.forEach(b => {
        const val = state.count[reg.id].bills[b] || 0;
        html += `<div class="denom-row">
            <span class="denom-label">$${b}</span>
            <span class="denom-x">&times;</span>
            <input type="number" min="0" class="denom-input count-bill"
                   data-reg="${reg.id}" data-bill="${b}" value="${val}" inputmode="numeric">
            <span class="denom-eq">=</span>
            <span class="denom-amount" id="amt_${reg.id}_b${b}">$0.00</span>
        </div>`;
    });
    html += `<div class="subtotal-row">
        <span>Bills</span>
        <span id="sub_${reg.id}_bills">$0.00</span>
    </div>`;

    html += `<div class="section-header mt-3">Coins (Cup Weight)</div>
             <div class="coin-info mb-1"><i class="bi bi-info-circle"></i> 14 g cup deducted</div>`;
    Object.entries(COINS).forEach(([key, c]) => {
        const val = state.count[reg.id].coins[key] || 0;
        html += `<div class="denom-row">
            <span class="denom-label">${c.label}</span>
            <input type="number" min="0" step="any" class="denom-input count-coin"
                   data-reg="${reg.id}" data-coin="${key}" value="${val}" placeholder="g" inputmode="decimal">
            <span class="denom-eq">g</span>
        </div>
        <div class="coin-count-display ms-2 mb-1" id="cc_${reg.id}_${key}">0 coins = $0.00</div>`;
    });
    html += `<div class="subtotal-row">
        <span>Coins</span>
        <span id="sub_${reg.id}_coins">$0.00</span>
    </div>`;

    const usdVal = state.count[reg.id].usd || 0;
    html += `<div class="section-header mt-3">US Cash</div>
        <div class="usd-box">
            <div class="denom-row">
                <span class="denom-label">USD $</span>
                <input type="number" min="0" step="0.01" class="denom-input count-usd"
                       data-reg="${reg.id}" value="${usdVal}" style="width:90px;" inputmode="decimal">
            </div>
            <div class="text-end fw-bold mt-1" id="sub_${reg.id}_usd">$0.00 USD</div>
        </div>`;

    html += `</div></div>`;
    return html;
}

function buildExtras(reg) {
    let html = '';

    const cbVal = state.cardBatch[reg.id] || '';
    html += `<div class="card shadow-sm mt-2">
        <div class="card-body p-3">
            <div class="section-header">Moneris Batch</div>
            <div class="denom-row">
                <span class="denom-label">Batch $</span>
                <input type="number" min="0" step="0.01" class="denom-input card-batch-input"
                       data-reg="${reg.id}" value="${cbVal}" inputmode="decimal" style="width:100px;">
            </div>
        </div>
    </div>`;

    if (reg.hasTips) {
        const val = state.tips[reg.id] || '';
        html += `<div class="card shadow-sm mt-2">
            <div class="card-body p-3">
                <div class="section-header">Tips</div>
                <div class="denom-row">
                    <span class="denom-label">Tips $</span>
                    <input type="number" min="0" step="0.01" class="denom-input extras-tips"
                           data-reg="${reg.id}" value="${val}" inputmode="decimal">
                </div>
            </div>
        </div>`;
    }

    if (reg.hasRegisterTape) {
        const tapeFields = [
            { key: 'total_sales', label: 'Total Sales', step: '0.01', mode: 'decimal' },
            { key: 'txn_count',   label: 'TXN Count',   step: '1',    mode: 'numeric' },
            { key: 'gst',         label: 'GST',          step: '0.01', mode: 'decimal' },
            { key: 'cash_sales',  label: 'Cash Sales',   step: '0.01', mode: 'decimal' },
            { key: 'card_sales',  label: 'Card Sales',   step: '0.01', mode: 'decimal' },
        ];
        html += `<div class="card shadow-sm mt-2">
            <div class="card-body p-3">
                <div class="section-header">Register Tape</div>
                <div class="coin-info mb-2"><i class="bi bi-info-circle"></i> Enter totals from the analog register tape</div>`;
        tapeFields.forEach(f => {
            const val = state.registerTape[f.key] || '';
            html += `<div class="denom-row">
                <span class="denom-label" style="width:85px;">${f.label}</span>
                <input type="number" min="0" step="${f.step}" class="denom-input extras-tape"
                       data-tape="${f.key}" value="${val}" inputmode="${f.mode}">
            </div>`;
        });
        html += `</div></div>`;
    }

    return html;
}

function attachCountListeners() {
    document.querySelectorAll('.count-bill').forEach(inp => {
        inp.addEventListener('input', function() {
            state.count[this.dataset.reg].bills[parseInt(this.dataset.bill)] = parseInt(this.value) || 0;
            updateCountTotals();
        });
        inp.addEventListener('focus', function() { if (this.value === '0') this.select(); });
    });
    document.querySelectorAll('.count-coin').forEach(inp => {
        inp.addEventListener('input', function() {
            state.count[this.dataset.reg].coins[this.dataset.coin] = parseFloat(this.value) || 0;
            updateCountTotals();
        });
        inp.addEventListener('focus', function() { if (this.value === '0') this.select(); });
    });
    document.querySelectorAll('.count-usd').forEach(inp => {
        inp.addEventListener('input', function() {
            state.count[this.dataset.reg].usd = parseFloat(this.value) || 0;
            updateCountTotals();
        });
        inp.addEventListener('focus', function() { if (this.value === '0') this.select(); });
    });

    document.querySelectorAll('.card-batch-input').forEach(inp => {
        inp.addEventListener('input', function() {
            state.cardBatch[this.dataset.reg] = this.value;
        });
    });
    document.querySelectorAll('.extras-tips').forEach(inp => {
        inp.addEventListener('input', function() {
            state.tips[this.dataset.reg] = this.value;
        });
    });
    document.querySelectorAll('.extras-tape').forEach(inp => {
        inp.addEventListener('input', function() {
            state.registerTape[this.dataset.tape] = this.value;
        });
    });
}

function updateCountTotals() {
    REGISTERS.forEach(reg => {
        let billTotal = 0;
        BILLS.forEach(b => {
            const cnt = state.count[reg.id].bills[b] || 0;
            const amt = cnt * b;
            billTotal += amt;
            const el = document.getElementById(`amt_${reg.id}_b${b}`);
            if (el) el.textContent = fmt(amt);
        });
        const subBills = document.getElementById(`sub_${reg.id}_bills`);
        if (subBills) subBills.textContent = fmt(billTotal);

        let coinTotal = 0;
        Object.entries(COINS).forEach(([key, c]) => {
            const r = coinCalc(state.count[reg.id].coins[key] || 0, key);
            coinTotal += r.value;
            const el = document.getElementById(`cc_${reg.id}_${key}`);
            if (el) el.textContent = `${r.count} coin${r.count !== 1 ? 's' : ''} = ${fmt(r.value)}`;
        });
        const subCoins = document.getElementById(`sub_${reg.id}_coins`);
        if (subCoins) subCoins.textContent = fmt(coinTotal);

        const subUsd = document.getElementById(`sub_${reg.id}_usd`);
        if (subUsd) subUsd.textContent = fmt(state.count[reg.id].usd) + ' USD';
    });

    REGISTERS.forEach(reg => {
        const el = document.getElementById(`ft${reg.shortName}`);
        if (el) el.textContent = fmt(getRegCADTotal(reg.id));
    });
    const ftTotal = document.getElementById('ftTotal');
    if (ftTotal) ftTotal.textContent = 'TOTAL ' + fmt(getGrandCAD()) + ' CAD';
}

function clearForm() {
    if (!confirm('Clear all entered values?')) return;
    initState();
    buildCountScreen();
}

// ── CONFIRMATION MODAL ──────────────────────────────
function showConfirmModal() {
    const body = document.getElementById('confirmBody');
    let html = '<div class="row g-3">';
    REGISTERS.forEach(reg => {
        html += `<div class="col-md-4"><h6 class="fw-bold" style="color:var(--olive);">${reg.shortName} — ${reg.name}</h6>`;
        html += '<div class="section-header">Bills</div>';
        BILLS.forEach(b => {
            const cnt = state.count[reg.id].bills[b] || 0;
            if (cnt > 0) html += `<div class="d-flex justify-content-between"><span>$${b} &times; ${cnt}</span><span class="fw-bold">${fmt(cnt * b)}</span></div>`;
        });
        html += `<div class="border-top mt-1 pt-1 fw-bold d-flex justify-content-between"><span>Bills</span><span>${fmt(getRegBillTotal(reg.id))}</span></div>`;

        html += '<div class="section-header mt-2">Coins</div>';
        Object.entries(COINS).forEach(([key, c]) => {
            const r = coinCalc(state.count[reg.id].coins[key] || 0, key);
            if (r.count > 0) html += `<div class="d-flex justify-content-between"><span>${c.label} &times; ${r.count}</span><span class="fw-bold">${fmt(r.value)}</span></div>`;
        });
        html += `<div class="border-top mt-1 pt-1 fw-bold d-flex justify-content-between"><span>Coins</span><span>${fmt(getRegCoinTotal(reg.id))}</span></div>`;

        html += `<div class="d-flex justify-content-between mt-2 pt-2" style="border-top:2px solid var(--olive);font-weight:700;font-size:1.05rem;color:var(--olive);">
            <span>${reg.shortName} Total (CAD)</span><span>${fmt(getRegCADTotal(reg.id))}</span></div>`;

        if (state.count[reg.id].usd > 0) {
            html += `<div class="usd-box mt-2 p-2"><div class="d-flex justify-content-between"><span>US Cash</span><span class="fw-bold">${fmt(state.count[reg.id].usd)} USD</span></div></div>`;
        }

        if (reg.hasTips && state.tips[reg.id]) {
            html += `<div class="d-flex justify-content-between mt-1"><span>Tips</span><span class="fw-bold">${fmt(parseFloat(state.tips[reg.id]) || 0)}</span></div>`;
        }
        if (reg.hasRegisterTape && state.registerTape.total_sales) {
            const rt = state.registerTape;
            html += `<div class="mt-2 p-2" style="background:#f0f0f0;border-radius:4px;">`;
            html += `<div class="section-header" style="font-size:0.75rem;">Register Tape</div>`;
            html += `<div class="d-flex justify-content-between"><span>Total Sales</span><span>${fmt(parseFloat(rt.total_sales) || 0)}</span></div>`;
            if (rt.txn_count) html += `<div class="d-flex justify-content-between"><span>TXN Count</span><span>${rt.txn_count}</span></div>`;
            if (rt.gst) html += `<div class="d-flex justify-content-between"><span>GST</span><span>${fmt(parseFloat(rt.gst) || 0)}</span></div>`;
            if (rt.cash_sales) html += `<div class="d-flex justify-content-between"><span>Cash Sales</span><span>${fmt(parseFloat(rt.cash_sales) || 0)}</span></div>`;
            if (rt.card_sales) html += `<div class="d-flex justify-content-between"><span>Card Sales</span><span>${fmt(parseFloat(rt.card_sales) || 0)}</span></div>`;
            html += `</div>`;
        }
        html += '</div>';
    });
    html += '</div>';
    html += `<hr><div class="d-flex justify-content-between align-items-center">
        <span class="fw-bold" style="font-size:1.1rem;">Total Cash (CAD)</span>
        <span style="font-family:'Courier New',monospace;font-size:1.4rem;font-weight:700;color:var(--olive);">${fmt(getGrandCAD())}</span>
    </div>`;

    body.innerHTML = html;
    new bootstrap.Modal(document.getElementById('confirmModal')).show();
}

function goToFloat() {
    bootstrap.Modal.getInstance(document.getElementById('confirmModal')).hide();
    buildFloatScreen();
    showScreen('screenFloat');
}

// ── FLOAT PREP SCREEN ───────────────────────────────
function allocateBills(avail, target) {
    const denoms = [...BILLS].reverse();
    const alloc = {};
    BILLS.forEach(b => alloc[b] = 0);

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

function autoAllocateFloats() {
    let hasPrefill = false;
    REGISTERS.forEach(reg => {
        BILLS.forEach(b => { if (state.float[reg.id].bills[b] > 0) hasPrefill = true; });
    });
    if (hasPrefill) return;

    REGISTERS.forEach(reg => {
        const coinTotal = getRegCoinTotal(reg.id);
        let billTarget;

        if (FLOAT_MODE[reg.id] === 'total_fixed') {
            billTarget = Math.max(0, FLOAT_TARGETS[reg.id] - coinTotal);
        } else {
            billTarget = FLOAT_TARGETS[reg.id];
        }

        const avail = {};
        BILLS.forEach(b => avail[b] = state.count[reg.id].bills[b] || 0);

        const alloc = allocateBills(avail, billTarget);
        BILLS.forEach(b => state.float[reg.id].bills[b] = alloc[b]);
    });
}

function buildFloatScreen() {
    autoAllocateFloats();
    const wrap = document.getElementById('floatColumns');
    if (!wrap) return;
    wrap.innerHTML = '';
    REGISTERS.forEach(reg => {
        const col = document.createElement('div');
        col.className = 'col-md-4';
        col.innerHTML = buildFloatCard(reg);
        wrap.appendChild(col);
    });
    attachFloatListeners();
    updateFloatTotals();
}

function buildFloatCard(reg) {
    const target = FLOAT_TARGETS[reg.id];
    const mode = FLOAT_MODE[reg.id];
    const targetLabel = mode === 'total_fixed'
        ? `Target: ${fmt(target)} total`
        : `Target: ${fmt(target)} bills`;
    let html = `<div class="card shadow-sm h-100">
        <div class="card-header-register">${reg.shortName} — ${reg.name} <span class="ms-2" style="font-weight:400;font-size:0.82rem;">${targetLabel}</span></div>
        <div class="card-body p-3">`;

    html += `<div class="section-header">Banknotes <span style="font-weight:400;font-size:0.75rem;text-transform:none;letter-spacing:0;color:#888;">— from ${reg.shortName} pool, smallest first</span></div>`;
    BILLS.forEach(b => {
        const allocated = state.float[reg.id].bills[b] || 0;
        html += `<div class="denom-row">
            <span class="denom-label">$${b}</span>
            <span class="denom-x">&times;</span>
            <input type="number" min="0" class="denom-input float-bill"
                   data-reg="${reg.id}" data-bill="${b}" value="${allocated}" inputmode="numeric">
            <span class="pool-badge" id="pool_${reg.id}_b${b}"></span>
        </div>`;
    });
    html += `<div class="subtotal-row">
        <span>Banknotes</span>
        <span id="fsub_${reg.id}_bills">$0.00</span>
    </div>`;

    const coinLabel = mode === 'total_fixed'
        ? 'Coins (toward target)'
        : 'Coins (extra — stays in register)';
    html += `<div class="section-header mt-3">${coinLabel}</div>`;
    Object.entries(COINS).forEach(([key, c]) => {
        const r = coinCalc(state.count[reg.id].coins[key] || 0, key);
        html += `<div class="d-flex justify-content-between"><span>${c.label}: ${r.count}</span><span>${fmt(r.value)}</span></div>`;
    });
    html += `<div class="border-top mt-1 pt-1 fw-bold d-flex justify-content-between"><span>Coins</span><span>${fmt(getRegCoinTotal(reg.id))}</span></div>`;

    html += `<div class="subtotal-row mt-2" style="border-top:3px solid var(--olive);">
        <span>Total Float</span>
        <span id="fsub_${reg.id}_total">$0.00</span>
    </div>`;
    html += `<div id="float_${reg.id}_warning"></div>`;

    html += `</div></div>`;
    return html;
}

function attachFloatListeners() {
    document.querySelectorAll('.float-bill').forEach(inp => {
        inp.addEventListener('input', function() {
            state.float[this.dataset.reg].bills[parseInt(this.dataset.bill)] = parseInt(this.value) || 0;
            updateFloatTotals();
        });
        inp.addEventListener('focus', function() { if (this.value === '0') this.select(); });
    });
}

function updateFloatTotals() {
    REGISTERS.forEach(r => {
        BILLS.forEach(b => {
            const el = document.getElementById(`pool_${r.id}_b${b}`);
            if (el) {
                const counted = state.count[r.id].bills[b] || 0;
                const allocated = state.float[r.id].bills[b] || 0;
                const remaining = counted - allocated;
                el.textContent = `avail: ${remaining}`;
                el.style.color = remaining < 0 ? '#dc3545' : '#888';
            }
        });
    });

    REGISTERS.forEach(reg => {
        const banknoteTotal = getFloatBanknoteTotal(reg.id);
        const coinTotal = getRegCoinTotal(reg.id);
        const floatTotal = banknoteTotal + coinTotal;
        const target = FLOAT_TARGETS[reg.id];
        const mode = FLOAT_MODE[reg.id];

        const isGood = mode === 'total_fixed'
            ? floatTotal >= target
            : banknoteTotal >= target;

        const subBills = document.getElementById(`fsub_${reg.id}_bills`);
        if (subBills) subBills.innerHTML = fmt(banknoteTotal) + (isGood
            ? ' <span class="float-check"><i class="bi bi-check-circle-fill"></i></span>' : '');
        const subTotal = document.getElementById(`fsub_${reg.id}_total`);
        if (subTotal) subTotal.textContent = fmt(floatTotal);

        const warn = document.getElementById(`float_${reg.id}_warning`);
        if (warn) {
            if (floatTotal < target) {
                const short = target - floatTotal;
                warn.innerHTML = `<div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">
                    <strong>Short ${fmt(short)}</strong> — need to buy from another till</div>`;
            } else if (mode === 'bills_fixed' && banknoteTotal < target) {
                const gap = target - banknoteTotal;
                warn.innerHTML = `<div class="alert alert-info py-1 px-2 mt-2 mb-0 small">
                    Bills short ${fmt(gap)} — covered by coins</div>`;
            } else if (mode === 'total_fixed' && coinTotal > target) {
                const excess = coinTotal - target;
                warn.innerHTML = `<div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">
                    <i class="bi bi-exclamation-triangle"></i> Coins exceed target by <strong>${fmt(excess)}</strong> — please remove ${fmt(excess)} in coins before closing.</div>`;
            } else {
                warn.innerHTML = '';
            }
        }
    });

    REGISTERS.forEach(reg => {
        const el = document.getElementById(`ff${reg.shortName}`);
        if (el) el.textContent = fmt(getFloatTotal(reg.id));
    });
    const ffDep = document.getElementById('ffDeposit');
    if (ffDep) ffDep.textContent = 'Deposit: ' + fmt(getDepositTotal()) + ' CAD';

    updateDepositSection();
}

function updateDepositSection() {
    const pool = poolBills();
    let html = '';
    let total = 0;

    BILLS.forEach(b => {
        let allocated = 0;
        REGISTERS.forEach(r => allocated += (state.float[r.id].bills[b] || 0));
        const depCount = pool[b] - allocated;
        const depAmt = depCount * b;
        total += depAmt;
        if (depCount !== 0) {
            html += `<div class="d-flex justify-content-between">
                <span>$${b} &times; ${depCount}</span>
                <span class="fw-bold">${fmt(depAmt)}</span>
            </div>`;
        }
    });

    if (!html) html = '<div class="text-muted">No bills remaining</div>';
    const depBreak = document.getElementById('depositBreakdown');
    if (depBreak) depBreak.innerHTML = html;
    const depTotal = document.getElementById('depositTotal');
    if (depTotal) depTotal.textContent = fmt(total);

    let usdHtml = '';
    REGISTERS.forEach(reg => {
        if (state.count[reg.id].usd > 0) {
            usdHtml += `<div class="d-flex justify-content-between"><span>${reg.shortName} ${reg.name}</span><span class="fw-bold">${fmt(state.count[reg.id].usd)} USD</span></div>`;
        }
    });
    if (!usdHtml) usdHtml = '<div class="text-muted">No US cash</div>';
    const usdSum = document.getElementById('usdSummary');
    if (usdSum) usdSum.innerHTML = usdHtml;
}

function backToCount() {
    showScreen('screenCount');
}

// ── SUBMIT / SAVE ───────────────────────────────────
function submitClose() {
    const details = [];
    REGISTERS.forEach(reg => {
        BILLS.forEach(b => {
            const cnt = state.count[reg.id].bills[b] || 0;
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
        Object.entries(COINS).forEach(([key, c]) => {
            const weight = state.count[reg.id].coins[key] || 0;
            if (weight > 0) {
                const calc = coinCalc(weight, key);
                details.push({
                    register: reg.id,
                    denomination_type: 'coin',
                    denomination: key,
                    value: weight,
                    calculated_amount: calc.value
                });
            }
        });
        const usd = state.count[reg.id].usd || 0;
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
    REGISTERS.forEach(reg => {
        BILLS.forEach(b => {
            const qty = state.float[reg.id].bills[b] || 0;
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
        ? document.getElementById('closingNotes').value.trim()
        : '';

    const payload = {
        csrf_token: CSRF_TOKEN,
        close_date: state.date,
        closed_by: state.staffId,
        complete: true,
        notes: notes,
        details: details,
        floats: floats,
        r1_card: state.cardBatch.r1 || null,
        r2_card: state.cardBatch.r2 || null,
        r3_card_batch: state.cardBatch.r3 || null,
        r2_tips: state.tips.r2 || null,
        r3_tips: state.tips.r3 || null,
        r3_total_sales: state.registerTape.total_sales || null,
        r3_txn_count: state.registerTape.txn_count || null,
        r3_gst: state.registerTape.gst || null,
        r3_cash: state.registerTape.cash_sales || null,
        r3_card: state.registerTape.card_sales || null,
    };

    const btn = document.querySelector('#screenFloat .btn-tan');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

    fetch(BASE_URL + 'dayclose/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = BASE_URL + 'dayclose/summary?date=' + encodeURIComponent(state.date);
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
            if (btn) { btn.disabled = false; btn.textContent = 'Save & Complete'; }
        }
    })
    .catch(err => {
        alert('Network error: ' + err.message);
        if (btn) { btn.disabled = false; btn.textContent = 'Save & Complete'; }
    });
}

// ── NUMPAD ──────────────────────────────────────────
function buildNumpad() {
    const container = document.getElementById('dcKeypad');
    if (!container) return;

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.id = 'numpadToggle';
    toggle.className = 'dc-numpad-toggle';
    toggle.innerHTML = '<i class="bi bi-calculator"></i>';
    toggle.title = 'Show keypad';
    document.body.appendChild(toggle);

    const keys = ['1','2','3','4','5','6','7','8','9','.','0','⌫'];
    let html = '<div class="dc-numpad" id="numpadPanel" style="display:none;">';
    html += '<div class="dc-numpad-grid">';
    keys.forEach(k => {
        const cls = k === '⌫' ? 'dc-numpad-btn dc-numpad-backspace' : 'dc-numpad-btn';
        html += `<button type="button" class="${cls}" data-key="${k}">${k}</button>`;
    });
    html += '</div>';
    html += '<button type="button" class="dc-numpad-btn dc-numpad-done" id="numpadDone">Close Keypad</button>';
    html += '</div>';
    container.innerHTML = html;

    let activeInput = null;

    function showNumpad() {
        document.getElementById('numpadPanel').style.display = 'block';
        toggle.style.display = 'none';
    }

    function hideNumpad() {
        document.getElementById('numpadPanel').style.display = 'none';
        toggle.style.display = '';
        activeInput = null;
    }

    toggle.addEventListener('click', showNumpad);

    document.addEventListener('focusin', function(e) {
        if (e.target.matches('.denom-input')) {
            activeInput = e.target;
        }
    });

    container.addEventListener('click', function(e) {
        const btn = e.target.closest('.dc-numpad-btn');
        if (!btn) return;
        e.preventDefault();

        if (!activeInput) {
            activeInput = document.querySelector('.denom-input');
            if (activeInput) activeInput.focus();
        }
        if (!activeInput) return;

        const key = btn.dataset.key;
        if (key === '⌫') {
            activeInput.value = activeInput.value.slice(0, -1);
        } else if (key === '.') {
            if (!activeInput.value.includes('.')) activeInput.value += '.';
        } else {
            activeInput.value += key;
        }
        activeInput.dispatchEvent(new Event('input', { bubbles: true }));
    });

    container.addEventListener('mousedown', function(e) {
        if (e.target.closest('.dc-numpad-btn')) e.preventDefault();
    });

    document.getElementById('numpadDone').addEventListener('click', function() {
        hideNumpad();
        if (activeInput) activeInput.blur();
    });
}

// ── INIT COUNT SCREEN (if on count page) ────────────
(function() {
    if (document.getElementById('countColumns')) {
        const navInfo = document.getElementById('navInfo');
        if (navInfo && state.staff && state.date) {
            navInfo.textContent = state.staff + ' — ' + state.date;
        }
        buildCountScreen();
        buildNumpad();
    }
})();

// ── DC namespace adapter for embedded count.php onclick handlers ──
window.DC = {
    clearForm:        clearForm,
    showConfirmModal: showConfirmModal,
    goToFloat:        goToFloat,
    backToCount:      backToCount,
    submitClose:      submitClose,
};
