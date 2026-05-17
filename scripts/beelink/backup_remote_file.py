#!/usr/bin/env python3
"""Fetch current escpos_helpers.py from Beelink as a backup."""
import paramiko, os

LOCAL_BAK = r"C:\Users\mark\pos\backup\beelink_escpos_helpers_2026-05-17.py"
REMOTE    = "/C:/Users/Loose tea machine/escpos_helpers.py"  # SFTP path

os.makedirs(os.path.dirname(LOCAL_BAK), exist_ok=True)

t = paramiko.Transport(("209.121.249.78", 2201))
t.connect(username="Loose tea machine", pkey=paramiko.Ed25519Key.from_private_key_file(r"C:\Users\mark\.ssh\pos_key"))
sftp = paramiko.SFTPClient.from_transport(t)

# Windows SFTP usually accepts forward-slash paths. Try a couple variations.
for remote in [
    "C:/Users/Loose tea machine/escpos_helpers.py",
    "/C:/Users/Loose tea machine/escpos_helpers.py",
    "Users/Loose tea machine/escpos_helpers.py",
]:
    try:
        sftp.get(remote, LOCAL_BAK)
        st = os.stat(LOCAL_BAK)
        print(f"OK: backed up {remote} -> {LOCAL_BAK} ({st.st_size} bytes)")
        break
    except Exception as e:
        print(f"  tried {remote}: {type(e).__name__}: {e}")

sftp.close()
t.close()
