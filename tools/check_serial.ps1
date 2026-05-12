Get-CimInstance Win32_PnPEntity | Where-Object { $_.Name -match 'COM\d|Serial|CH340|FTDI|PL2303|CP210' } | Select-Object Name, Status, DeviceID | Format-List
