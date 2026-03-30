@echo off
REM Install POS Print Service as a Windows service using NSSM.
REM Download NSSM from https://nssm.cc and place nssm.exe in PATH or this directory.

set SERVICE_NAME=POSPrintService
set PYTHON_PATH=python
set SCRIPT_PATH=%~dp0print_server.py

echo Installing %SERVICE_NAME%...

REM Remove existing service if present
nssm stop %SERVICE_NAME% 2>nul
nssm remove %SERVICE_NAME% confirm 2>nul

REM Install
nssm install %SERVICE_NAME% "%PYTHON_PATH%" "%SCRIPT_PATH%"
nssm set %SERVICE_NAME% AppDirectory "%~dp0"
nssm set %SERVICE_NAME% DisplayName "POS Print Service"
nssm set %SERVICE_NAME% Description "HTTP service for thermal printer, cash drawer, and pole display"
nssm set %SERVICE_NAME% Start SERVICE_AUTO_START
nssm set %SERVICE_NAME% AppStdout "%~dp0..\logs\print_service.log"
nssm set %SERVICE_NAME% AppStderr "%~dp0..\logs\print_service.log"
nssm set %SERVICE_NAME% AppRotateFiles 1
nssm set %SERVICE_NAME% AppRotateBytes 1048576

REM Start the service
nssm start %SERVICE_NAME%

echo Done. Service %SERVICE_NAME% installed and started.
pause
