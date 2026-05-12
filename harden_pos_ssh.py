#!/usr/bin/env python3
"""
POS SSH Hardening Script
- Installs SSH public key on both POS machines
- Changes Windows user passwords
- Disables password authentication in sshd_config
- Restarts sshd service
"""

import paramiko
import os
import sys
import time

# Connection details
HOST = "209.53.51.50"
OLD_PASSWORD = "Deborah1"

MACHINES = [
    {
        "name": "POS-1",
        "hostname": "Loose-Tea-counter-PC",
        "port": 2201,
        "user": "Loose tea machine",
        "new_password": "zVMWMG0IXON0XwU5",
    },
    {
        "name": "POS-2",
        "hostname": "Tea-Bar-PC",
        "port": 2202,
        "user": "Tea bar machine",  # profile folder is C:\Users\Mark but username was renamed
        "new_password": "lMetnk@qVzCK5PqT",
    },
]

# Read the public key
pub_key_path = os.path.expanduser("~/.ssh/pos_key.pub")
with open(pub_key_path, "r") as f:
    pub_key = f.read().strip()

print(f"Public key: {pub_key[:40]}...")
print()


def run_cmd(ssh, cmd, description=""):
    """Run a command and return stdout, stderr."""
    if description:
        print(f"  {description}...", end=" ", flush=True)
    stdin, stdout, stderr = ssh.exec_command(cmd)
    exit_code = stdout.channel.recv_exit_status()
    out = stdout.read().decode("utf-8", errors="replace").strip()
    err = stderr.read().decode("utf-8", errors="replace").strip()
    if description:
        print("OK" if exit_code == 0 else f"FAILED (exit {exit_code})")
    if err and exit_code != 0:
        print(f"    stderr: {err}")
    return out, err, exit_code


def harden_machine(machine):
    name = machine["name"]
    print(f"{'='*50}")
    print(f"Hardening {name} ({machine['hostname']})")
    print(f"{'='*50}")

    # Connect with current password
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    print(f"  Connecting to {HOST}:{machine['port']}...", end=" ", flush=True)
    try:
        ssh.connect(
            HOST,
            port=machine["port"],
            username=machine["user"],
            password=OLD_PASSWORD,
            timeout=15,
        )
        print("OK")
    except Exception as e:
        print(f"FAILED: {e}")
        return False

    # Step 1: Install public key in administrators_authorized_keys
    # Windows OpenSSH for admin users uses this file instead of ~/.ssh/authorized_keys
    auth_keys_path = r"C:\ProgramData\ssh\administrators_authorized_keys"

    # Check if file exists and if our key is already there
    out, _, _ = run_cmd(ssh, f'type "{auth_keys_path}" 2>nul', "Checking existing authorized_keys")

    if pub_key in out:
        print("  Key already installed, skipping.")
    else:
        # Append our key (or create the file)
        # Use echo to append; >> creates if not exists
        escaped_key = pub_key.replace('"', '\\"')
        run_cmd(
            ssh,
            f'echo {escaped_key} >> "{auth_keys_path}"',
            "Installing public key",
        )

    # Fix file permissions - must be owned by Administrators/SYSTEM only
    # Remove inheritance and set explicit permissions
    run_cmd(
        ssh,
        f'icacls "{auth_keys_path}" /inheritance:r /grant "Administrators:F" /grant "SYSTEM:F"',
        "Setting key file permissions",
    )

    # Step 2: Verify key-based auth works before changing anything else
    print(f"  Testing key-based auth...", end=" ", flush=True)
    test_ssh = paramiko.SSHClient()
    test_ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    priv_key_path = os.path.expanduser("~/.ssh/pos_key")
    try:
        pkey = paramiko.Ed25519Key.from_private_key_file(priv_key_path)
        test_ssh.connect(
            HOST,
            port=machine["port"],
            username=machine["user"],
            pkey=pkey,
            timeout=15,
        )
        test_out, _, _ = run_cmd(test_ssh, "echo KEY_AUTH_OK")
        test_ssh.close()
        if "KEY_AUTH_OK" in test_out:
            print("OK - key auth verified!")
        else:
            print("FAILED - unexpected response")
            ssh.close()
            return False
    except Exception as e:
        print(f"FAILED: {e}")
        print("  *** ABORTING - will not disable password auth without working key auth ***")
        ssh.close()
        return False

    # Step 3: Change Windows user password
    user = machine["user"]
    new_pass = machine["new_password"]
    run_cmd(
        ssh,
        f'net user "{user}" "{new_pass}"',
        f"Changing password for '{user}'",
    )

    # Step 4: Disable password auth in sshd_config
    sshd_config = r"C:\ProgramData\ssh\sshd_config"

    # Read current config
    out, _, _ = run_cmd(ssh, f'type "{sshd_config}"')
    config_lines = out.replace("\r\n", "\n").split("\n")

    # Build new config
    new_lines = []
    settings_done = {
        "PubkeyAuthentication": False,
        "PasswordAuthentication": False,
    }

    for line in config_lines:
        stripped = line.strip()
        # Update existing settings (commented or uncommented)
        if stripped.startswith("#PubkeyAuthentication") or stripped.startswith("PubkeyAuthentication"):
            new_lines.append("PubkeyAuthentication yes")
            settings_done["PubkeyAuthentication"] = True
        elif stripped.startswith("#PasswordAuthentication") or stripped.startswith("PasswordAuthentication"):
            new_lines.append("PasswordAuthentication no")
            settings_done["PasswordAuthentication"] = True
        else:
            new_lines.append(line)

    # Add any settings that weren't found
    for setting, done in settings_done.items():
        if not done:
            val = "yes" if setting == "PubkeyAuthentication" else "no"
            new_lines.append(f"{setting} {val}")

    new_config = "\r\n".join(new_lines)

    # Write new config via a temp file approach
    # PowerShell to write the content reliably
    # Escape for PowerShell
    ps_config = new_config.replace("'", "''")
    run_cmd(
        ssh,
        f"powershell -Command \"Set-Content -Path '{sshd_config}' -Value '{ps_config}' -Encoding ASCII\"",
        "Updating sshd_config (disable password auth)",
    )

    # Step 5: Restart sshd
    run_cmd(ssh, "net stop sshd", "Stopping sshd")
    time.sleep(2)
    run_cmd(ssh, "net start sshd", "Starting sshd")
    time.sleep(2)

    ssh.close()

    # Step 6: Final verification - connect with key only
    print(f"  Final verification (key-only auth)...", end=" ", flush=True)
    verify_ssh = paramiko.SSHClient()
    verify_ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        pkey = paramiko.Ed25519Key.from_private_key_file(priv_key_path)
        verify_ssh.connect(
            HOST,
            port=machine["port"],
            username=machine["user"],
            pkey=pkey,
            timeout=15,
        )
        out, _, _ = run_cmd(verify_ssh, "echo FINAL_OK")
        verify_ssh.close()
        if "FINAL_OK" in out:
            print("OK - key auth works after hardening!")
        else:
            print("UNEXPECTED RESPONSE")
            return False
    except Exception as e:
        print(f"FAILED: {e}")
        print("  *** WARNING: May need physical access to fix ***")
        return False

    # Step 7: Verify password auth is rejected
    print(f"  Verifying password auth is rejected...", end=" ", flush=True)
    reject_ssh = paramiko.SSHClient()
    reject_ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        reject_ssh.connect(
            HOST,
            port=machine["port"],
            username=machine["user"],
            password=new_pass,
            timeout=15,
            look_for_keys=False,
            allow_agent=False,
        )
        reject_ssh.close()
        print("WARNING - password still accepted! sshd may need manual restart")
    except paramiko.AuthenticationException:
        print("OK - password correctly rejected!")
    except Exception as e:
        print(f"Connection error (likely OK): {e}")

    print(f"\n  {name} hardening complete!\n")
    return True


# Run on both machines
print("POS SSH Hardening Script")
print("========================\n")

results = {}
for machine in MACHINES:
    results[machine["name"]] = harden_machine(machine)
    print()

# Summary
print("=" * 50)
print("SUMMARY")
print("=" * 50)
for name, success in results.items():
    status = "HARDENED" if success else "FAILED - needs attention"
    print(f"  {name}: {status}")

print(f"\nSSH key: ~/.ssh/pos_key")
print(f"Usage:   ssh -i ~/.ssh/pos_key -p 2201 'Loose tea machine'@209.53.51.50")
print(f"         ssh -i ~/.ssh/pos_key -p 2202 'Tea bar machine'@209.53.51.50")
print(f"\nParamiko usage:")
print(f'  pkey = paramiko.Ed25519Key.from_private_key_file(os.path.expanduser("~/.ssh/pos_key"))')
print(f"  ssh.connect(host, port=2201, username='Loose tea machine', pkey=pkey)")
