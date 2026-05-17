#!/usr/bin/env python3
"""Find the Windows service hosting the print server."""
import paramiko

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect("209.121.249.78", port=2201, username="Loose tea machine",
               key_filename=r"C:\Users\mark\.ssh\pos_key", timeout=10)

def run(cmd, timeout=20):
    print(f"\n>>> {cmd}", flush=True)
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    print(stdout.read().decode(errors='replace'))
    e = stderr.read().decode(errors='replace')
    if e.strip(): print(f"[err] {e}")

run('tasklist /svc /fi "PID eq 5256"')
# Backup: list services and look for ones with python / print / pos in binpath
run('powershell -NoProfile -Command "Get-WmiObject Win32_Service | Where-Object { $_.PathName -match \'python|print|pos\' -or $_.Name -match \'print|pos\' } | Select-Object Name,DisplayName,State,StartMode,PathName | Format-List"')
client.close()
print("\ndone.")
