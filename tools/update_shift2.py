import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

db_user = 'gitte512_inventory_manager'
db_pass = 'Starlifter44*'
db_name = 'gitte512_git_inventory'

# First get the expected card total for shift 2
q1 = """
SELECT
    COALESCE((SELECT SUM(p.amount) FROM pos_payments p JOIN pos_transactions t ON p.transaction_id = t.id WHERE t.shift_id = 2 AND t.status IN ('completed','partial_refund') AND p.method IN ('card','moneris')), 0)
    - COALESCE((SELECT SUM(rp.amount) FROM pos_refund_payments rp JOIN pos_refunds r ON rp.refund_id = r.id WHERE r.shift_id = 2 AND rp.method IN ('card','moneris')), 0)
    - COALESCE((SELECT SUM(rp.amount) FROM pos_standalone_refund_payments rp JOIN pos_standalone_refunds r ON rp.refund_id = r.id WHERE r.shift_id = 2 AND rp.method IN ('card','moneris')), 0)
    AS expected_card;
"""

cmd = f"mysql -u {db_user} -p'{db_pass}' {db_name} -e \"{q1}\""
stdin, stdout, stderr = ssh.exec_command(cmd)
out = stdout.read().decode()
err = stderr.read().decode()
print("Expected card calculation:")
print(out)
if 'ERROR' in err:
    print(err)

# Parse expected_card
lines = out.strip().split('\n')
expected_card = float(lines[-1].strip()) if len(lines) > 1 else 0

closing_card = 2257.30
card_over_short = round(closing_card - expected_card, 2)

print(f"Closing card: {closing_card}")
print(f"Expected card: {expected_card}")
print(f"Card over/short: {card_over_short}")

# Update the shift
q2 = f"UPDATE pos_shifts SET closing_card = {closing_card}, expected_card = {expected_card}, card_over_short = {card_over_short}, closing_tips = NULL WHERE id = 2;"
cmd2 = f"mysql -u {db_user} -p'{db_pass}' {db_name} -e \"{q2}\""
stdin, stdout, stderr = ssh.exec_command(cmd2)
out2 = stdout.read().decode()
err2 = stderr.read().decode()
if 'ERROR' in err2:
    print(f"UPDATE ERROR: {err2}")
else:
    print("Shift 2 updated successfully!")

# Verify
q3 = "SELECT id, closing_cash, expected_cash, over_short, closing_card, expected_card, card_over_short, closing_tips FROM pos_shifts WHERE id = 2;"
cmd3 = f"mysql -u {db_user} -p'{db_pass}' {db_name} -e \"{q3}\""
stdin, stdout, stderr = ssh.exec_command(cmd3)
print("\nVerification:")
print(stdout.read().decode())

ssh.close()
