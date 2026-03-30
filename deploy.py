import paramiko
import os

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', port=22, username='gitte512', password='8q4grp0mdf')

sftp = ssh.open_sftp()

remote_base = '/home/gitte512/public_html/pos.granvilletea.com'

# Ensure remote base exists
try:
    sftp.stat(remote_base)
except:
    sftp.mkdir(remote_base)

local_base = r'C:\Users\mark\pos'
skip_dirs = {'.git', '__pycache__', 'node_modules'}
skip_files = {'.env', 'deploy.py'}

uploaded = 0
for root, dirs, files in os.walk(local_base):
    dirs[:] = [d for d in dirs if d not in skip_dirs]
    rel_root = os.path.relpath(root, local_base).replace('\\', '/')
    if rel_root == '.':
        rel_root = ''

    remote_dir = remote_base + ('/' + rel_root if rel_root else '')

    # Create remote directory tree
    try:
        sftp.stat(remote_dir)
    except:
        parts = remote_dir.split('/')
        for i in range(1, len(parts) + 1):
            d = '/'.join(parts[:i])
            if not d:
                continue
            try:
                sftp.stat(d)
            except:
                sftp.mkdir(d)

    for f in files:
        if f in skip_files:
            continue
        local_path = os.path.join(root, f)
        rel_path = (rel_root + '/' + f) if rel_root else f
        remote_path = remote_base + '/' + rel_path
        sftp.put(local_path, remote_path)
        uploaded += 1
        print(f'  {rel_path}')

print(f'\nUploaded {uploaded} files to {remote_base}')

sftp.close()
ssh.close()
