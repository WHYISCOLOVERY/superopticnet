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


def normalize_output(text: str) -> str:
    if not text:
        return ""
    text = text.replace("\r", "")
    text = re.sub(r"\x1b\[[0-9;]*[A-Za-z]", "", text)
    return text.strip()


def last_prompt(text: str) -> str:
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    for line in reversed(lines):
        if line.endswith(">") or line.endswith("#"):
            return line
    return ""


def read_until_idle(channel, initial_sleep: float, poll: float, loops: int) -> str:
    time.sleep(initial_sleep)
    parts = []
    more_safeguard = 0
    for _ in range(loops):
        if channel.recv_ready():
            chunk = channel.recv(65535).decode("utf-8", errors="ignore")
            parts.append(chunk)
            if "--More--" in chunk or "-- More --" in chunk:
                channel.send(" ")
                more_safeguard += 1
                if more_safeguard > 25:
                    break
            time.sleep(poll)
            continue
        if parts:
            break
        time.sleep(poll)
    return "".join(parts)


def command_timing(command: str) -> tuple[float, float, int]:
    command = command.strip().lower()
    if "show ont-info all" in command or "show ont-optical all" in command:
        return (1.2, 0.2, 42)
    if "show ont-info " in command or "show ont-optical " in command:
        return (1.0, 0.2, 32)
    if "show optical-state" in command or "show statistic" in command or "show gpon statistic" in command:
        return (1.0, 0.2, 28)
    return (0.85, 0.15, 22)


def run_command(channel, command: str) -> dict:
    initial_sleep, poll, loops = command_timing(command)
    channel.send(command + "\r")
    output = normalize_output(read_until_idle(channel, initial_sleep=initial_sleep, poll=poll, loops=loops))
    return {
        "command": command,
        "output": output,
        "prompt": last_prompt(output),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host")
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--username")
    parser.add_argument("--password")
    parser.add_argument("--payload-file")
    parser.add_argument("--timeout-ms", type=int, default=5000)
    args = parser.parse_args()

    commands = []
    if args.payload_file:
        with open(args.payload_file, "r", encoding="utf-8-sig") as handle:
            payload = json.load(handle)
        args.host = payload.get("host", args.host)
        args.port = int(payload.get("port", args.port))
        args.username = payload.get("username", args.username)
        args.password = payload.get("password", args.password)
        args.timeout_ms = int(payload.get("timeout_ms", args.timeout_ms))
        commands = [str(item).strip() for item in payload.get("commands", []) if str(item).strip()]

    if not args.host or not args.username or args.password is None or not commands:
        print(json.dumps({"ok": False, "error": "missing_required_arguments"}))
        return 2

    timeout = max(1.2, args.timeout_ms / 1000.0)
    client = None
    channel = None
    try:
        client = connect_legacy_ssh(args.host, args.port, args.username, args.password, timeout)
        channel = client.invoke_shell(width=220, height=90)

        welcome = normalize_output(read_until_idle(channel, initial_sleep=1.2, poll=0.2, loops=24))
        steps = []
        for command in commands:
            steps.append(run_command(channel, command))

        print(
            json.dumps(
                {
                    "ok": True,
                    "host": args.host,
                    "port": args.port,
                    "username": args.username,
                    "welcome": welcome[:4000],
                    "steps": steps,
                    "final_prompt": steps[-1]["prompt"] if steps else last_prompt(welcome),
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
