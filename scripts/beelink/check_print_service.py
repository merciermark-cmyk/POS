"""Probe POS-1 print service health + recent service state."""
import paramiko

HOST = "209.121.249.78"
PORT = 2201
USER = "Loose tea machine"
KEY = r"C:\Users\mark\.ssh\pos_key"

pkey = paramiko.Ed25519Key.from_private_key_file(KEY)
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, port=PORT, username=USER, pkey=pkey)

CMDS = [
    ('sc query POSPrintService', 'service status'),
    ('netstat -ano | findstr :5000', 'listener on :5000'),
    ('curl -s -o NUL -w "%{http_code}" http://localhost:5000/health', 'health endpoint'),
    ('powershell -NoProfile -Command "Get-Printer | Select-Object Name,PrinterStatus,JobCount | Format-Table -AutoSize"', 'installed printers'),
    ('powershell -NoProfile -Command "Get-PrintJob -PrinterName \\"POS-80C\\" 2>$null | Select-Object Id,JobStatus,SubmittedTime,Size | Format-Table -AutoSize"', 'pending jobs on POS-80C'),
]

for cmd, label in CMDS:
    print(f"\n=== {label} ===\n$ {cmd}")
    stdin, stdout, stderr = ssh.exec_command(cmd)
    rc = stdout.channel.recv_exit_status()
    out = stdout.read().decode(errors='replace').strip()
    err = stderr.read().decode(errors='replace').strip()
    print(f"rc={rc}")
    if out: print(out)
    if err: print(f"STDERR: {err}")

ssh.close()
