// Safe Coins admin page — modal handling + weight-to-dollar conversion.
// Loaded only on /safe-coins; flag-gated server-side.

const SC_COINS = {
    toonie:  { weight: 6.92, value: 2.00, label: 'Toonie' },
    loonie:  { weight: 6.27, value: 1.00, label: 'Loonie' },
    quarter: { weight: 4.40, value: 0.25, label: 'Quarter' },
    dime:    { weight: 1.75, value: 0.10, label: 'Dime' },
    nickel:  { weight: 3.95, value: 0.05, label: 'Nickel' },
};
const SC_CUP_WEIGHT = 14; // grams (same tare as DayClose)

const typeTitles = {
    bank_sell:  'Sell to bank',
    bank_buy:   'Buy from bank',
    adjustment: 'Adjustment',
    reconcile:  'Reconcile a bag',
};

function openModal(type) {
    document.getElementById('scAddType').value = type;
    document.getElementById('scModalTitle').textContent = typeTitles[type] || 'Add entry';
    document.getElementById('scAddDenom').value = 'toonie';
    document.getElementById('scAddGrams').value = '';
    document.getElementById('scAddDollars').value = '';
    document.getElementById('scAddNote').value = '';
    updateGramsHint();
    new bootstrap.Modal(document.getElementById('scAddModal')).show();
}

function updateGramsHint() {
    const denom = document.getElementById('scAddDenom').value;
    const c = SC_COINS[denom];
    const hint = document.getElementById('scGramsHint');
    if (c) {
        hint.textContent = `${c.label} = ${c.weight}g each; cup tare ${SC_CUP_WEIGHT}g.`;
    } else {
        hint.textContent = 'Mixed bag — enter dollars directly (weight conversion not available).';
    }
}

function computeFromGrams() {
    const denom = document.getElementById('scAddDenom').value;
    const grams = parseFloat(document.getElementById('scAddGrams').value);
    if (!grams || grams <= SC_CUP_WEIGHT || !SC_COINS[denom]) return;
    const c = SC_COINS[denom];
    const count = Math.floor((grams - SC_CUP_WEIGHT) / c.weight);
    const dollars = (count * c.value).toFixed(2);
    document.getElementById('scAddDollars').value = dollars;
}

function submitEntry() {
    const type = document.getElementById('scAddType').value;
    const denom = document.getElementById('scAddDenom').value;
    const grams = document.getElementById('scAddGrams').value;
    const dollars = parseFloat(document.getElementById('scAddDollars').value);
    const note = document.getElementById('scAddNote').value.trim();

    if (!dollars || isNaN(dollars)) {
        alert('Enter a dollar amount (or grams to compute from weight).');
        return;
    }
    if (type !== 'adjustment' && dollars <= 0) {
        alert('Dollar amount must be greater than zero (or use Adjustment for negative values).');
        return;
    }

    const btn = document.getElementById('scAddSubmit');
    btn.disabled = true; btn.textContent = 'Saving...';

    fetch(BASE_URL + 'safe-coins/add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: CSRF_TOKEN,
            type: type,
            denomination: denom,
            grams: grams === '' ? null : grams,
            dollars: dollars,
            note: note,
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error: ' + (data.error || 'Unknown'));
            btn.disabled = false; btn.textContent = 'Save entry';
        }
    })
    .catch(err => {
        alert('Network error: ' + err.message);
        btn.disabled = false; btn.textContent = 'Save entry';
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-add-type]').forEach(btn => {
        btn.addEventListener('click', () => openModal(btn.dataset.addType));
    });
    const reconcileBtn = document.getElementById('btnReconcile');
    if (reconcileBtn) reconcileBtn.addEventListener('click', () => openModal('reconcile'));

    document.getElementById('scAddDenom').addEventListener('change', updateGramsHint);
    document.getElementById('scAddGrams').addEventListener('input', computeFromGrams);
    document.getElementById('scAddSubmit').addEventListener('click', submitEntry);
});
