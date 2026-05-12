import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

db_user = 'gitte512_inventory_manager'
db_pass = 'Starlifter44*'
db_name = 'gitte512_git_inventory'

def run(q):
    cmd = f"mysql -u {db_user} -p'{db_pass}' {db_name} -e \"{q}\""
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode().strip()
    err = stderr.read().decode().strip()
    if 'ERROR' in err:
        print(f"ERROR: {err}")
        return None
    return out

# Card payments for shift 2
print(run("SELECT COALESCE(SUM(p.amount),0) AS card_payments FROM pos_payments p JOIN pos_transactions t ON p.transaction_id=t.id WHERE t.shift_id=2 AND t.status IN ('completed','partial_refund') AND p.method IN ('card','moneris');"))

# Card refunds for shift 2
print(run("SELECT COALESCE(SUM(rp.amount),0) AS card_refunds FROM pos_refund_payments rp JOIN pos_refunds r ON rp.refund_id=r.id WHERE r.shift_id=2 AND rp.method IN ('card','moneris');"))

# Standalone card refunds for shift 2
print(run("SELECT COALESCE(SUM(amount),0) AS standalone_card_refunds FROM pos_standalone_refunds WHERE shift_id=2 AND payment_method IN ('card','moneris');"))

ssh.close()
