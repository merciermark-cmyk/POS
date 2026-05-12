import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

stdin, stdout, stderr = ssh.exec_command('tail -20 /home/gitte512/public_html/pos.granvilletea.com/error_log')
print(stdout.read().decode())

ssh.close()
