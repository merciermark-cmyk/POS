"""
POS Print Service — Flask HTTP server for ESC/POS thermal printer,
cash drawer, VFD pole display, and Zebra ZD411 label printer.

Runs on each POS mini PC. Reads machine-specific settings from C:\\POS\\config.ini.
The web app (pos.granvilletea.com) sends fully-formed receipt/report JSON —
receipt endpoints have no database connection. Label endpoints call the
inventory API over HTTPS for product lookups.

Endpoints:
    GET  /health            - Health check
    POST /print/receipt     - Print receipt (JSON with store_name, items, totals, payments, etc.)
    POST /print/test        - Print a test page
    POST /print/raw         - Send raw ESC/POS bytes (base64-encoded)
    POST /print/open-drawer - Kick cash drawer
    POST /print/feed        - Feed paper
    POST /print/cut         - Cut paper
    POST /pole-display      - Update VFD pole display (JSON: {line1, line2})
    GET  /label             - Standalone label printing web page
    POST /print/label       - Print product label on Zebra ZD411 (ZPL)
    GET  /api/products      - Search products (for label page autocomplete)
"""

import configparser
import logging
import os
import sys
import base64
import urllib.request
import urllib.parse
import json

from flask import Flask, request, jsonify, render_template
from escpos_helpers import (
    build_receipt, build_z_report, build_test_page,
    DRAWER_KICK, CUT, FEED_LINES, INIT,
    clear_pole, write_pole
)

# ── Configuration ─────────────────────────────────────────────────────
CONFIG_PATH = r'C:\POS\config.ini'

config = configparser.ConfigParser()
config.read(CONFIG_PATH)

MACHINE_ID = config.get('machine', 'id', fallback='POS-1')
PRINTER_NAME = config.get('printer', 'name', fallback='POS-80C')
POLE_PORT = config.get('pole_display', 'port', fallback='COM3')
POLE_BAUD = config.getint('pole_display', 'baud', fallback=9600)
LABEL_PRINTER_NAME = config.get('label_printer', 'name', fallback='ZDesigner ZD411-203dpi ZPL')
LABEL_API_URL = config.get('label_api', 'url',
    fallback='https://inventory.granvilletea.com/public/api/label-products.php')
LISTEN_HOST = config.get('service', 'host', fallback='0.0.0.0')
LISTEN_PORT = config.getint('service', 'port', fallback=5000)

# ── Logging ───────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format=f'[%(asctime)s] [{MACHINE_ID}] %(levelname)s %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
log = logging.getLogger(__name__)

# ── Flask App ─────────────────────────────────────────────────────────
app = Flask(__name__)


@app.after_request
def add_cors(response):
    response.headers['Access-Control-Allow-Origin'] = '*'
    response.headers['Access-Control-Allow-Methods'] = 'GET, POST, OPTIONS'
    response.headers['Access-Control-Allow-Headers'] = 'Content-Type'
    return response


# ── Printer (win32print via Windows spooler) ──────────────────────────

def send_to_printer(data: bytes) -> bool:
    """Send raw ESC/POS bytes to the thermal printer via Windows spooler."""
    try:
        import win32print
        handle = win32print.OpenPrinter(PRINTER_NAME)
        try:
            win32print.StartDocPrinter(handle, 1, ('POS Receipt', None, 'RAW'))
            win32print.StartPagePrinter(handle)
            win32print.WritePrinter(handle, data)
            win32print.EndPagePrinter(handle)
            win32print.EndDocPrinter(handle)
            return True
        finally:
            win32print.ClosePrinter(handle)
    except Exception as e:
        log.error(f'Printer error: {e}')
        return False


def printer_exists() -> bool:
    """Check if the configured printer is available."""
    try:
        import win32print
        printers = [p[2] for p in win32print.EnumPrinters(
            win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
        )]
        return PRINTER_NAME in printers
    except Exception:
        return False


# ── Pole Display (serial) ────────────────────────────────────────────

def send_to_pole(line1: str, line2: str) -> bool:
    """Send text to VFD pole display via serial port."""
    try:
        import serial
        import time
        with serial.Serial(POLE_PORT, POLE_BAUD, timeout=1) as ser:
            ser.write(clear_pole())
            time.sleep(0.1)
            ser.write(write_pole(line1, line2))
            return True
    except Exception as e:
        log.warning(f'Pole display: {e}')
        return False


# ── Label Printer (Zebra ZD411 via ZPL) ─────────────────────────────

def send_to_label_printer(data: bytes) -> bool:
    """Send raw ZPL bytes to the Zebra ZD411 via Windows spooler."""
    try:
        import win32print
        handle = win32print.OpenPrinter(LABEL_PRINTER_NAME)
        try:
            win32print.StartDocPrinter(handle, 1, ('ZPL Label', None, 'RAW'))
            win32print.StartPagePrinter(handle)
            win32print.WritePrinter(handle, data)
            win32print.EndPagePrinter(handle)
            win32print.EndDocPrinter(handle)
            return True
        finally:
            win32print.ClosePrinter(handle)
    except Exception as e:
        log.error(f'Label printer error: {e}')
        return False


def build_zpl_label(product_name: str, brewing_instructions: str = '',
                    product_code: str = '') -> bytes:
    """
    Build ZPL commands for a 2x1" label on the Zebra ZD411 (203 DPI).
    Center justified, offset slightly left to compensate for printer margin.
    """
    # 2" x 1" at 203 DPI = 406 x 203 dots
    content_width = 370  # usable width for text blocks
    left = 18            # center 370 block on 406 label: (406-370)/2

    zpl = '^XA\n'
    zpl += '^PW406\n'       # print width
    zpl += '^LL203\n'       # label length
    zpl += '^CI28\n'        # UTF-8

    # Product name — 10pt ≈ 30 dots, double-strike for bold
    zpl += f'^FO{left},30^A0N,30,30^FB{content_width},2,0,C^FD{product_name}^FS\n'
    zpl += f'^FO{left + 1},30^A0N,30,30^FB{content_width},2,0,C^FD{product_name}^FS\n'

    y = 95
    if brewing_instructions:
        # Divider line
        zpl += f'^FO{left + 30},88^GB{content_width - 60},0,1^FS\n'
        # Brewing instructions — 8pt ≈ 24 dots
        zpl += f'^FO{left},96^A0N,24,24^FB{content_width},2,0,C^FD{brewing_instructions}^FS\n'
        y = 145

    if product_code:
        # PLU — 8pt ≈ 24 dots
        zpl += f'^FO{left},{y}^A0N,24,24^FB{content_width},1,0,C^FDPLU: {product_code}^FS\n'

    zpl += '^XZ\n'

    return zpl.encode('utf-8')


# ── Label API (remote product lookup) ────────────────────────────────

def fetch_label_products(query=''):
    """Fetch products from the inventory API for label printing."""
    url = LABEL_API_URL
    if query:
        url += '?' + urllib.parse.urlencode({'q': query})
    req = urllib.request.Request(url)
    with urllib.request.urlopen(req, timeout=10) as resp:
        return json.loads(resp.read().decode('utf-8'))


def fetch_product_by_id(product_id):
    """Fetch a single product by ID from the inventory API."""
    url = LABEL_API_URL + '?' + urllib.parse.urlencode({'id': product_id})
    req = urllib.request.Request(url)
    with urllib.request.urlopen(req, timeout=10) as resp:
        products = json.loads(resp.read().decode('utf-8'))
        for p in products:
            if str(p['id']) == str(product_id):
                return p
        return None


# ── Routes ────────────────────────────────────────────────────────────

@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status': 'ok',
        'machine_id': MACHINE_ID,
        'printer': PRINTER_NAME,
        'printer_found': printer_exists(),
        'pole_display': POLE_PORT,
        'label_printer': LABEL_PRINTER_NAME
    })


@app.route('/print/receipt', methods=['POST'])
def print_receipt():
    data = request.get_json(silent=True) or {}
    try:
        receipt_bytes = build_receipt(data)
        no_drawer = bool(data.get('no_drawer', False))
        if no_drawer:
            ok = send_to_printer(receipt_bytes)
        else:
            ok = send_to_printer(receipt_bytes + DRAWER_KICK)
        if ok:
            return jsonify({'status': 'ok'})
        return jsonify({'status': 'error', 'message': 'printer not found'}), 503
    except Exception as e:
        log.error(f'Receipt error: {e}')
        return jsonify({'status': 'error', 'message': str(e)}), 500


@app.route('/print/test', methods=['POST'])
def print_test():
    test_bytes = build_test_page(MACHINE_ID, PRINTER_NAME)
    ok = send_to_printer(test_bytes)
    if ok:
        return jsonify({'status': 'ok'})
    return jsonify({'status': 'error', 'message': 'printer not found'}), 503


@app.route('/print/raw', methods=['POST'])
def print_raw():
    data = request.get_json(silent=True) or {}
    raw_b64 = data.get('data', '')
    try:
        raw_bytes = base64.b64decode(raw_b64)
        ok = send_to_printer(raw_bytes)
        if ok:
            return jsonify({'status': 'ok'})
        return jsonify({'status': 'error', 'message': 'printer not found'}), 503
    except Exception as e:
        return jsonify({'status': 'error', 'message': str(e)}), 400


@app.route('/print/open-drawer', methods=['POST'])
def open_drawer():
    ok = send_to_printer(DRAWER_KICK)
    if ok:
        return jsonify({'status': 'ok'})
    return jsonify({'status': 'error', 'message': 'printer not found'}), 503


@app.route('/print/feed', methods=['POST'])
def feed():
    data = request.get_json(silent=True) or {}
    lines = data.get('lines', 3)
    ok = send_to_printer(FEED_LINES(lines))
    if ok:
        return jsonify({'status': 'ok'})
    return jsonify({'status': 'error', 'message': 'printer not found'}), 503


@app.route('/print/cut', methods=['POST'])
def cut():
    ok = send_to_printer(CUT)
    if ok:
        return jsonify({'status': 'ok'})
    return jsonify({'status': 'error', 'message': 'printer not found'}), 503


@app.route('/print/z-report', methods=['POST'])
def print_z_report():
    data = request.get_json(silent=True) or {}
    try:
        report_bytes = build_z_report(data)
        ok = send_to_printer(report_bytes)
        if ok:
            return jsonify({'status': 'ok'})
        return jsonify({'status': 'error', 'message': 'printer not found'}), 503
    except Exception as e:
        log.error(f'Z-report error: {e}')
        return jsonify({'status': 'error', 'message': str(e)}), 500


@app.route('/pole-display', methods=['POST'])
def pole_display():
    data = request.get_json(silent=True) or {}
    ok = send_to_pole(data.get('line1', ''), data.get('line2', ''))
    return jsonify({'status': 'ok' if ok else 'pole display not found'})


# ── Label Printing Routes ────────────────────────────────────────────

@app.route('/label', methods=['GET'])
def label_page():
    """Serve the standalone label printing web page."""
    return render_template('label.html')


@app.route('/print/label', methods=['POST'])
def print_label():
    """
    Print a product label on the Zebra ZD411 via ZPL.

    Accepts JSON:
      { "product_name": "...", "brewing_instructions": "...", "product_code": "...", "qty": 1 }
    or
      { "product_id": 123, "qty": 1 }  — looks up via inventory API
    """
    data = request.get_json(silent=True) or {}
    try:
        if 'product_id' in data:
            product = fetch_product_by_id(int(data['product_id']))
            if not product:
                return jsonify({'status': 'error', 'message': 'Product not found'}), 404
            label = product
        else:
            label = {
                'name': data.get('product_name', 'Unknown'),
                'brewing_instructions': data.get('brewing_instructions', ''),
                'product_code': data.get('product_code', ''),
            }

        qty = max(1, min(50, int(data.get('qty', 1))))
        zpl = build_zpl_label(
            product_name=label['name'],
            brewing_instructions=label.get('brewing_instructions') or '',
            product_code=label.get('product_code') or '',
        )
        # Repeat ZPL for quantity
        zpl_batch = zpl * qty

        if send_to_label_printer(zpl_batch):
            return jsonify({'status': 'ok', 'printed': qty})
        else:
            return jsonify({'status': 'error', 'message': 'Label printer not responding'}), 503

    except Exception as e:
        log.error(f'Label print error: {e}')
        return jsonify({'status': 'error', 'message': str(e)}), 500


@app.route('/api/products', methods=['GET'])
def api_products():
    """Search loose tea products via inventory API."""
    q = request.args.get('q', '').strip()
    try:
        products = fetch_label_products(q)
        return jsonify(products)
    except Exception as e:
        log.error(f'Product search error: {e}')
        return jsonify({'status': 'error', 'message': str(e)}), 500


# ── Main ──────────────────────────────────────────────────────────────

if __name__ == '__main__':
    log.info(f'POS Print Service [{MACHINE_ID}] starting on {LISTEN_HOST}:{LISTEN_PORT}')
    log.info(f'Printer: {PRINTER_NAME} | Pole display: {POLE_PORT} | Label printer: {LABEL_PRINTER_NAME}')
    app.run(host=LISTEN_HOST, port=LISTEN_PORT, debug=False)
