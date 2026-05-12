import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

files = [
    '/home/gitte512/public_html/pos.granvilletea.com/index.php',
    '/home/gitte512/public_html/pos.granvilletea.com/app/controllers/ShiftController.php',
    '/home/gitte512/public_html/pos.granvilletea.com/app/views/shift/close.php',
    '/home/gitte512/public_html/pos.granvilletea.com/app/views/shift/edit.php',
    '/home/gitte512/public_html/pos.granvilletea.com/app/views/shift/report.php',
]

for f in files:
    stdin, stdout, stderr = ssh.exec_command(f'ea-php82 -l {f}')
    out = stdout.read().decode().strip()
    err = stderr.read().decode().strip()
    print(f'{f}:')
    print(f'  {out or err}')

ssh.close()
