"""Deploy updated label.html template to POS-1 and restart print service.

Connects to POS-1 via the SSH tunnel on the store's public IP, uploads the
template via SFTP, then stops/starts POSPrintService over SSH.
"""
import paramiko
import os
import sys

HOST = "209.121.249.78"
PORT = 2201
USER = "Loose tea machine"
KEY = r"C:\Users\mark\.ssh\pos_key"
LOCAL = r"C:\Users\mark\pos\pos-machine\print-service\templates\label.html"
REMOTE_CANDIDATES = [
    "C:/Users/Loose tea machine/templates/label.html",
    "/C:/Users/Loose tea machine/templates/label.html",
]


def main():
    pkey = paramiko.Ed25519Key.from_private_key_file(KEY)
    t = paramiko.Transport((HOST, PORT))
    t.connect(username=USER, pkey=pkey)
    sftp = paramiko.SFTPClient.from_transport(t)

    # Back up the live file first
    backup_path = LOCAL.replace("label.html", "label.html.posbk")
    pulled = False
    for remote in REMOTE_CANDIDATES:
        try:
            sftp.get(remote, backup_path)
            sz = os.path.getsize(backup_path)
            print(f"backup: pulled live -> {backup_path} ({sz} bytes)")
            pulled = True
            target = remote
            break
        except Exception as e:
            print(f"  backup tried {remote}: {type(e).__name__}: {e}")
    if not pulled:
        print("FATAL: could not read existing label.html; aborting", file=sys.stderr)
        sftp.close(); t.close()
        sys.exit(1)

    # Upload new version to the path that worked
    sftp.put(LOCAL, target)
    new_sz = sftp.stat(target).st_size
    print(f"upload: pushed {LOCAL} -> {target} ({new_sz} bytes)")
    sftp.close()

    # Restart the print service so Jinja picks up the new template
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, port=PORT, username=USER, pkey=pkey)
    for cmd in [
        "net stop POSPrintService",
        "net start POSPrintService",
    ]:
        stdin, stdout, stderr = ssh.exec_command(cmd)
        rc = stdout.channel.recv_exit_status()
        out = stdout.read().decode(errors="replace").strip()
        err = stderr.read().decode(errors="replace").strip()
        print(f"$ {cmd}  (rc={rc})")
        if out: print(f"  out: {out}")
        if err: print(f"  err: {err}")
    ssh.close()
    t.close()


if __name__ == "__main__":
    main()
