"""One-shot SFTP deploy: gate POS Labels button to POS-1 only."""
import paramiko
import sys
import os

HOST = "granvilletea.com"
USER = "gitte512"
PASS = "8q4grp0mdf"
REMOTE_ROOT = "/home/gitte512/public_html/pos.granvilletea.com"
LOCAL_ROOT = r"C:\Users\mark\pos"

FILES = [
    "app/views/auth/pin.php",
    "app/views/sale/terminal.php",
]

def main():
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASS)
    sftp = paramiko.SFTPClient.from_transport(transport)
    try:
        for rel in FILES:
            local = os.path.join(LOCAL_ROOT, rel.replace("/", os.sep))
            remote = f"{REMOTE_ROOT}/{rel}"
            local_size = os.path.getsize(local)
            sftp.put(local, remote)
            remote_size = sftp.stat(remote).st_size
            status = "OK" if local_size == remote_size else "SIZE MISMATCH"
            print(f"{status}  {rel}  ({local_size} bytes)")
    finally:
        sftp.close()
        transport.close()

if __name__ == "__main__":
    main()
