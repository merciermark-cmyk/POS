import paramiko, os

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

queries = [
    # Today's transactions
    "SELECT id, terminal_id, shift_id, type, total, tax_total, status, created_at FROM pos_transactions WHERE DATE(created_at) = CURDATE() ORDER BY id;",
    # Today's payments
    "SELECT p.id, p.transaction_id, p.method, p.amount, p.created_at FROM pos_payments p JOIN pos_transactions t ON p.transaction_id = t.id WHERE DATE(t.created_at) = CURDATE() ORDER BY p.id;",
    # Shift status
    "SELECT s.id, s.terminal_id, t.name as terminal, s.user_id, s.status, s.opening_float, s.closing_cash, s.expected_cash, s.opened_at, s.closed_at FROM pos_shifts s JOIN pos_terminals t ON s.terminal_id = t.id WHERE DATE(s.opened_at) >= CURDATE() ORDER BY s.id;",
    # Transaction count and totals summary
    "SELECT COUNT(*) as txn_count, SUM(total) as gross_total, SUM(tax_total) as tax_total, SUM(CASE WHEN type='refund' THEN total ELSE 0 END) as refund_total FROM pos_transactions WHERE DATE(created_at) = CURDATE() AND status='completed';",
]

for q in queries:
    cmd = f'mysql -u gitte512_inventory_manager -pB5xKz9mQ2v gitte512_git_inventory -e "{q}"'
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode()
    err = stderr.read().decode()
    print(f"\n--- QUERY ---\n{q}\n")
    if out:
        print(out)
    if err and 'Warning' not in err:
        print(f"ERROR: {err}")

ssh.close()
