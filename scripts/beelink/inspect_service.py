#!/usr/bin/env python3
"""Confirm print service launcher details."""
import paramiko

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect("209.121.249.78", port=2201, username="Loose tea machine",
               key_filename=r"C:\Users\mark\.ssh\pos_key", timeout=10)

def run(cmd, timeout=15):
    print(f"\n>>> {cmd}", flush=True)
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    print(stdout.read().decode(errors='replace'))
    e = stderr.read().decode(errors='replace')
    if e.strip(): print(f"[err] {e}")

run('type "C:\\Users\\Loose tea machine\\AppData\\Roaming\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\\pos-kiosk.bat"')
run('schtasks /query /tn "\\LaunchKiosk" /fo LIST /v 2>nul')
run('schtasks /query /tn "\\LaunchKeyboard" /fo LIST /v 2>nul')
# PID 5256 is on port 5000 — find its image and command
run('tasklist /fi "PID eq 5256" /v')
client.close()
print("\ndone.")
