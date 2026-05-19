"""Inspect POS-80C receipt printer state in detail."""
import paramiko

HOST = "209.121.249.78"; PORT = 2201
USER = "Loose tea machine"
KEY = r"C:\Users\mark\.ssh\pos_key"

pkey = paramiko.Ed25519Key.from_private_key_file(KEY)
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, port=PORT, username=USER, pkey=pkey)

# Detailed printer + port info
PROBES = [
    ('Get-Printer -Name "POS-80C" | Format-List Name,PrinterStatus,JobCount,DetectedErrorState,WorkOffline,PortName,DriverName', 'POS-80C detail'),
    ('Get-PrintJob -PrinterName "POS-80C" 2>$null | Format-List Id,JobStatus,SubmittedTime,Size,UserName', 'pending jobs'),
    ('Get-PrinterPort | Where-Object { $_.Name -like "USB*" -or $_.Name -like "COM*" } | Format-Table Name,Description', 'USB/COM ports'),
    ('wmic printjob list brief 2>$null', 'all queued jobs (wmic)'),
]
for cmd, label in PROBES:
    print(f"\n=== {label} ===")
    stdin, stdout, stderr = ssh.exec_command(f'powershell -NoProfile -Command "{cmd}"')
    out = stdout.read().decode(errors='replace').strip()
    err = stderr.read().decode(errors='replace').strip()
    if out: print(out)
    if err: print(f"STDERR: {err}")

ssh.close()
