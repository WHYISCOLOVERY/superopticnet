#!/usr/bin/env python
"""Read only Cisco SNMP RO metadata for the local vault reconciler.

Credentials and the resulting community strings travel only over stdin/stdout
pipes owned by the parent PHP CLI process. Nothing is written to disk or logs.
"""
import argparse
import ipaddress
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


def read_until_prompt(channel, seconds=5.0):
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


def parse_ro_entries(text):
    entries = []
    seen = set()
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line.lower().startswith("snmp-server community "):
            continue
        tokens = line.split()
        permission_index = next(
            (idx for idx, token in enumerate(tokens) if token.upper() in ("RO", "RW")),
            None,
        )
        if permission_index is None or tokens[permission_index].upper() != "RO" or len(tokens) < 4:
            continue
        # Normal IOS form: snmp-server community <value> RO [acl]. A type marker
        # is not a usable SNMP community, so fail closed instead of guessing.
        if tokens[2] in ("0", "5", "7", "8", "9"):
            continue
        community = tokens[2]
        acl = tokens[permission_index + 1] if permission_index + 1 < len(tokens) else ""
        identity = (community, acl)
        if identity in seen:
            continue
        seen.add(identity)
        entries.append({"community": community, "acl": acl})
    return entries


def snmp_shape(text):
    counts = {
        "community_ro_count": 0,
        "community_rw_count": 0,
        "group_count": 0,
        "user_count": 0,
        "host_count": 0,
        "view_count": 0,
        "trap_enable_count": 0,
    }
    for raw_line in text.splitlines():
        line = raw_line.strip().lower()
        if not line.startswith("snmp-server "):
            continue
        if line.startswith("snmp-server community "):
            if re.search(r"\sro(?:\s|$)", line):
                counts["community_ro_count"] += 1
            if re.search(r"\srw(?:\s|$)", line):
                counts["community_rw_count"] += 1
        elif line.startswith("snmp-server group "):
            counts["group_count"] += 1
        elif line.startswith("snmp-server user "):
            counts["user_count"] += 1
        elif line.startswith("snmp-server host "):
            counts["host_count"] += 1
        elif line.startswith("snmp-server view "):
            counts["view_count"] += 1
        elif line.startswith("snmp-server enable traps"):
            counts["trap_enable_count"] += 1
    counts["configured"] = any(value > 0 for value in counts.values())
    return counts


def acl_source_status(text, source_ip):
    try:
        source = int(ipaddress.IPv4Address(source_ip))
    except Exception:
        return {"readable": False, "entry_count": 0, "source_permitted": False}

    rules = []
    sequences = []
    deny_any_sequence = None
    for raw_line in text.splitlines():
        line = re.sub(r"\s*\(\d+ matches?\)\s*$", "", raw_line.strip(), flags=re.I)
        match = re.match(r"^(?:(\d+)\s+)?(permit|deny)\s+(?:host\s+)?(any|\d+\.\d+\.\d+\.\d+)(?:\s+(\d+\.\d+\.\d+\.\d+))?", line, re.I)
        if not match:
            continue
        sequence_text, action, network_text, wildcard_text = match.groups()
        sequence = int(sequence_text) if sequence_text is not None else None
        if sequence is not None:
            sequences.append(sequence)
        if network_text.lower() == "any":
            matches = True
            if action.lower() == "deny" and deny_any_sequence is None:
                deny_any_sequence = sequence
        else:
            try:
                network = int(ipaddress.IPv4Address(network_text))
                wildcard = int(ipaddress.IPv4Address(wildcard_text or "0.0.0.0"))
                mask = (~wildcard) & 0xFFFFFFFF
                matches = (source & mask) == (network & mask)
            except Exception:
                continue
        rules.append((action.lower(), matches))

    decision = False
    for action, matches in rules:
        if matches:
            decision = action == "permit"
            break
    suggested_sequence = None
    if deny_any_sequence is not None:
        lower = max((item for item in sequences if item < deny_any_sequence), default=0)
        if lower + 1 < deny_any_sequence:
            suggested_sequence = lower + max(1, (deny_any_sequence - lower) // 2)
    elif sequences:
        suggested_sequence = max(sequences) + 10
    return {
        "readable": bool(rules),
        "standard": bool(re.search(r"Standard IP access list", text, re.I)),
        "entry_count": len(rules),
        "source_permitted": decision,
        "suggested_sequence": suggested_sequence,
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--payload-stdin", action="store_true", required=True)
    args = parser.parse_args()
    payload = json.load(sys.stdin)
    host = str(payload.get("host", "")).strip()
    username = str(payload.get("username", "")).strip()
    password = str(payload.get("password", ""))
    port = int(payload.get("port", 22))
    timeout = max(1.0, int(payload.get("timeout_ms", 3500)) / 1000.0)
    source_ip = str(payload.get("source_ip", "")).strip()
    if not host or not username or not password:
        print(json.dumps({"ok": False, "error": "missing_required_arguments"}))
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
        if not ready:
            print(json.dumps({"ok": False, "error": "initial_prompt_not_received"}))
            return 1
        privileged = initial.rstrip().endswith("#")

        channel.sendall("terminal length 0\n")
        _, ready = read_until_prompt(channel, 3.0)
        if not ready:
            print(json.dumps({"ok": False, "error": "paging_command_timeout", "privileged": privileged}))
            return 1

        channel.sendall("show running-config | include snmp-server\n")
        output, ready = read_until_prompt(channel, 7.0)
        if not ready:
            print(json.dumps({"ok": False, "error": "config_read_timeout", "privileged": privileged}))
            return 1
        if re.search(r"%\s*(Invalid|Error|Incomplete|Ambiguous)|Authorization failed", output, re.I):
            print(json.dumps({"ok": False, "error": "config_read_not_authorized", "privileged": privileged}))
            return 1

        entries = parse_ro_entries(output)
        acl_statuses = []
        acl_names = sorted({entry["acl"] for entry in entries if entry.get("acl")})
        for acl_name in acl_names:
            if not re.fullmatch(r"[A-Za-z0-9_.:-]{1,64}", acl_name):
                continue
            channel.sendall("show access-lists " + acl_name + "\n")
            acl_output, acl_ready = read_until_prompt(channel, 5.0)
            status = acl_source_status(acl_output, source_ip) if acl_ready else {
                "readable": False,
                "entry_count": 0,
                "source_permitted": False,
            }
            status["acl"] = acl_name
            acl_statuses.append(status)

        print(json.dumps({
            "ok": True,
            "privileged": privileged,
            "entries": entries,
            "snmp_shape": snmp_shape(output),
            "acl_statuses": acl_statuses,
        }))
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
