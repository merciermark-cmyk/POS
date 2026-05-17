#!/usr/bin/env python3
"""Inspect Beelink print service launch mechanism."""
import paramiko

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect("209.121.249.78", port=2201, username="Loose tea machine",
               key_filename=r"C:\Users\mark\.ssh\pos_key", timeout=10)

def run(cmd, timeout=10):
    print(f"\n>>> {cmd}", flush=True)
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    print(stdout.read().decode(errors='replace'))
    e = stderr.read().decode(errors='replace')
    if e.strip(): print(f"[err] {e}")

# Scheduled tasks (look for print or python launchers)
run('schtasks /query /fo LIST /v 2>nul | findstr /i "TaskName Action print python flask"', timeout=20)
# Startup folder
run('dir "C:\\Users\\Loose tea machine\\AppData\\Roaming\\Microsoft\\Windows\\Start Menu\\Programs\\Startup" /b 2>nul')
run('dir "C:\\ProgramData\\Microsoft\\Windows\\Start Menu\\Programs\\Startup" /b 2>nul')
# Registry Run keys
run('reg query HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run 2>nul')
run('reg query HKLM\\Software\\Microsoft\\Windows\\CurrentVersion\\Run 2>nul')
# Check if print server is actually running on port 5000
run('netstat -ano | findstr :5000')
client.close()
print("\ndone.")
