import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

# Find all error logs
stdin, stdout, stderr = ssh.exec_command('find /home/gitte512 -name "*.log" -o -name "error_log" 2>/dev/null | head -20')
print("=== Log files ===")
print(stdout.read().decode())

# Check the most common locations
for log in [
    '/home/gitte512/public_html/pos.granvilletea.com/error_log',
    '/home/gitte512/logs/pos.granvilletea.com.error.log',
    '/tmp/php-errors.log',
]:
    stdin, stdout, stderr = ssh.exec_command(f'tail -5 {log} 2>/dev/null')
    out = stdout.read().decode().strip()
    if out:
        print(f"\n=== {log} ===")
        print(out)

ssh.close()
