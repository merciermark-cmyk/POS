"""
ESC/POS byte builders for Vretti M817 thermal printer and Syson 210CE VFD pole display.
"""

from datetime import datetime

# ── ESC/POS Commands ──────────────────────────────────────────────────
ESC = b'\x1b'
GS = b'\x1d'

INIT = ESC + b'@'               # Initialize printer
CUT = GS + b'V\x00'             # Full cut
PARTIAL_CUT = GS + b'V\x01'     # Partial cut

ALIGN_LEFT = ESC + b'a\x00'
ALIGN_CENTER = ESC + b'a\x01'
ALIGN_RIGHT = ESC + b'a\x02'

BOLD_ON = ESC + b'E\x01'
BOLD_OFF = ESC + b'E\x00'

DOUBLE_WIDTH = ESC + b'!\x20'
DOUBLE_HEIGHT = ESC + b'!\x10'
DOUBLE_SIZE = ESC + b'!\x30'
NORMAL_SIZE = ESC + b'!\x00'

FEED_LINES = lambda n: ESC + b'd' + bytes([n])

# Cash drawer kick (pin 2, 100ms pulse)
DRAWER_KICK = ESC + b'p\x00\x19\xff'

# ── VFD Pole Display ─────────────────────────────────────────────────
VFD_CLEAR = b'\x0c'             # Clear display
VFD_HOME = b'\x0b'              # Cursor home
VFD_LINE2 = b'\x0d'             # Move to line 2 (CR)

PAPER_WIDTH = 48  # characters for 80mm paper


def clear_pole() -> bytes:
    return VFD_CLEAR


def write_pole(line1: str, line2: str) -> bytes:
    """Write two lines to 20-char VFD pole display."""
    l1 = line1[:20].ljust(20)
    l2 = line2[:20].rjust(20)
    return VFD_CLEAR + l1.encode('ascii', errors='replace') + VFD_LINE2 + l2.encode('ascii', errors='replace')


def _line(text: str) -> bytes:
    return text.encode('utf-8', errors='replace') + b'\n'


def _divider() -> bytes:
    return _line('-' * PAPER_WIDTH)


def _right_col(left: str, right: str) -> bytes:
    """Format a two-column line: left-aligned text + right-aligned amount."""
    space = PAPER_WIDTH - len(left) - len(right)
    if space < 1:
        space = 1
    return _line(left + ' ' * space + right)


def build_receipt(txn_data: dict, change: float = 0) -> bytes:
    """Build receipt bytes from transaction data."""
    txn = txn_data['transaction']
    items = txn_data['items']
    payments = txn_data['payments']
    settings = txn_data.get('settings', {})

    out = bytearray()
    out += INIT

    # Header
    out += ALIGN_CENTER
    out += BOLD_ON + DOUBLE_SIZE
    out += _line(settings.get('store_name', 'Granville Island Tea Co.'))
    out += NORMAL_SIZE + BOLD_OFF
    out += _line(settings.get('store_address', ''))
    out += _line(settings.get('store_phone', ''))
    out += _line('')

    # Transaction info
    out += ALIGN_LEFT
    out += _line(f'Transaction: #{txn["id"]}')
    out += _line(f'Date: {txn["created_at"]}')
    out += _line(f'Cashier: {txn["username"]}')
    out += _divider()

    # Items
    for item in items:
        name = item['product_name'][:30]
        qty = int(item['quantity'])
        price = float(item['unit_price'])
        total = float(item['line_total'])

        discount_pct = float(item.get('discount_percent', 0))
        if discount_pct > 0:
            out += _line(f'{name} (-{int(discount_pct)}%)')
        else:
            out += _line(name)
        out += _right_col(f'  {qty} x ${price:.2f}', f'${total:.2f}')

        # Print modifiers indented under item
        for mod in item.get('modifiers', []):
            mod_name = mod.get('modifier_name', mod.get('name', ''))[:26]
            mod_price = float(mod.get('modifier_price', mod.get('price', 0)))
            mod_qty = int(mod.get('quantity', mod.get('qty', 1)))
            if mod_qty > 1:
                out += _line(f'    + {mod_name} x{mod_qty} (${mod_price:.2f})')
            else:
                out += _line(f'    + {mod_name} (${mod_price:.2f})')

        tax_parts = []
        if float(item.get('gst', 0)) > 0:
            tax_parts.append(f'GST ${float(item["gst"]):.2f}')
        if float(item.get('pst', 0)) > 0:
            tax_parts.append(f'PST ${float(item["pst"]):.2f}')
        if tax_parts:
            out += _line('    ' + '  '.join(tax_parts))

    out += _divider()

    # Totals
    out += _right_col('Subtotal', f'${float(txn["subtotal"]):.2f}')
    if float(txn.get('gst_amount', 0)) > 0:
        out += _right_col('GST (5%)', f'${float(txn["gst_amount"]):.2f}')
    if float(txn.get('pst_amount', 0)) > 0:
        out += _right_col('PST (7%)', f'${float(txn["pst_amount"]):.2f}')

    out += BOLD_ON + DOUBLE_SIZE
    out += _right_col('TOTAL', f'${float(txn["total"]):.2f}')
    out += NORMAL_SIZE + BOLD_OFF
    out += _divider()

    # Payments
    for pay in payments:
        ref = pay.get('reference', '') or ''
        if ref.startswith('USD '):
            method = f'Cash ({ref})'
        else:
            method = pay['method'].replace('_', ' ').title()
        out += _right_col(method, f'${float(pay["amount"]):.2f}')

    if change > 0:
        out += BOLD_ON
        out += _right_col('CHANGE', f'${change:.2f}')
        out += BOLD_OFF

    out += _line('')

    # Tax numbers
    gst_num = settings.get('gst_number', '')
    pst_num = settings.get('pst_number', '')
    if gst_num:
        out += _line(f'GST# {gst_num}')
    if pst_num:
        out += _line(f'PST# {pst_num}')

    # Footer
    out += _line('')
    out += ALIGN_CENTER
    out += _line(settings.get('receipt_footer', 'Thank you!'))
    out += _line('')

    out += FEED_LINES(4)
    out += CUT

    return bytes(out)


def build_z_report(data: dict) -> bytes:
    """Build Z-report (shift close summary) for printing."""
    out = bytearray()
    out += INIT
    out += ALIGN_CENTER
    out += BOLD_ON + DOUBLE_SIZE
    out += _line('Z-REPORT')
    out += NORMAL_SIZE + BOLD_OFF
    out += _line(datetime.now().strftime('%Y-%m-%d %I:%M %p'))
    out += _line('')
    out += ALIGN_LEFT
    out += _divider()

    out += _right_col('Opening Float', f'${float(data.get("opening_float", 0)):.2f}')
    out += _right_col('Transactions', str(data.get('transaction_count', 0)))
    out += _right_col('Voids', str(data.get('void_count', 0)))
    out += _divider()

    out += _right_col('Subtotal', f'${float(data.get("subtotal", 0)):.2f}')
    out += _right_col('GST Collected', f'${float(data.get("gst", 0)):.2f}')
    out += _right_col('PST Collected', f'${float(data.get("pst", 0)):.2f}')
    out += BOLD_ON
    out += _right_col('TOTAL SALES', f'${float(data.get("total", 0)):.2f}')
    out += BOLD_OFF
    out += _divider()

    # Payment breakdown
    for pay in data.get('payments', []):
        method = pay.get('method', '').replace('_', ' ').title()
        out += _right_col(method, f'${float(pay.get("total", 0)):.2f}')

    out += _divider()
    out += _right_col('Expected Cash', f'${float(data.get("expected_cash", 0)):.2f}')
    out += _right_col('Actual Cash', f'${float(data.get("closing_cash", 0)):.2f}')

    over_short = float(data.get('over_short', 0))
    label = 'OVER' if over_short >= 0 else 'SHORT'
    out += BOLD_ON
    out += _right_col(label, f'${abs(over_short):.2f}')
    out += BOLD_OFF

    out += _line('')
    out += FEED_LINES(4)
    out += CUT

    return bytes(out)
