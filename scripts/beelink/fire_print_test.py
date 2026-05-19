"""Fire /print/test on POS-1 print service via SSH and report response."""
import paramiko

HOST = "209.121.249.78"; PORT = 2201
USER = "Loose tea machine"
KEY = r"C:\Users\mark\.ssh\pos_key"

pkey = paramiko.Ed25519Key.from_private_key_file(KEY)
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, port=PORT, username=USER, pkey=pkey)

cmd = (
    'curl -s -o response.txt -w "%{http_code}" -X POST '
    '-H "Content-Type: application/json" -d "{}" '
    'http://localhost:5000/print/test'
)
stdin, stdout, stderr = ssh.exec_command(cmd)
rc = stdout.channel.recv_exit_status()
print(f"http_code = {stdout.read().decode(errors='replace').strip()}")

stdin, stdout, stderr = ssh.exec_command('type response.txt')
print(f"body: {stdout.read().decode(errors='replace').strip()}")

ssh.close()
