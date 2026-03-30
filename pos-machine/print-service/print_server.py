"""
POS Print Service — Flask HTTP server for ESC/POS thermal printer,
cash drawer, and VFD pole display.

Runs on each POS mini PC. Reads machine-specific settings from C:\\POS\\config.ini.
The web app (pos.granvilletea.com) sends fully-formed receipt/report JSON —
this service has NO database connection.

Endpoints:
    GET  /health            - Health check
    POST /print/receipt     - Print receipt (JSON with store_name, items, totals, payments, etc.)
    POST /print/test        - Print a test page
    POST /print/raw         - Send raw ESC/POS bytes (base64-encoded)
    POST /print/open-drawer - Kick cash drawer
    POST /print/feed        - Feed paper
    POST /print/cut         - Cut paper
    POST /pole-display      - Update VFD pole display (JSON: {line1, line2})
"""

import configparser
import logging
import os
import sys
import base64

from flask import Flask, request, jsonify
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
        with serial.Serial(POLE_PORT, POLE_BAUD, timeout=1) as ser:
            ser.write(clear_pole() + write_pole(line1, line2))
            return True
    except Exception as e:
        log.warning(f'Pole display: {e}')
        return False


# ── Routes ────────────────────────────────────────────────────────────

@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status': 'ok',
        'machine_id': MACHINE_ID,
        'printer': PRINTER_NAME,
        'printer_found': printer_exists(),
        'pole_display': POLE_PORT
    })


@app.route('/print/receipt', methods=['POST'])
def print_receipt():
    data = request.get_json(silent=True) or {}
    try:
        receipt_bytes = build_receipt(data)
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


# ── Main ──────────────────────────────────────────────────────────────

if __name__ == '__main__':
    log.info(f'POS Print Service [{MACHINE_ID}] starting on {LISTEN_HOST}:{LISTEN_PORT}')
    log.info(f'Printer: {PRINTER_NAME} | Pole display: {POLE_PORT}')
    app.run(host=LISTEN_HOST, port=LISTEN_PORT, debug=False)
