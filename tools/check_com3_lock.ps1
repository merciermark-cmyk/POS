# Check for processes that might be holding COM3 open
Write-Host "=== Checking for processes using COM3 ==="

# Try opening the port directly to confirm the error
try {
    $port = New-Object System.IO.Ports.SerialPort("COM3", 9600)
    $port.Open()
    Write-Host "SUCCESS: COM3 opened fine. Closing now."
    $port.Close()
    $port.Dispose()
} catch {
    Write-Host "FAILED to open COM3: $($_.Exception.Message)"
}

# Check if any Python processes are running
Write-Host "`n=== Python processes ==="
Get-Process python*, py* -ErrorAction SilentlyContinue | Format-Table Id, ProcessName, StartTime -AutoSize

# Check common serial monitor programs
Write-Host "`n=== Potential serial port users ==="
$suspects = @('putty', 'teraterm', 'minicom', 'realterm', 'coolterm', 'hyperterminal', 'com0com', 'serial', 'arduino', 'screen')
foreach ($s in $suspects) {
    Get-Process -Name "*$s*" -ErrorAction SilentlyContinue | Format-Table Id, ProcessName -AutoSize
}
