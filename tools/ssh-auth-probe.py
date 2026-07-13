#!/usr/bin/env python
import argparse
import json
import socket
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


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host")
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--username")
    parser.add_argument("--password")
    parser.add_argument("--payload-file")
    parser.add_argument("--timeout-ms", type=int, default=2200)
    args = parser.parse_args()

    if args.payload_file:
        with open(args.payload_file, "r", encoding="utf-8") as handle:
            payload = json.load(handle)
        args.host = payload.get("host", args.host)
        args.port = int(payload.get("port", args.port))
        args.username = payload.get("username", args.username)
        args.password = payload.get("password", args.password)
        args.timeout_ms = int(payload.get("timeout_ms", args.timeout_ms))

    if not args.host or not args.username or args.password is None:
        print(json.dumps({"ok": False, "error": "missing_required_arguments"}))
        return 2

    timeout = max(0.8, args.timeout_ms / 1000.0)
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        transport = paramiko.Transport((args.host, args.port))
        opts = transport.get_security_options()
        try:
            opts.kex = [
                "diffie-hellman-group1-sha1",
                "diffie-hellman-group14-sha1",
                "diffie-hellman-group-exchange-sha1",
            ]
        except Exception:
            pass
        try:
            opts.key_types = ["ssh-rsa", "rsa-sha2-256", "rsa-sha2-512"]
        except Exception:
            pass
        transport.banner_timeout = timeout
        transport.connect(username=args.username, password=args.password)
        client._transport = transport
        banner = ""
        try:
            chan = client.invoke_shell(width=200, height=80)
            time.sleep(0.8)
            if chan.recv_ready():
                banner = chan.recv(32768).decode("utf-8", errors="ignore")
            chan.close()
        except Exception:
            pass
        print(
            json.dumps(
                {
                    "ok": True,
                    "host": args.host,
                    "port": args.port,
                    "username": args.username,
                    "banner": banner,
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
            client.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
