import paramiko, os

HOST = 'granvilletea.com'
USER = 'gitte512'
PASS = '8q4grp0mdf'
REMOTE_BASE = '/home/gitte512/public_html/pos.granvilletea.com'
LOCAL_BASE = r'C:\Users\mark\pos'

files = [
    ('migrate_manual_entry.php', 'migrate_manual_entry.php'),
    ('app/controllers/ManualEntryController.php', 'app/controllers/ManualEntryController.php'),
    ('app/views/manual-entry/form.php', 'app/views/manual-entry/form.php'),
    ('app/models/Transaction.php', 'app/models/Transaction.php'),
    ('app/views/layouts/admin.php', 'app/views/layouts/admin.php'),
    ('app/views/transactions/list.php', 'app/views/transactions/list.php'),
    ('index.php', 'index.php'),
    ('schema.sql', 'schema.sql'),
]

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, port=22, username=USER, password=PASS)
sftp = ssh.open_sftp()

# Ensure remote dirs exist
for d in ['app/views/manual-entry']:
    remote_dir = f'{REMOTE_BASE}/{d}'
    try:
        sftp.stat(remote_dir)
    except FileNotFoundError:
        print(f'Creating directory: {remote_dir}')
        sftp.mkdir(remote_dir)

for local_rel, remote_rel in files:
    local_path = os.path.join(LOCAL_BASE, local_rel.replace('/', os.sep))
    remote_path = f'{REMOTE_BASE}/{remote_rel}'
    print(f'Uploading {local_rel} -> {remote_path}')
    sftp.put(local_path, remote_path)

print('\nAll files uploaded. Running migration...')
stdin, stdout, stderr = ssh.exec_command(f'cd {REMOTE_BASE} && php migrate_manual_entry.php')
print(stdout.read().decode())
err = stderr.read().decode()
if err:
    print(f'STDERR: {err}')

sftp.close()
ssh.close()
print('Done.')
