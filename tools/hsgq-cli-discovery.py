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


def keywords_from_outputs(outputs: dict) -> list[str]:
    joined = "\n".join(str(value) for value in outputs.values())
    found = re.findall(
        r"\b(?:enable|show|configure|interface|ont|port|gpon|snmp|service|vlan|user|history|version|optical|statistic|transceiver|laser)\b",
        joined,
        re.IGNORECASE,
    )
    deduped = []
    for item in found:
        token = item.lower()
        if token not in deduped:
            deduped.append(token)
    return deduped


def first_ont_id_from_table(text: str) -> str:
    for raw_line in text.splitlines():
        line = raw_line.strip()
        match = re.match(r"^(\d+)\/(\d+)\s+\S+", line)
        if match:
            return match.group(2)
    return ""


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

        nodes = {}
        nodes["user_help"] = normalize_output(run_cmd(channel, "?", initial_sleep=0.9, poll=0.15, loops=18))
        nodes["show_help_user"] = normalize_output(run_cmd(channel, "show ?", initial_sleep=0.9, poll=0.15, loops=18))
        enable_output = normalize_output(run_cmd(channel, "enable", initial_sleep=0.8, poll=0.15, loops=18))
        prompts["enable"] = last_prompt(enable_output or nodes["show_help_user"])
        nodes["enable_help"] = normalize_output(run_cmd(channel, "?", initial_sleep=0.8, poll=0.15, loops=18))
        nodes["show_help_enable"] = normalize_output(run_cmd(channel, "show ?", initial_sleep=0.9, poll=0.15, loops=18))
        configure_output = normalize_output(run_cmd(channel, "configure", initial_sleep=0.8, poll=0.15, loops=18))
        prompts["config"] = last_prompt(configure_output)
        nodes["config_help"] = normalize_output(run_cmd(channel, "?", initial_sleep=0.8, poll=0.15, loops=18))
        nodes["interface_help"] = normalize_output(run_cmd(channel, "interface ?", initial_sleep=0.8, poll=0.15, loops=18))
        nodes["ont_help"] = normalize_output(run_cmd(channel, "ont ?", initial_sleep=0.8, poll=0.15, loops=18))
        nodes["port_help"] = normalize_output(run_cmd(channel, "port ?", initial_sleep=0.8, poll=0.15, loops=18))
        nodes["gpon_help"] = normalize_output(run_cmd(channel, "interface gpon ?", initial_sleep=0.8, poll=0.15, loops=18))
        gpon_context_output = normalize_output(run_cmd(channel, "interface gpon 1", initial_sleep=0.8, poll=0.15, loops=18))
        prompts["config_gpon_1"] = last_prompt(gpon_context_output)
        nodes["gpon_context_help"] = normalize_output(run_cmd(channel, "?", initial_sleep=0.8, poll=0.15, loops=20))
        nodes["show_help_gpon"] = normalize_output(run_cmd(channel, "show ?", initial_sleep=0.9, poll=0.15, loops=20))
        nodes["show_ont_info_help"] = normalize_output(run_cmd(channel, "show ont-info ?", initial_sleep=0.9, poll=0.15, loops=20))
        nodes["show_ont_optical_help"] = normalize_output(run_cmd(channel, "show ont-optical ?", initial_sleep=0.9, poll=0.15, loops=20))
        nodes["show_optical_state"] = normalize_output(run_cmd(channel, "show optical-state", initial_sleep=1.0, poll=0.2, loops=26))
        nodes["show_statistic"] = normalize_output(run_cmd(channel, "show statistic", initial_sleep=1.0, poll=0.2, loops=26))
        nodes["show_gpon_help"] = normalize_output(run_cmd(channel, "show gpon ?", initial_sleep=0.9, poll=0.15, loops=20))
        nodes["show_gpon_statistic"] = normalize_output(run_cmd(channel, "show gpon statistic", initial_sleep=1.0, poll=0.2, loops=26))
        nodes["show_port_help"] = normalize_output(run_cmd(channel, "show port ?", initial_sleep=0.9, poll=0.15, loops=20))
        nodes["show_port_transceiver"] = normalize_output(run_cmd(channel, "show port transceiver", initial_sleep=1.0, poll=0.2, loops=26))
        nodes["show_ont_info_all"] = normalize_output(run_cmd(channel, "show ont-info all", initial_sleep=1.2, poll=0.2, loops=42))

        sample_ont_id = first_ont_id_from_table(nodes["show_ont_info_all"])
        if sample_ont_id:
            nodes["show_ont_info_sample"] = normalize_output(run_cmd(channel, f"show ont-info {sample_ont_id}", initial_sleep=1.0, poll=0.2, loops=30))
            nodes["show_ont_optical_sample"] = normalize_output(run_cmd(channel, f"show ont-optical {sample_ont_id}", initial_sleep=1.0, poll=0.2, loops=24))
            nodes["show_ont_port_vlan_sample"] = normalize_output(run_cmd(channel, f"show ont-port-vlan {sample_ont_id}", initial_sleep=1.0, poll=0.2, loops=24))
        else:
            nodes["show_ont_info_sample"] = ""
            nodes["show_ont_optical_sample"] = ""
            nodes["show_ont_port_vlan_sample"] = ""

        nodes["show_ont_optical_all"] = normalize_output(run_cmd(channel, "show ont-optical all", initial_sleep=1.2, poll=0.2, loops=42))

        print(
            json.dumps(
                {
                    "ok": True,
                    "host": args.host,
                    "port": args.port,
                    "username": args.username,
                    "prompts": prompts,
                    "nodes": nodes,
                    "keywords": keywords_from_outputs(nodes),
                    "sample_ont_id": sample_ont_id,
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
