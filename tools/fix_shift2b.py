import paramiko

ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect('granvilletea.com', username='gitte512', password='8q4grp0mdf')

closing_card = 2257.30
expected_card = 1982.30
card_over_short = round(closing_card - expected_card, 2)

print(f"Closing card (terminal batch): ${closing_card}")
print(f"Expected card (POS total):     ${expected_card}")
print(f"Card over/short:               ${card_over_short}")

q = f"UPDATE pos_shifts SET closing_card={closing_card}, expected_card={expected_card}, card_over_short={card_over_short}, closing_tips=NULL WHERE id=2;"
cmd = f"mysql -u gitte512_inventory_manager -p'Starlifter44*' gitte512_git_inventory -e \"{q}\""
stdin, stdout, stderr = ssh.exec_command(cmd)
err = stderr.read().decode()
if 'ERROR' in err:
    print(f"ERROR: {err}")
else:
    print("Updated successfully!")

# Verify
cmd2 = "mysql -u gitte512_inventory_manager -p'Starlifter44*' gitte512_git_inventory -e \"SELECT id, closing_card, expected_card, card_over_short, closing_tips FROM pos_shifts WHERE id=2;\""
stdin, stdout, stderr = ssh.exec_command(cmd2)
print("\n" + stdout.read().decode())

ssh.close()
