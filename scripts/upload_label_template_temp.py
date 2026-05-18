"""Upload label.html to a temp URL on pos.granvilletea.com so POS-1 can curl it."""
import paramiko
import os

HOST = "granvilletea.com"
USER = "gitte512"
PASS = "8q4grp0mdf"
LOCAL = r"C:\Users\mark\pos\pos-machine\print-service\templates\label.html"
REMOTE = "/home/gitte512/public_html/pos.granvilletea.com/label-deploy.html"

transport = paramiko.Transport((HOST, 22))
transport.connect(username=USER, password=PASS)
sftp = paramiko.SFTPClient.from_transport(transport)
try:
    sftp.put(LOCAL, REMOTE)
    sz = sftp.stat(REMOTE).st_size
    print(f"uploaded {sz} bytes -> https://pos.granvilletea.com/label-deploy.html")
finally:
    sftp.close()
    transport.close()
