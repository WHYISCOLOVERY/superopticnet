#!/usr/bin/env python
import argparse
import json
import re
import sys
import time
import warnings

warnings.filterwarnings("ignore")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

try:
    import paramiko
except Exception as exc:
    print(json.dumps({"ok": False, "error": f"paramiko_import_failed: {exc}"}))
    raise SystemExit(2)


def connect_legacy_ssh(host: str, port: int, username: str, password: str, timeout: float):
    # Same legacy-KEX-tolerant connect as tools/hsgq-cli-discovery.py: some
    # older Cisco IOS (not IOS-XE) images still only offer diffie-hellman-
    # group1-sha1/ssh-rsa, which modern paramiko defaults can refuse.
    transport = paramiko.Transport((host, port))
    opts = transport.get_security_options()
    try:
        opts.kex = [
            "diffie-hellman-group14-sha1",
            "diffie-hellman-group1-sha1",
            "diffie-hellman-group-exchange-sha1",
        ]
    except Exception:
        pass
    try:
        opts.key_types = ["ssh-rsa", "rsa-sha2-256", "rsa-sha2-512"]
    except Exception:
        pass
    transport.banner_timeout = timeout
    transport.connect(username=username, password=password)
    client = paramiko.SSHClient()
    client._transport = transport
    return client


def read_idle(channel, initial_sleep: float = 0.9, poll: float = 0.15, loops: int = 18) -> str:
    time.sleep(initial_sleep)
    parts = []
    for _ in range(loops):
        if channel.recv_ready():
            parts.append(channel.recv(65535).decode("utf-8", errors="ignore"))
            time.sleep(poll)
            continue
        if parts:
            break
        time.sleep(poll)
    return "".join(parts)


def run_cmd(channel, command: str, initial_sleep: float = 0.9, poll: float = 0.15, loops: int = 18) -> str:
    channel.send(command + "\r")
    return read_idle(channel, initial_sleep=initial_sleep, poll=poll, loops=loops)


def last_prompt(text: str) -> str:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    for line in reversed(lines):
        if line.endswith(">") or line.endswith("#"):
            return line
    return ""


def normalize_output(text: str) -> str:
    if not text:
        return ""
    text = text.replace("\r", "")
    text = re.sub(r"\x1b\[[0-9;]*[A-Za-z]", "", text)
    return text.strip()


def looks_like_cisco_ios(version_output: str) -> bool:
    lowered = version_output.lower()
    return "cisco ios" in lowered or "ios-xe" in lowered or "ios xe" in lowered


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host")
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--username")
    parser.add_argument("--password")
    parser.add_argument("--payload-file")
    parser.add_argument("--timeout-ms", type=int, default=4500)
    args = parser.parse_args()

    if args.payload_file:
        with open(args.payload_file, "r", encoding="utf-8-sig") as handle:
            payload = json.load(handle)
        args.host = payload.get("host", args.host)
        args.port = int(payload.get("port", args.port))
        args.username = payload.get("username", args.username)
        args.password = payload.get("password", args.password)
        args.timeout_ms = int(payload.get("timeout_ms", args.timeout_ms))

    if not args.host or not args.username or args.password is None:
        print(json.dumps({"ok": False, "error": "missing_required_arguments"}))
        return 2

    timeout = max(1.2, args.timeout_ms / 1000.0)
    client = None
    channel = None
    try:
        client = connect_legacy_ssh(args.host, args.port, args.username, args.password, timeout)
        channel = client.invoke_shell(width=220, height=90)

        welcome = normalize_output(read_idle(channel, initial_sleep=1.2, poll=0.2, loops=24))
        prompts = {"welcome": last_prompt(welcome)}

        # Disable pagination FIRST -- the standard IOS automation practice --
        # so none of the following show commands can block on a "--More--"
        # prompt we would otherwise have to interactively page through.
        run_cmd(channel, "terminal length 0", initial_sleep=0.6, poll=0.15, loops=12)

        # Best-effort privileged mode: only attempted if we are not already
        # there (prompt ends in "#"). If the device demands a separate
        # enable secret we don't have, this simply times out with whatever
        # partial output comes back -- read-only show commands below still
        # work fine from user EXEC mode on a well-configured IOS device, so
        # this is not a hard failure.
        enable_attempted = not prompts["welcome"].endswith("#")
        if enable_attempted:
            enable_output = normalize_output(run_cmd(channel, "enable", initial_sleep=0.8, poll=0.15, loops=14))
            prompts["enable"] = last_prompt(enable_output)
            if not prompts["enable"].endswith("#"):
                # Likely an enable-secret prompt we can't answer; send a
                # blank line to avoid leaving the session stuck mid-prompt
                # for the next command.
                run_cmd(channel, "", initial_sleep=0.4, poll=0.1, loops=6)

        commands = {
            "show_version": "show version",
            "show_ip_interface_brief": "show ip interface brief",
            "show_interfaces_status": "show interfaces status",
            "show_vlan_brief": "show vlan brief",
            "show_mac_address_table": "show mac address-table",
            "show_inventory": "show inventory",
        }
        outputs = {}
        for key, command in commands.items():
            outputs[key] = normalize_output(run_cmd(channel, command, initial_sleep=1.0, poll=0.2, loops=26))

        hostname_match = re.search(r"^(\S+?)\s+uptime is", outputs["show_version"], re.MULTILINE)

        print(
            json.dumps(
                {
                    "ok": True,
                    "host": args.host,
                    "port": args.port,
                    "username": args.username,
                    "prompts": prompts,
                    "is_cisco_ios": looks_like_cisco_ios(outputs["show_version"]),
                    "hostname": hostname_match.group(1) if hostname_match else "",
                    "privileged_mode": prompts.get("enable", prompts["welcome"]).endswith("#"),
                    "outputs": outputs,
                    "welcome": welcome[:4000],
                },
                ensure_ascii=False,
            )
        )
        return 0
    except Exception as exc:
        print(
            json.dumps(
                {
                    "ok": False,
                    "host": args.host,
                    "port": args.port,
                    "username": args.username,
                    "error": f"{exc.__class__.__name__}: {exc}",
                },
                ensure_ascii=False,
            )
        )
        return 1
    finally:
        try:
            if channel is not None:
                channel.close()
        except Exception:
            pass
        try:
            if client is not None:
                client.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
