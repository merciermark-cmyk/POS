#!/usr/bin/env python3
"""Fix sshd_config on both POS machines to disable password auth."""

import paramiko
import os
import time

HOST = "209.53.51.50"
KEY_PATH = os.path.expanduser("~/.ssh/pos_key")
pkey = paramiko.Ed25519Key.from_private_key_file(KEY_PATH)

MACHINES = [
    {"name": "POS-1", "port": 2201, "user": "Loose tea machine"},
    {"name": "POS-2", "port": 2202, "user": "Tea bar machine"},
]

SSHD_CONFIG = r"C:\ProgramData\ssh\sshd_config"

# PowerShell script to do targeted replacements - avoids quoting issues
PS_SCRIPT = r"""
$f = Get-Content 'C:\ProgramData\ssh\sshd_config'
$out = @()
$setPubkey = $false
$setPasswd = $false
foreach ($line in $f) {
    if ($line -match '^\s*#?\s*PubkeyAuthentication\s') {
        $out += 'PubkeyAuthentication yes'
        $setPubkey = $true
    } elseif ($line -match '^\s*#?\s*PasswordAuthentication\s') {
        $out += 'PasswordAuthentication no'
        $setPasswd = $true
    } else {
        $out += $line
    }
}
if (-not $setPubkey) { $out += 'PubkeyAuthentication yes' }
if (-not $setPasswd) { $out += 'PasswordAuthentication no' }
$out | Set-Content 'C:\ProgramData\ssh\sshd_config' -Encoding ASCII
Write-Output 'CONFIG_UPDATED'
"""

for machine in MACHINES:
    print(f"\n{'='*50}")
    print(f"Fixing sshd_config on {machine['name']}")
    print(f"{'='*50}")

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, port=machine["port"], username=machine["user"], pkey=pkey, timeout=15)

    # Upload PS script as a temp file to avoid escaping issues
    sftp = ssh.open_sftp()
    remote_script = "C:/ProgramData/ssh/fix_sshd.ps1"
    with sftp.open(remote_script, "w") as f:
        f.write(PS_SCRIPT)
    sftp.close()
    print("  Uploaded fix script")

    # Run the PowerShell script
    stdin, stdout, stderr = ssh.exec_command(f'powershell -ExecutionPolicy Bypass -File "{remote_script}"')
    exit_code = stdout.channel.recv_exit_status()
    out = stdout.read().decode().strip()
    err = stderr.read().decode().strip()
    print(f"  Config update: {'OK' if 'CONFIG_UPDATED' in out else 'FAILED'}")
    if err:
        print(f"  stderr: {err}")

    # Clean up temp script
    ssh.exec_command(f'del "{remote_script}"')
    time.sleep(1)

    # Verify the config
    stdin, stdout, stderr = ssh.exec_command(f'findstr /i "Authentication" "{SSHD_CONFIG}"')
    out = stdout.read().decode().strip()
    print(f"  Config verification:\n    {out.replace(chr(10), chr(10) + '    ')}")

    # Restart sshd
    ssh.exec_command("net stop sshd")
    time.sleep(2)
    stdin, stdout, stderr = ssh.exec_command("net start sshd")
    stdout.channel.recv_exit_status()
    print("  sshd restarted")
    time.sleep(2)

    ssh.close()

    # Verify password auth is rejected
    print(f"  Verifying password auth rejected...", end=" ", flush=True)
    test_ssh = paramiko.SSHClient()
    test_ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        test_ssh.connect(
            HOST, port=machine["port"], username=machine["user"],
            password="zVMWMG0IXON0XwU5" if machine["name"] == "POS-1" else "lMetnk@qVzCK5PqT",
            timeout=15, look_for_keys=False, allow_agent=False,
        )
        test_ssh.close()
        print("WARNING - password still accepted")
    except paramiko.AuthenticationException:
        print("OK - password correctly rejected!")
    except Exception as e:
        print(f"Error: {e}")

    # Verify key auth still works
    print(f"  Verifying key auth works...", end=" ", flush=True)
    test_ssh2 = paramiko.SSHClient()
    test_ssh2.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        test_ssh2.connect(HOST, port=machine["port"], username=machine["user"], pkey=pkey, timeout=15)
        stdin, stdout, stderr = test_ssh2.exec_command("echo KEY_OK")
        out = stdout.read().decode().strip()
        test_ssh2.close()
        print("OK" if "KEY_OK" in out else "UNEXPECTED")
    except Exception as e:
        print(f"FAILED: {e}")
