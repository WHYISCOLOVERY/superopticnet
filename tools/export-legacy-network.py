#!/usr/bin/env python3
"""Export legacy NAS and pool metadata through the authenticated billing UI."""

from __future__ import annotations

import hashlib
import json
import os
import pathlib
import re
import sys

import requests
import urllib3


def main() -> int:
    base = os.environ.get("LEGACY_BILLING_URL", "https://billing.superoptic.net").rstrip("/")
    email = os.environ.get("LEGACY_BILLING_EMAIL", "")
    password = os.environ.get("LEGACY_BILLING_PASSWORD", "")
    output_dir = pathlib.Path(os.environ.get("LEGACY_NETWORK_OUTPUT", "data/private/legacy-live-radius-network"))
    if not email or not password:
        print("Legacy credentials are required in environment variables.", file=sys.stderr)
        return 2

    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    output_dir.mkdir(parents=True, exist_ok=True)
    session = requests.Session()
    landing = session.get(base + "/", timeout=20, verify=False)
    landing.raise_for_status()
    token_match = re.search(r'name=["\']csrf_token["\']\s+value=["\']([^"\']+)', landing.text)
    if token_match is None:
        raise RuntimeError("CSRF token was not found")
    login = session.post(
        base + "/core/main/funksiya.php",
        data={"email": email, "pass": password, "csrf_token": token_match.group(1)},
        timeout=20,
        verify=False,
    )
    login.raise_for_status()
    if "success" not in login.text.lower():
        raise RuntimeError("Legacy login failed")

    requests_to_save = {
        "pool.json": ("post", "/backend/ppp_pool.php", {"action": "get_pool_data"}, 30),
        "zones.json": ("post", "/backend/ppp_pool.php", {"action": "get_zones", "pool_id": "1"}, 30),
        "pool_stats.json": ("post", "/backend/ppp_pool.php", {"action": "get_pool_stats", "pool_id": "1"}, 60),
        "nas.json": ("get", "/backend/nas.php?action=get_data", {}, 90),
    }
    summary: dict[str, object] = {}
    for name, (method, path, data, timeout) in requests_to_save.items():
        response = session.request(method, base + path, data=data, timeout=timeout, verify=False)
        response.raise_for_status()
        try:
            payload = response.json()
        except ValueError:
            payload = {
                "_non_json_sha256": hashlib.sha256(response.content).hexdigest(),
                "_bytes": len(response.content),
            }
        (output_dir / name).write_text(
            json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        if isinstance(payload, dict):
            summary[name] = {
                "keys": sorted(payload.keys()),
                "list_counts": {key: len(value) for key, value in payload.items() if isinstance(value, list)},
            }
        else:
            summary[name] = {"type": type(payload).__name__, "count": len(payload)}
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
