#!/usr/bin/env python3
"""Locate print service files on POS-1 Beelink."""
import paramiko, sys

client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
client.connect("209.121.249.78", port=2201, username="Loose tea machine",
               key_filename=r"C:\Users\mark\.ssh\pos_key", timeout=10)

def run(cmd, timeout=10):
    print(f">>> {cmd}", flush=True)
    stdin, stdout, stderr = client.exec_command(cmd, timeout=timeout)
    print(stdout.read().decode(errors='replace'))
    e = stderr.read().decode(errors='replace')
    if e.strip(): print(f"[err] {e}")

# Just file listing — no tasklist / wmic
run('dir "C:\\Users\\Loose tea machine" /b')
run('dir "C:\\Users\\Loose tea machine\\*.py" /b /s', timeout=20)
client.close()
print("done.")
