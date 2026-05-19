"""Pull live escpos_helpers.py + print_server.py from POS-1 to compare."""
import paramiko, os, hashlib

HOST = "209.121.249.78"; PORT = 2201
USER = "Loose tea machine"
KEY = r"C:\Users\mark\.ssh\pos_key"

pkey = paramiko.Ed25519Key.from_private_key_file(KEY)
t = paramiko.Transport((HOST, PORT))
t.connect(username=USER, pkey=pkey)
sftp = paramiko.SFTPClient.from_transport(t)

PAIRS = [
    ("C:/Users/Loose tea machine/escpos_helpers.py",
     r"C:\Users\mark\pos\backup\beelink_escpos_helpers_2026-05-18.py"),
    ("C:/Users/Loose tea machine/print_server.py",
     r"C:\Users\mark\pos\backup\beelink_print_server_2026-05-18.py"),
]
for remote, local in PAIRS:
    sftp.get(remote, local)
    with open(local, 'rb') as f:
        h = hashlib.sha256(f.read()).hexdigest()[:12]
    print(f"{remote}\n  -> {local}\n  sha256:{h}  size:{os.path.getsize(local)}")
sftp.close(); t.close()
