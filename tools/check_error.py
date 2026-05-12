import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

stdin, stdout, stderr = ssh.exec_command('tail -30 /home/gitte512/logs/pos.granvilletea.com.error.log 2>/dev/null || tail -30 /home/gitte512/public_html/pos.granvilletea.com/error_log 2>/dev/null || tail -30 ~/logs/error.log 2>/dev/null')
print(stdout.read().decode())
print(stderr.read().decode())

ssh.close()
