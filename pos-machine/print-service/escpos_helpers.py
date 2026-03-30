"""
ESC/POS byte builders for Vretti M817 (POS-80C) thermal printer
and Syson 210CE VFD pole display.

Receipt data comes as JSON from the web app — no database connection needed.
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


def build_receipt(data: dict) -> bytes:
    """
    Build receipt bytes from JSON sent by the web app.

    Expected JSON structure:
    {
        "store_name": "Granville Island Tea Co.",
        "store_address": "...",
        "store_phone": "...",
        "transaction_id": 123,
        "date": "2026-03-30 14:22",
        "cashier": "Jane",
        "items": [
            {"name": "...", "quantity": 2, "unit_price": 12.50, "line_total": 25.00,
             "gst": 1.25, "pst": 0}
        ],
        "subtotal": 25.00,
        "gst_amount": 1.25,
        "pst_amount": 0,
        "total": 26.25,
        "payments": [
            {"method": "cash", "amount": 30.00}
        ],
        "change": 3.75,
        "gst_number": "...",
        "pst_number": "...",
        "receipt_footer": "Thank you!"
    }
    """
    is_refund = bool(data.get('is_refund', False))

    out = bytearray()
    out += INIT

    # Header
    out += ALIGN_CENTER
    out += BOLD_ON + DOUBLE_SIZE
    out += _line(data.get('store_name', 'Granville Island Tea Co.'))
    out += NORMAL_SIZE + BOLD_OFF
    addr = data.get('store_address', '')
    if addr:
        out += _line(addr)
    phone = data.get('store_phone', '')
    if phone:
        out += _line(phone)
    out += _line('')

    # Refund banner
    if is_refund:
        out += BOLD_ON + DOUBLE_SIZE
        out += _line('*** REFUND ***')
        out += NORMAL_SIZE + BOLD_OFF
        out += _line('')

    # Transaction info
    out += ALIGN_LEFT
    txn_id = data.get('transaction_id', '')
    if is_refund:
        refund_id = data.get('refund_id', '')
        if refund_id:
            out += _line(f'Refund: #{refund_id}')
        if txn_id:
            out += _line(f'Original Txn: #{txn_id}')
    else:
        if txn_id:
            out += _line(f'Transaction: #{txn_id}')
    out += _line(f'Date: {data.get("date", datetime.now().strftime("%Y-%m-%d %I:%M %p"))}')
    cashier = data.get('cashier', '')
    if cashier:
        out += _line(f'Cashier: {cashier}')
    out += _divider()

    # Items
    for item in data.get('items', []):
        name = str(item.get('name', ''))[:30]
        qty = int(item.get('quantity', 1))
        price = float(item.get('unit_price', 0))
        total = float(item.get('line_total', 0))

        out += _line(name)
        out += _right_col(f'  {qty} x ${price:.2f}', f'${total:.2f}')

        tax_parts = []
        if float(item.get('gst', 0)) > 0:
            tax_parts.append(f'GST ${float(item["gst"]):.2f}')
        if float(item.get('pst', 0)) > 0:
            tax_parts.append(f'PST ${float(item["pst"]):.2f}')
        if tax_parts:
            out += _line('    ' + '  '.join(tax_parts))

    out += _divider()

    # Totals
    out += _right_col('Subtotal', f'${float(data.get("subtotal", 0)):.2f}')
    if float(data.get('gst_amount', 0)) > 0:
        out += _right_col('GST (5%)', f'${float(data["gst_amount"]):.2f}')
    if float(data.get('pst_amount', 0)) > 0:
        out += _right_col('PST (7%)', f'${float(data["pst_amount"]):.2f}')

    total_label = 'REFUND TOTAL' if is_refund else 'TOTAL'
    out += BOLD_ON + DOUBLE_SIZE
    out += _right_col(total_label, f'${float(data.get("total", 0)):.2f}')
    out += NORMAL_SIZE + BOLD_OFF
    out += _divider()

    # Payments
    for pay in data.get('payments', []):
        method = pay.get('method', '').replace('_', ' ').title()
        out += _right_col(method, f'${float(pay.get("amount", 0)):.2f}')

    change = float(data.get('change', 0))
    if change > 0:
        out += BOLD_ON
        out += _right_col('CHANGE', f'${change:.2f}')
        out += BOLD_OFF

    out += _line('')

    # Tax numbers
    gst_num = data.get('gst_number', '')
    pst_num = data.get('pst_number', '')
    if gst_num:
        out += _line(f'GST# {gst_num}')
    if pst_num:
        out += _line(f'PST# {pst_num}')

    # Footer
    out += _line('')
    out += ALIGN_CENTER
    out += _line(data.get('receipt_footer', 'Thank you!'))
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


def build_test_page(machine_id: str = 'POS-1', printer_name: str = 'POS-80C') -> bytes:
    """Build a test page to verify printer connectivity."""
    out = bytearray()
    out += INIT
    out += ALIGN_CENTER
    out += BOLD_ON + DOUBLE_SIZE
    out += _line('PRINTER TEST')
    out += NORMAL_SIZE + BOLD_OFF
    out += _line('')
    out += _line(f'Machine: {machine_id}')
    out += _line(f'Printer: {printer_name}')
    out += _line(f'Time: {datetime.now().strftime("%Y-%m-%d %I:%M:%S %p")}')
    out += _line('')
    out += _divider()
    out += ALIGN_LEFT
    out += _line('48-char width test:')
    out += _line('0' * PAPER_WIDTH)
    out += _divider()
    out += _right_col('Left text', 'Right text')
    out += BOLD_ON
    out += _line('Bold text')
    out += BOLD_OFF
    out += DOUBLE_SIZE
    out += _line('Double size')
    out += NORMAL_SIZE
    out += _divider()
    out += ALIGN_CENTER
    out += _line('If you can read this,')
    out += _line('the printer is working!')
    out += _line('')
    out += FEED_LINES(4)
    out += CUT
    return bytes(out)
