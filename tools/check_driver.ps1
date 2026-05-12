# Check PL2303 driver details
Write-Host "=== PL2303 Driver Info ==="
Get-CimInstance Win32_PnPSignedDriver | Where-Object { $_.DeviceName -match 'Prolific|PL2303' } | Format-List DeviceName, DriverVersion, DriverDate, IsSigned, Manufacturer, InfName

Write-Host "`n=== USB Device Status ==="
Get-PnpDevice | Where-Object { $_.FriendlyName -match 'Prolific|PL2303|COM3' } | Format-List FriendlyName, Status, InstanceId, Problem

Write-Host "`n=== Device Manager error code ==="
Get-PnpDevice | Where-Object { $_.FriendlyName -match 'Prolific|PL2303|COM3' } | Get-PnpDeviceProperty -KeyName DEVPKEY_Device_ProblemCode | Format-List
