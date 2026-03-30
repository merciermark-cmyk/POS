"""
POS Print Service — HTTP server for ESC/POS printer, cash drawer, and VFD pole display.
Runs on localhost:9100 on the Beelink POS terminal.

Endpoints:
    POST /receipt          - Print receipt (JSON: {transaction_id, change})
    POST /open-drawer      - Kick cash drawer
    POST /pole-display     - Update VFD pole display (JSON: {line1, line2})
    POST /z-report         - Print Z-report (JSON: shift data)
    GET  /status           - Health check
"""

import json
import sys
import os
from http.server import HTTPServer, BaseHTTPRequestHandler
from escpos_helpers import build_receipt, build_z_report, DRAWER_KICK, clear_pole, write_pole

# ── Configuration ─────────────────────────────────────────────────────
LISTEN_HOST = '127.0.0.1'
LISTEN_PORT = 9100

# Printer: USB (update VID/PID for your Vretti M817)
PRINTER_VID = 0x0416  # Vretti common VID
PRINTER_PID = 0x5011  # Vretti common PID
# Fallback: printer file (Windows USB)
PRINTER_FILE = None  # e.g. r'\\.\USB003' or '/dev/usb/lp0'

# Pole display: COM port (update for your Syson 210CE)
POLE_DISPLAY_PORT = 'COM3'
POLE_DISPLAY_BAUD = 9600

# ── Database connection for receipt data ──────────────────────────────
DB_CONFIG = {
    'host': os.getenv('DB_HOST', 'localhost'),
    'port': int(os.getenv('DB_PORT', '3306')),
    'database': os.getenv('DB_NAME', 'inventory'),
    'user': os.getenv('DB_USER', 'inventory'),
    'password': os.getenv('DB_PASS', 'inventory123'),
}


def get_printer():
    """Get a file-like object for the thermal printer."""
    try:
        import usb.core
        dev = usb.core.find(idVendor=PRINTER_VID, idProduct=PRINTER_PID)
        if dev:
            if dev.is_kernel_driver_active(0):
                dev.detach_kernel_driver(0)
            dev.set_configuration()
            cfg = dev.get_active_configuration()
            intf = cfg[(0, 0)]
            ep_out = None
            for ep in intf:
                if usb.util.endpoint_direction(ep.bEndpointAddress) == usb.util.ENDPOINT_OUT:
                    ep_out = ep
                    break
            return ep_out
    except Exception:
        pass

    if PRINTER_FILE:
        return open(PRINTER_FILE, 'wb')

    return None


def send_to_printer(data: bytes):
    """Send raw bytes to printer."""
    printer = get_printer()
    if printer is None:
        print('[WARN] No printer found — data discarded')
        return False
    try:
        if hasattr(printer, 'write'):
            printer.write(data)
        else:
            printer.write(data)
        return True
    except Exception as e:
        print(f'[ERROR] Printer: {e}')
        return False
    finally:
        if hasattr(printer, 'close'):
            printer.close()


def send_to_pole(line1: str, line2: str):
    """Send text to VFD pole display via serial."""
    try:
        import serial
        with serial.Serial(POLE_DISPLAY_PORT, POLE_DISPLAY_BAUD, timeout=1) as ser:
            data = clear_pole() + write_pole(line1, line2)
            ser.write(data)
            return True
    except Exception as e:
        print(f'[WARN] Pole display: {e}')
        return False


def fetch_transaction(txn_id: int) -> dict:
    """Fetch full transaction data from database."""
    import mysql.connector
    conn = mysql.connector.connect(**DB_CONFIG)
    cur = conn.cursor(dictionary=True)

    cur.execute('SELECT t.*, u.username FROM pos_transactions t '
                'JOIN pos_users u ON t.user_id = u.id WHERE t.id = %s', (txn_id,))
    txn = cur.fetchone()

    cur.execute('SELECT * FROM pos_transaction_items WHERE transaction_id = %s ORDER BY id', (txn_id,))
    items = cur.fetchall()

    cur.execute('SELECT * FROM pos_payments WHERE transaction_id = %s ORDER BY id', (txn_id,))
    payments = cur.fetchall()

    # Settings
    cur.execute('SELECT setting_key, setting_value FROM pos_settings')
    settings = {row['setting_key']: row['setting_value'] for row in cur.fetchall()}

    conn.close()
    return {'transaction': txn, 'items': items, 'payments': payments, 'settings': settings}


class PrintHandler(BaseHTTPRequestHandler):

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()

    def do_GET(self):
        if self.path == '/status':
            self.respond(200, {'status': 'ok', 'printer': get_printer() is not None})
        else:
            self.respond(404, {'error': 'not found'})

    def do_POST(self):
        content_len = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(content_len) if content_len else b''

        try:
            data = json.loads(body) if body else {}
        except json.JSONDecodeError:
            data = {}

        if self.path == '/receipt':
            self.handle_receipt(data)
        elif self.path == '/open-drawer':
            ok = send_to_printer(DRAWER_KICK)
            self.respond(200 if ok else 503, {'status': 'ok' if ok else 'printer not found'})
        elif self.path == '/pole-display':
            ok = send_to_pole(data.get('line1', ''), data.get('line2', ''))
            self.respond(200, {'status': 'ok' if ok else 'pole display not found'})
        elif self.path == '/z-report':
            self.handle_z_report(data)
        else:
            self.respond(404, {'error': 'not found'})

    def handle_receipt(self, data):
        txn_id = data.get('transaction_id')
        change = data.get('change', 0)
        if not txn_id:
            self.respond(400, {'error': 'transaction_id required'})
            return

        try:
            txn_data = fetch_transaction(int(txn_id))
            receipt_bytes = build_receipt(txn_data, float(change))
            # Print receipt + kick drawer
            ok = send_to_printer(receipt_bytes + DRAWER_KICK)
            self.respond(200 if ok else 503, {'status': 'ok' if ok else 'printer error'})
        except Exception as e:
            self.respond(500, {'error': str(e)})

    def handle_z_report(self, data):
        try:
            report_bytes = build_z_report(data)
            ok = send_to_printer(report_bytes)
            self.respond(200 if ok else 503, {'status': 'ok' if ok else 'printer error'})
        except Exception as e:
            self.respond(500, {'error': str(e)})

    def respond(self, code, data):
        self.send_response(code)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.end_headers()
        self.wfile.write(json.dumps(data).encode())

    def log_message(self, format, *args):
        print(f'[{self.log_date_time_string()}] {format % args}')


def ensure_ssl_cert():
    """Generate a self-signed certificate if one doesn't exist."""
    cert_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'certs')
    cert_file = os.path.join(cert_dir, 'server.pem')
    key_file = os.path.join(cert_dir, 'server-key.pem')

    if not os.path.exists(cert_file):
        os.makedirs(cert_dir, exist_ok=True)
        import subprocess
        subprocess.run([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048',
            '-keyout', key_file, '-out', cert_file,
            '-days', '3650', '-nodes',
            '-subj', '/CN=localhost'
        ], check=True)
        print(f'Generated self-signed certificate in {cert_dir}')

    return cert_file, key_file


if __name__ == '__main__':
    import ssl
    cert_file, key_file = ensure_ssl_cert()

    print(f'POS Print Service starting on {LISTEN_HOST}:{LISTEN_PORT} (HTTPS)')
    server = HTTPServer((LISTEN_HOST, LISTEN_PORT), PrintHandler)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(cert_file, key_file)
    server.socket = ctx.wrap_socket(server.socket, server_side=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\nShutting down.')
        server.server_close()
