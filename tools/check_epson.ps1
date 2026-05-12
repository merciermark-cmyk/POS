# Check for Epson port-related services and processes
Write-Host "=== Epson Services ==="
Get-Service | Where-Object { $_.DisplayName -match 'Epson|EpsonNet|EPSON' } | Format-Table Status, Name, DisplayName -AutoSize

Write-Host "`n=== Epson Processes ==="
Get-Process | Where-Object { $_.ProcessName -match 'Epson|EPSON' } | Format-Table Id, ProcessName, Path -AutoSize

Write-Host "`n=== All COM ports (registry) ==="
Get-ItemProperty 'HKLM:\HARDWARE\DEVICEMAP\SERIALCOMM' -ErrorAction SilentlyContinue | Format-List

Write-Host "`n=== Printer Ports ==="
Get-PrinterPort | Format-Table Name, Description, PortMonitor -AutoSize
