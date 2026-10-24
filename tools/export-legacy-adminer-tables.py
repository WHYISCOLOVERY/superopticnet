#!/usr/bin/env python3
"""Read selected legacy tables through Adminer without exposing credentials."""

from __future__ import annotations

import hashlib
import json
import os
import pathlib
import re
import urllib.parse

import requests
import urllib3
from bs4 import BeautifulSoup


def php_value(source: str, name: str) -> str:
    match = re.search(r"\$" + re.escape(name) + r"\s*=\s*['\"]([^'\"]*)", source)
    if match is None:
        raise RuntimeError(f"Missing PHP variable: {name}")
    return match.group(1)


def adminer_login(base: str, source: str, suffix: str) -> tuple[requests.Session, dict[str, str]]:
    session = requests.Session()
    session.get(base, verify=False, timeout=20).raise_for_status()
    response = session.post(
        base,
        data={
            "auth[driver]": "server",
            "auth[server]": php_value(source, "db_host" + suffix),
            "auth[username]": php_value(source, "db_user" + suffix),
            "auth[password]": php_value(source, "db_pass" + suffix),
            "auth[db]": php_value(source, "db_name" + suffix),
        },
        verify=False,
        timeout=30,
    )
    response.raise_for_status()
    if 'auth[password]' in response.text:
        raise RuntimeError("Adminer login failed")
    query = urllib.parse.parse_qs(urllib.parse.urlparse(response.url).query)
    return session, {key: values[-1] for key, values in query.items()}


def export_table(
    session: requests.Session,
    base: str,
    base_params: dict[str, str],
    table: str,
) -> dict[str, object]:
    params = dict(base_params)
    params.update({"select": table, "limit": "10000"})
    response = session.get(base, params=params, verify=False, timeout=60)
    response.raise_for_status()
    soup = BeautifulSoup(response.content, "lxml")
    data_table = soup.find("table")
    if data_table is None:
        raise RuntimeError(f"Adminer table not found: {table}")
    rows = data_table.find_all("tr")
    if not rows:
        return {"table": table, "columns": [], "rows": []}
    header_cells = rows[0].find_all("th", recursive=False)
    columns: list[str] = []
    for cell in header_cells:
        link = cell.find("a")
        label = (link.get_text(" ", strip=True) if link else cell.get_text(" ", strip=True)).strip()
        label = re.sub(r"\s+[↓↑=].*$", "", label).strip()
        columns.append(label)
    exported_rows: list[dict[str, str | None]] = []
    for row in rows[1:]:
        cells = row.find_all("td", recursive=False)
        if not cells:
            continue
        values = cells[1:] if cells[0].find("input", {"name": "check[]"}) else cells
        record: dict[str, str | None] = {}
        for index, column in enumerate(columns):
            if index >= len(values):
                record[column] = None
                continue
            text = values[index].get_text(" ", strip=True)
            record[column] = None if text == "NULL" else text
        exported_rows.append(record)
    return {"table": table, "columns": columns, "rows": exported_rows}


def main() -> int:
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    root = pathlib.Path(os.environ.get("SUPEROPTIC_ROOT", pathlib.Path(__file__).resolve().parents[1]))
    config_path = root / "data/private/legacy-billing-audit-source-20261016/var/www/html/core/main/config.php"
    output_dir = root / "data/private/legacy-live-radius-network"
    output_dir.mkdir(parents=True, exist_ok=True)
    source = config_path.read_text(encoding="utf-8", errors="ignore")
    base = "https://billing.superoptic.net/adminer/"
    plans = {
        "radius": ("_r", ["nas", "definitions", "port_mappings"]),
        "billing": ("", ["ppp_pools", "ppp_zones", "router", "static_ip"]),
    }
    summary: dict[str, object] = {}
    for group, (suffix, tables) in plans.items():
        session, params = adminer_login(base, source, suffix)
        for table in tables:
            try:
                payload = export_table(session, base, params, table)
            except RuntimeError as error:
                summary[f"{group}.{table}"] = {"error": str(error)}
                continue
            path = output_dir / f"adminer-{group}-{table}.json"
            encoded = json.dumps(payload, ensure_ascii=False, indent=2).encode("utf-8")
            path.write_bytes(encoded)
            summary[f"{group}.{table}"] = {
                "rows": len(payload["rows"]),
                "sha256": hashlib.sha256(encoded).hexdigest(),
            }
    print(json.dumps(summary, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
