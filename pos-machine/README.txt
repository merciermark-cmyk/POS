POS Machine Setup — Quick Reference
====================================

Directory Layout
----------------
C:\POS\
  config.ini              Machine-specific settings (machine ID, printer, COM port)
  print-service\
    print_server.py       Flask HTTP server (port 5000)
    escpos_helpers.py     ESC/POS command builders
    requirements.txt      Python dependencies
  logs\
    print_service.log     Service output (rotated at 1MB)
  setup\
    setup.bat             Initial setup script (run once as admin)
    nssm.exe              Service manager
    kiosk.bat             Chrome kiosk launcher (copied to Startup)

Service Management
------------------
  Start:    nssm start POSPrintService
  Stop:     nssm stop POSPrintService
  Restart:  nssm restart POSPrintService
  Status:   nssm status POSPrintService
  Remove:   nssm remove POSPrintService confirm

Endpoints (http://localhost:5000)
---------------------------------
  GET  /health              Health check + hardware status
  POST /print/receipt       Print receipt (send JSON body)
  POST /print/test          Print test page
  POST /print/raw           Send raw ESC/POS (base64 in {"data": "..."})
  POST /print/open-drawer   Kick cash drawer
  POST /print/feed          Feed paper ({"lines": 3})
  POST /print/cut           Cut paper
  POST /print/z-report      Print shift close report (send JSON body)
  POST /pole-display        Update pole display ({"line1": "...", "line2": "..."})

Quick Diagnostics
-----------------
  curl http://localhost:5000/health
  curl -X POST http://localhost:5000/print/test
  curl -X POST http://localhost:5000/print/open-drawer

Troubleshooting
---------------
  Service won't start:
    1. Check logs: type C:\POS\logs\print_service.log
    2. Verify Python is in PATH: python --version
    3. Verify packages: pip list | findstr flask
    4. Test manually: cd C:\POS\print-service && python print_server.py

  Printer not found:
    1. Check printer name in config.ini matches Windows printer name exactly
    2. Open Settings > Printers & Scanners to verify printer is listed
    3. Try printing a Windows test page first

  Pole display not responding:
    1. Check COM port in Device Manager > Ports
    2. Update config.ini if port number changed
    3. Restart service after config change

  Chrome kiosk won't open on boot:
    1. Verify kiosk.bat exists in: %APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup
    2. Check Chrome is installed at: C:\Program Files\Google\Chrome\Application\chrome.exe

  After ANY config.ini change:
    nssm restart POSPrintService

Web App
-------
  URL: https://pos.granvilletea.com
  The web app sends complete receipt JSON to localhost:5000 — the print
  service has no database connection and doesn't need one.
