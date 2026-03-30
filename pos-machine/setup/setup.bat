@echo off
setlocal EnableDelayedExpansion

REM ═══════════════════════════════════════════════════════════════════
REM  POS Machine Setup Script
REM  Run as Administrator on a fresh mini PC.
REM  Expects template files on a USB stick (or in the same directory).
REM ═══════════════════════════════════════════════════════════════════

echo.
echo ============================================
echo   POS Machine Setup
echo ============================================
echo.

REM ── Check admin privileges ──────────────────────────────────────
net session >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo ERROR: This script must be run as Administrator.
    echo Right-click and select "Run as administrator".
    pause
    exit /b 1
)

REM ── Determine source directory (where setup.bat lives) ──────────
set "SOURCE_DIR=%~dp0.."
set "DEST=C:\POS"

echo Source: %SOURCE_DIR%
echo Destination: %DEST%
echo.

REM ── Step 1: Create directory structure ──────────────────────────
echo [1/9] Creating directories...
if not exist "%DEST%\print-service" mkdir "%DEST%\print-service"
if not exist "%DEST%\logs" mkdir "%DEST%\logs"
if not exist "%DEST%\setup" mkdir "%DEST%\setup"
echo       Done.

REM ── Step 2: Copy files ──────────────────────────────────────────
echo [2/9] Copying files...
copy /Y "%SOURCE_DIR%\print-service\print_server.py" "%DEST%\print-service\" >nul
copy /Y "%SOURCE_DIR%\print-service\escpos_helpers.py" "%DEST%\print-service\" >nul
copy /Y "%SOURCE_DIR%\print-service\requirements.txt" "%DEST%\print-service\" >nul
copy /Y "%SOURCE_DIR%\setup\nssm.exe" "%DEST%\setup\" >nul 2>&1
copy /Y "%SOURCE_DIR%\setup\kiosk.bat" "%DEST%\setup\" >nul
copy /Y "%SOURCE_DIR%\README.txt" "%DEST\" >nul 2>&1
echo       Done.

REM ── Step 3: Prompt for machine ID ──────────────────────────────
echo.
set "MACHINE_ID=POS-1"
set /p "MACHINE_ID=Enter machine ID [POS-1]: "
if "!MACHINE_ID!"=="" set "MACHINE_ID=POS-1"

echo [3/9] Writing config.ini (Machine: !MACHINE_ID!)...
(
    echo [machine]
    echo id = !MACHINE_ID!
    echo.
    echo [printer]
    echo name = POS-80C
    echo.
    echo [pole_display]
    echo port = COM3
    echo baud = 9600
    echo.
    echo [service]
    echo host = 0.0.0.0
    echo port = 5000
) > "%DEST%\config.ini"
echo       Done.

REM ── Step 4: Detect hardware ─────────────────────────────────────
echo [4/9] Detecting hardware...

set "PRINTER_OK=NO"
wmic printer where "Name='POS-80C'" get Name 2>nul | findstr /i "POS-80C" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    set "PRINTER_OK=YES"
    echo       Printer POS-80C: FOUND
) else (
    echo       Printer POS-80C: NOT FOUND
    echo       WARNING: Install the printer driver and try again, or update config.ini later.
)

set "COM_OK=NO"
mode COM3 2>nul | findstr /i "COM3" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    set "COM_OK=YES"
    echo       Pole display COM3: FOUND
) else (
    echo       Pole display COM3: NOT FOUND
    echo       WARNING: Check USB-serial adapter, or update config.ini with correct COM port.
)

REM ── Step 5: Install Python packages ─────────────────────────────
echo [5/9] Installing Python packages...
python -m pip install --quiet -r "%DEST%\print-service\requirements.txt"
if %ERRORLEVEL% neq 0 (
    echo       WARNING: pip install failed. Ensure Python is installed and in PATH.
) else (
    echo       Done.
)

REM ── Step 6: Register nssm service ──────────────────────────────
echo [6/9] Registering POSPrintService...

REM Determine nssm location
set "NSSM=%DEST%\setup\nssm.exe"
if not exist "%NSSM%" (
    where nssm >nul 2>&1
    if %ERRORLEVEL% equ 0 (
        set "NSSM=nssm"
    ) else (
        echo       ERROR: nssm.exe not found. Place it in %DEST%\setup\ and re-run.
        goto :skip_service
    )
)

REM Find python.exe path
for /f "tokens=*" %%p in ('where python 2^>nul') do set "PYTHON_PATH=%%p"
if "!PYTHON_PATH!"=="" (
    echo       ERROR: python.exe not found in PATH.
    goto :skip_service
)

REM Stop and remove existing service if present
%NSSM% stop POSPrintService >nul 2>&1
%NSSM% remove POSPrintService confirm >nul 2>&1

%NSSM% install POSPrintService "!PYTHON_PATH!" "print_server.py"
%NSSM% set POSPrintService AppDirectory "%DEST%\print-service"
%NSSM% set POSPrintService DisplayName "POS Print Service"
%NSSM% set POSPrintService Description "HTTP service for thermal printer, cash drawer, and pole display"
%NSSM% set POSPrintService Start SERVICE_AUTO_START
%NSSM% set POSPrintService AppStdout "%DEST%\logs\print_service.log"
%NSSM% set POSPrintService AppStderr "%DEST%\logs\print_service.log"
%NSSM% set POSPrintService AppRotateFiles 1
%NSSM% set POSPrintService AppRotateBytes 1048576
echo       Done.

:skip_service

REM ── Step 7: Start service and verify ────────────────────────────
echo [7/9] Starting service...
%NSSM% start POSPrintService >nul 2>&1

REM Wait for service to start
timeout /t 3 /nobreak >nul

set "HEALTH_OK=NO"
curl -s http://localhost:5000/health >nul 2>&1
if %ERRORLEVEL% equ 0 (
    set "HEALTH_OK=YES"
    echo       Service is running. /health responds OK.
) else (
    echo       WARNING: Service may not have started yet. Check logs at %DEST%\logs\print_service.log
)

REM ── Step 8: Install kiosk launcher ──────────────────────────────
echo [8/9] Installing kiosk launcher to Startup folder...
set "STARTUP=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup"
copy /Y "%DEST%\setup\kiosk.bat" "%STARTUP%\" >nul
echo       Done. Chrome will open in kiosk mode on login.

REM ── Step 9: Print test page ─────────────────────────────────────
echo [9/9] Printing test page...
if "!HEALTH_OK!"=="YES" (
    curl -s -X POST http://localhost:5000/print/test >nul 2>&1
    if %ERRORLEVEL% equ 0 (
        echo       Test page sent to printer.
    ) else (
        echo       WARNING: Could not send test page.
    )
) else (
    echo       Skipped (service not running).
)

REM ── Summary ─────────────────────────────────────────────────────
echo.
echo ============================================
echo   Setup Complete
echo ============================================
echo.
echo   Machine ID:     !MACHINE_ID!
echo   Printer:        POS-80C (!PRINTER_OK!)
echo   Pole Display:   COM3 (!COM_OK!)
echo   Service:        POSPrintService (!HEALTH_OK!)
echo   Config:         %DEST%\config.ini
echo   Logs:           %DEST%\logs\print_service.log
echo   Kiosk:          Installed in Startup folder
echo.
echo   Next steps:
echo   - Reboot to verify auto-start
echo   - Complete a test sale on pos.granvilletea.com
echo.
pause
