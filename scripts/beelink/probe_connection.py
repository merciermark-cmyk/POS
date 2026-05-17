#!/usr/bin/env python3
"""Minimal Beelink probe."""
import paramiko
import sys

print("connecting...", flush=True)
client = paramiko.SSHClient()
client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
try:
    client.connect("209.121.249.78", port=2201, username="Loose tea machine",
                   key_filename=r"C:\Users\mark\.ssh\pos_key",
                   timeout=10, banner_timeout=10, auth_timeout=10)
except Exception as e:
    print(f"connect failed: {type(e).__name__}: {e}")
    sys.exit(1)

print("connected; running whoami", flush=True)
stdin, stdout, stderr = client.exec_command("whoami && hostname", timeout=10)
print("STDOUT:", stdout.read().decode(errors='replace'))
print("STDERR:", stderr.read().decode(errors='replace'))
client.close()
print("done.")
