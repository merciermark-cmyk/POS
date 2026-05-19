"""Tail the print service log on POS-1."""
import paramiko

HOST = "209.121.249.78"; PORT = 2201
USER = "Loose tea machine"
KEY = r"C:\Users\mark\.ssh\pos_key"

pkey = paramiko.Ed25519Key.from_private_key_file(KEY)
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, port=PORT, username=USER, pkey=pkey)

stdin, stdout, stderr = ssh.exec_command(
    'powershell -NoProfile -Command "Get-Content -Path \'C:\\POS\\logs\\print_service.log\' -Tail 80"'
)
print(stdout.read().decode(errors='replace'))
err = stderr.read().decode(errors='replace').strip()
if err: print(f"STDERR: {err}")
ssh.close()
