#!/usr/bin/env python3
"""Create a secret-free structural inventory from the legacy billing snapshot."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path


SECRET_WORDS = ("password", "passwd", "secret", "token", "api_key", "apikey", "authorization")


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace") if path.is_file() else ""


def unique(values):
    return sorted({value for value in values if value})


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("snapshot", type=Path)
    parser.add_argument("--json", type=Path, required=True)
    args = parser.parse_args()
    root = args.snapshot.resolve()
    web = root / "var/www/html"
    radius = root / "etc/freeradius/3.0"

    php_files = list(web.rglob("*.php"))
    php_text = {p: read(p) for p in php_files}
    config_text = read(web / "core/main/config.php")
    payments_text = read(web / "payments/payments.php")
    check_text = read(web / "payments/check.php")
    listing_text = read(web / "backend/table/payment.php")
    sql_text = read(radius / "mods-available/sql")
    clients_text = read(radius / "clients.conf")
    cron_text = read(root / "var/spool/cron/crontabs/ttnetradius")

    schema_tables = {}
    mysql_root = root / "var/lib/mysql"
    if mysql_root.is_dir():
        for schema in mysql_root.iterdir():
            if not schema.is_dir() or schema.name in {"mysql", "sys", "performance_schema", "phpmyadmin"}:
                continue
            tables = [p.stem for p in schema.glob("*.frm")]
            schema_tables[schema.name] = unique(tables)

    providers = re.findall(r"\$row\[['\"]name['\"]\]\s*==\s*['\"]([^'\"]+)", listing_text)
    providers += re.findall(r"['\"]([A-Za-z0-9_. -]+)['\"]\s*=>", config_text)
    providers = [p for p in unique(providers) if not any(w in p.lower() for w in SECRET_WORDS)]

    request_fields = {
        "lookup_get": unique(re.findall(r"\$_GET\[['\"]([^'\"]+)", read(web / "payments/index.php"))),
        "payment_post": unique(re.findall(r"\$_POST\[['\"]([^'\"]+)", payments_text)),
        "check_get": unique(re.findall(r"\$_GET\[['\"]([^'\"]+)", check_text)),
    }

    sql_tables = []
    for text in php_text.values():
        sql_tables += re.findall(r"\b(?:FROM|JOIN|UPDATE|INTO)\s+`?([a-zA-Z][a-zA-Z0-9_]*)", text, re.I)

    updated_columns = unique(re.findall(r"\b(?:UPDATE|INSERT\s+INTO)\s+`?([a-zA-Z][a-zA-Z0-9_]*)", payments_text, re.I))
    response_codes = unique(re.findall(r"['\"]code['\"]\s*=>\s*([0-9]+)", payments_text + check_text))

    radius_driver = None
    radius_dialect = None
    radius_database = None
    for name, target in (("driver", "radius_driver"), ("dialect", "radius_dialect"), ("database", "radius_database")):
        match = re.search(rf"^\s*{name}\s*=\s*['\"]?([^'\"\s#]+)", sql_text, re.M)
        if match:
            if target == "radius_driver":
                radius_driver = match.group(1)
            elif target == "radius_dialect":
                radius_dialect = match.group(1)
            else:
                radius_database = match.group(1)

    clients = []
    for block in re.finditer(r"client\s+([^\s{]+)\s*\{(.*?)\}", clients_text, re.S):
        body = block.group(2)
        ip = re.search(r"^\s*(?:ipaddr|ipv4addr)\s*=\s*([^\s#]+)", body, re.M)
        clients.append({"name": block.group(1), "address": ip.group(1) if ip else None, "secret_present": bool(re.search(r"^\s*secret\s*=", body, re.M))})

    radius_enabled_references = []
    default_site = read(radius / "sites-available/default")
    for module in ("sql", "pap", "chap", "mschap", "expiration", "logintime", "detail", "unix"):
        if re.search(rf"(?m)^\s*{re.escape(module)}\b", default_site):
            radius_enabled_references.append(module)

    service_files = [p.name for p in (root / "etc/systemd/system").glob("radius*.service")]
    cron_commands = []
    for line in cron_text.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if any(word in line.lower() for word in SECRET_WORDS):
            line = "[REDACTED COMMAND CONTAINING SECRET-LIKE TEXT]"
        cron_commands.append(line)

    files = []
    for path in php_files:
        rel = path.relative_to(root).as_posix()
        data = path.read_bytes()
        files.append({"path": rel, "sha256": hashlib.sha256(data).hexdigest(), "bytes": len(data)})

    report = {
        "snapshot_root": str(root),
        "php_file_count": len(php_files),
        "database_schemas": schema_tables,
        "sql_tables_referenced": unique(sql_tables),
        "payment": {
            "endpoints": ["/payments/index.php", "/payments/payments.php", "/payments/check.php"],
            "request_fields": request_fields,
            "provider_labels": providers,
            "mutated_tables": updated_columns,
            "response_codes": response_codes,
            "duplicate_transaction_guard": bool(re.search(r"transaction_id.*LIMIT\s+1", payments_text, re.I | re.S)),
            "database_transaction_used": bool(re.search(r"beginTransaction\s*\(", payments_text)),
            "rollback_used": bool(re.search(r"rollBack\s*\(", payments_text)),
        },
        "radius": {
            "driver": radius_driver,
            "dialect": radius_dialect,
            "database": radius_database,
            "clients": clients,
            "default_site_modules": unique(radius_enabled_references),
            "service_files": unique(service_files),
            "cron_commands": cron_commands,
            "disconnect_script_present": (radius / "disconnect.py").is_file(),
        },
        "source_files": files,
    }
    args.json.parent.mkdir(parents=True, exist_ok=True)
    args.json.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({k: report[k] for k in ("php_file_count", "database_schemas", "payment", "radius")}, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
