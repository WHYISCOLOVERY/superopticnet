#!/usr/bin/env python
"""Apply one tightly-scoped Cisco SNMP monitoring change.

The caller chooses one of a small set of fixed actions. Arbitrary CLI commands
are never accepted, and secret values are never returned in the result.
"""
import argparse
import json
import re
import socket
import sys
import time
import warnings

warnings.filterwarnings("ignore")

try:
    import paramiko
except Exception:
    print(json.dumps({"ok": False, "error": "paramiko_import_failed"}))
    raise SystemExit(2)


PROMPT = re.compile(r"(?m)^[\w.():/-]+[>#]\s*$")
CLI_ERROR = re.compile(r"%\s*(Invalid|Error|Incomplete|Ambiguous)|Authorization failed", re.I)


def read_until_prompt(channel, seconds=6.0):
    chunks = bytearray()
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline and len(chunks) < 65536:
        if channel.recv_ready():
            part = channel.recv(min(4096, 65536 - len(chunks)))
            if not part:
                break
            chunks.extend(part)
            text = chunks.decode("utf-8", errors="replace").replace("\r", "")
            if PROMPT.search(text) and text.rstrip().endswith((">", "#")):
                return text, True
        elif channel.closed:
            break
        else:
            time.sleep(0.03)
    return chunks.decode("utf-8", errors="replace"), False


def send(channel, command, seconds=6.0):
    channel.sendall(command + "\n")
    output, ready = read_until_prompt(channel, seconds)
    return ready and not CLI_ERROR.search(output)


def valid_name(value):
    return bool(re.fullmatch(r"[A-Za-z0-9_.:-]{1,64}", value))


def valid_ipv4(value):
    parts = value.split(".")
    return len(parts) == 4 and all(part.isdigit() and 0 <= int(part) <= 255 for part in parts)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--payload-stdin", action="store_true", required=True)
    parser.parse_args()
    payload = json.load(sys.stdin)
    host = str(payload.get("host", "")).strip()
    username = str(payload.get("username", "")).strip()
    password = str(payload.get("password", ""))
    port = int(payload.get("port", 22))
    timeout = max(1.0, int(payload.get("timeout_ms", 4000)) / 1000.0)
    action = str(payload.get("action", "")).strip()
    source_ip = str(payload.get("source_ip", "")).strip()
    acl_name = str(payload.get("acl", "")).strip()
    sequence = int(payload.get("sequence", 0))
    community = str(payload.get("community", "")).strip()

    if not host or not username or not password or not valid_ipv4(source_ip) or not valid_name(acl_name):
        print(json.dumps({"ok": False, "error": "invalid_required_arguments"}))
        return 2
    if action == "permit_snmp_source":
        if sequence < 1 or sequence > 999999:
            print(json.dumps({"ok": False, "error": "invalid_acl_sequence"}))
            return 2
        config_commands = [
            "configure terminal",
            "ip access-list standard " + acl_name,
            str(sequence) + " permit host " + source_ip,
            "end",
        ]
    elif action == "rollback_snmp_source":
        if sequence < 1 or sequence > 999999:
            print(json.dumps({"ok": False, "error": "invalid_acl_sequence"}))
            return 2
        config_commands = [
            "configure terminal",
            "ip access-list standard " + acl_name,
            "no " + str(sequence),
            "end",
        ]
    elif action == "configure_snmp_ro":
        if not re.fullmatch(r"[A-Za-z0-9_.:-]{16,64}", community):
            print(json.dumps({"ok": False, "error": "invalid_community"}))
            return 2
        config_commands = [
            "configure terminal",
            "ip access-list standard " + acl_name,
            "10 permit host " + source_ip,
            "exit",
            "snmp-server community " + community + " RO " + acl_name,
            "end",
        ]
    elif action == "rollback_snmp_ro":
        if not re.fullmatch(r"[A-Za-z0-9_.:-]{16,64}", community):
            print(json.dumps({"ok": False, "error": "invalid_community"}))
            return 2
        config_commands = [
            "configure terminal",
            "no snmp-server community " + community,
            "no ip access-list standard " + acl_name,
            "end",
        ]
    else:
        print(json.dumps({"ok": False, "error": "unsupported_action"}))
        return 2

    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        sock = socket.create_connection((host, port), timeout=timeout)
        transport = paramiko.Transport(sock)
        transport.auth_timeout = timeout
        options = transport.get_security_options()
        try:
            options.kex = [
                "diffie-hellman-group1-sha1",
                "diffie-hellman-group14-sha1",
                "diffie-hellman-group-exchange-sha1",
            ]
        except Exception:
            pass
        try:
            options.key_types = ["ssh-rsa", "rsa-sha2-256", "rsa-sha2-512"]
        except Exception:
            pass
        try:
            options.digests = ["hmac-sha1", "hmac-sha1-96", "hmac-sha2-256", "hmac-sha2-512"]
        except Exception:
            pass
        transport.banner_timeout = timeout
        transport.connect(username=username, password=password)
        client._transport = transport
        channel = client.invoke_shell(width=512, height=1000)
        initial, ready = read_until_prompt(channel, 5.0)
        if not ready or not initial.rstrip().endswith("#"):
            print(json.dumps({"ok": False, "error": "privileged_prompt_required"}))
            return 1

        completed = 0
        for command in config_commands:
            if not send(channel, command):
                print(json.dumps({"ok": False, "error": "config_command_failed", "completed_steps": completed}))
                return 1
            completed += 1
        if not send(channel, "write memory", 12.0):
            print(json.dumps({"ok": False, "error": "persist_failed", "completed_steps": completed}))
            return 1
        print(json.dumps({"ok": True, "action": action, "completed_steps": completed, "persisted": True}))
        return 0
    except Exception as exc:
        print(json.dumps({"ok": False, "error": exc.__class__.__name__}))
        return 1
    finally:
        try:
            client.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
