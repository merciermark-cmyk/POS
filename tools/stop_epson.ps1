Stop-Service EPSON_Port_Communication_Service -Force
Stop-Service EPSON_Device_Control_Log_Service -Force
Get-Service EPSON_Port_Communication_Service, EPSON_Device_Control_Log_Service | Format-Table Status, Name, DisplayName -AutoSize
