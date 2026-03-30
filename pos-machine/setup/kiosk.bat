@echo off
timeout /t 5 /nobreak >nul
start "" "C:\Program Files\Google\Chrome\Application\chrome.exe" --kiosk --noerrstdialogs --disable-session-crashed-bubble "https://pos.granvilletea.com"
