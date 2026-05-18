"""Delete the temporary label-deploy.html from gitte512 server (no longer needed)."""
import paramiko

HOST = "granvilletea.com"
USER = "gitte512"
PASS = "8q4grp0mdf"
REMOTE = "/home/gitte512/public_html/pos.granvilletea.com/label-deploy.html"

t = paramiko.Transport((HOST, 22))
t.connect(username=USER, password=PASS)
sftp = paramiko.SFTPClient.from_transport(t)
try:
    sftp.remove(REMOTE)
    print(f"removed {REMOTE}")
except FileNotFoundError:
    print(f"already gone: {REMOTE}")
finally:
    sftp.close()
    t.close()
