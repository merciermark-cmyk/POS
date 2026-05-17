#!/usr/bin/env python3
"""Upload patched escpos_helpers.py to Beelink and restart POSPrintService."""
import paramiko, time, sys

LOCAL  = r"C:\Users\mark\pos\backup\escpos_helpers_PATCHED.py"
REMOTE_DIR = "C:/Users/Loose tea machine"
REMOTE_TARGET = f"{REMOTE_DIR}/escpos_helpers.py"
REMOTE_BAK    = f"{REMOTE_DIR}/escpos_helpers.py.bak-2026-05-17"

key = paramiko.Ed25519Key.from_private_key_file(r"C:\Users\mark\.ssh\pos_key")

# SFTP first
t = paramiko.Transport(("209.121.249.78", 2201))
t.connect(username="Loose tea machine", pkey=key)
sftp = paramiko.SFTPClient.from_transport(t)

# Step 1: server-side backup (rename live to .bak)
try:
    sftp.stat(REMOTE_BAK)
    print(f"backup {REMOTE_BAK} already exists — leaving as is")
except IOError:
    sftp.rename(REMOTE_TARGET, REMOTE_BAK)
    print(f"renamed live -> {REMOTE_BAK}")

# Step 2: upload patched file as new live
sftp.put(LOCAL, REMOTE_TARGET)
st = sftp.stat(REMOTE_TARGET)
print(f"uploaded {REMOTE_TARGET} ({st.st_size} bytes)")
sftp.close()
t.close()

# Step 3: restart Windows service
client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect("209.121.249.78", port=2201, username="Loose tea machine", pkey=key, timeout=10)

def run(cmd, timeout=20):
    print(f"\n>>> {cmd}", flush=True)
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    print(stdout.read().decode(errors='replace'))
    e = stderr.read().decode(errors='replace')
    if e.strip(): print(f"[err] {e}")

# Stop, then start. nssm restart is cleaner if available.
run('"C:\\tools\\nssm.exe" restart POSPrintService 2>&1')
time.sleep(2)
run('powershell -NoProfile -Command "Get-Service POSPrintService | Format-List Status,StartType,Name"')
time.sleep(1)
run('netstat -ano | findstr :5000')

client.close()
print("\ndone.")
