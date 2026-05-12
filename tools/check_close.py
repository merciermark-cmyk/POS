import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

# Check the close view on production
stdin, stdout, stderr = ssh.exec_command('cat /home/gitte512/public_html/pos.granvilletea.com/app/views/shift/close.php')
print("=== close.php ===")
print(stdout.read().decode())

ssh.close()
