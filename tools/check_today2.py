import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

# Get production DB creds from .env
stdin, stdout, stderr = ssh.exec_command('cat /home/gitte512/public_html/pos.granvilletea.com/.env')
env = stdout.read().decode()
print("=== Production .env ===")
print(env)

# Parse DB creds
creds = {}
for line in env.strip().split('\n'):
    if '=' in line and not line.startswith('#'):
        k, v = line.split('=', 1)
        creds[k.strip()] = v.strip().strip('"')

db_user = creds.get('DB_USER', '')
db_pass = creds.get('DB_PASS', '')
db_name = creds.get('DB_NAME', '')

print(f"\nUsing: {db_user}@{db_name}")

queries = [
    ("Today's transactions",
     "SELECT id, terminal_id, shift_id, type, total, tax_total, status, created_at FROM pos_transactions WHERE DATE(created_at) = CURDATE() ORDER BY id"),
    ("Today's payments",
     "SELECT p.id, p.transaction_id, p.method, p.amount FROM pos_payments p JOIN pos_transactions t ON p.transaction_id = t.id WHERE DATE(t.created_at) = CURDATE() ORDER BY p.id"),
    ("Today's shifts",
     "SELECT s.id, s.terminal_id, t.name as terminal, s.status, s.opening_float, s.closing_cash, s.expected_cash, s.opened_at, s.closed_at FROM pos_shifts s JOIN pos_terminals t ON s.terminal_id = t.id WHERE DATE(s.opened_at) >= CURDATE() ORDER BY s.id"),
    ("Summary",
     "SELECT COUNT(*) as txn_count, SUM(total) as gross_total, SUM(tax_total) as tax_total FROM pos_transactions WHERE DATE(created_at) = CURDATE() AND status='completed'"),
]

for label, q in queries:
    cmd = f"mysql -u {db_user} -p'{db_pass}' {db_name} -e \"{q};\""
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode()
    err = stderr.read().decode()
    print(f"\n=== {label} ===")
    if out:
        print(out)
    if err and 'Warning' not in err:
        print(f"ERROR: {err}")

ssh.close()
