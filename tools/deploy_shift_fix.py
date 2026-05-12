import paramiko, os

local_base = r'C:\Users\mark\pos'
remote_base = '/home/gitte512/public_html/pos.granvilletea.com'

files = [
    'index.php',
    'app/controllers/ShiftController.php',
    'app/views/shift/close.php',
    'app/views/shift/edit.php',
    'app/views/shift/report.php',
]

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')
sftp = ssh.open_sftp()

for f in files:
    local = os.path.join(local_base, f)
    remote = remote_base + '/' + f
    print(f'Uploading {f} ... ', end='')
    sftp.put(local, remote)
    print('OK')

sftp.close()
ssh.close()
print('\nAll files deployed.')
