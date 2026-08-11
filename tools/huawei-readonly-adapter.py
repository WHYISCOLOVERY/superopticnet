#!/usr/bin/env python3
"""Transport-neutral, read-only discovery scaffold for two Huawei OLT releases."""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path
from typing import Any


SCHEMA_VERSION = 1
FEATURE_FLAG = "HUAWEI_READONLY_DISCOVERY_ENABLED"
CATALOG_FILES = {
    ("MA5608T", "V800R019C10"): "vendors/huawei/ma5608t-v800r019c10.json",
    ("MA5800-X7", "V100R024C10"): "vendors/huawei/ma5800-x7-v100r024c10.json",
}

SCALAR_OIDS = {
    "sys_descr": "1.3.6.1.2.1.1.1.0",
    "sys_object_id": "1.3.6.1.2.1.1.2.0",
    "sys_uptime": "1.3.6.1.2.1.1.3.0",
    "sys_name": "1.3.6.1.2.1.1.5.0",
}

WALK_OIDS = {
    "ent_physical_descr": "1.3.6.1.2.1.47.1.1.1.1.2",
    "ent_physical_class": "1.3.6.1.2.1.47.1.1.1.1.5",
    "ent_physical_name": "1.3.6.1.2.1.47.1.1.1.1.7",
    "ent_physical_hardware_rev": "1.3.6.1.2.1.47.1.1.1.1.8",
    "ent_physical_firmware_rev": "1.3.6.1.2.1.47.1.1.1.1.9",
    "ent_physical_software_rev": "1.3.6.1.2.1.47.1.1.1.1.10",
    "ent_physical_serial": "1.3.6.1.2.1.47.1.1.1.1.11",
    "ent_physical_mfg_name": "1.3.6.1.2.1.47.1.1.1.1.12",
    "ent_physical_model_name": "1.3.6.1.2.1.47.1.1.1.1.13",
    "if_descr": "1.3.6.1.2.1.2.2.1.2",
    "if_type": "1.3.6.1.2.1.2.2.1.3",
    "if_admin_status": "1.3.6.1.2.1.2.2.1.7",
    "if_oper_status": "1.3.6.1.2.1.2.2.1.8",
    "if_name": "1.3.6.1.2.1.31.1.1.1.1",
    "if_alias": "1.3.6.1.2.1.31.1.1.1.18",
}

COMMON_COMMANDS = {
    "version": "display version",
    "boards": "display board 0",
    "active_alarms": "display alarm active",
}

PROFILES = {
    ("MA5608T", "V800R019C10"): {
        "family": "MA5608T",
        "release": "V800R019C10",
        "product_pattern": r"^MA5608T$",
        "version_pattern": r"^MA5600V800R019C10$",
        "commands": COMMON_COMMANDS,
        "hardware_validated": False,
    },
    ("MA5800-X7", "V100R024C10"): {
        "family": "MA5800-X7",
        "release": "V100R024C10",
        "product_pattern": r"^MA5800-?X7$",
        "version_pattern": r"^MA5800V100R024C10$",
        "commands": COMMON_COMMANDS,
        "hardware_validated": False,
    },
}

FORBIDDEN_FIXTURE_KEYS = re.compile(
    r"(?:password|passwd|secret|community|username|private[_-]?key|auth[_-]?key|priv[_-]?key|token)",
    re.IGNORECASE,
)
FORBIDDEN_FIXTURE_TEXT = re.compile(
    r"\b(?:password|passwd|secret|community|username|private[ _-]?key|auth[ _-]?key|priv[ _-]?key|token)\b\s*[:=]",
    re.IGNORECASE,
)
BOARD_LINE = re.compile(
    r"^\s*(?P<slot>\d+(?:/\d+)?)\s+"
    r"(?P<board>H[A-Z0-9-]{3,})\s+"
    r"(?P<status>Active_normal|Standby_normal|Normal|Failed|Auto_find|Prohibited|Offline|Unknown)\b",
    re.IGNORECASE,
)
ALARM_LINE = re.compile(
    r"^\s*(?:(?P<sequence>\d+)\s+)?(?P<id>0x[0-9a-f]{8})\s+"
    r"(?P<severity>Critical|Major|Minor|Warning)\s+"
    r"(?P<raised>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\s+"
    r"(?P<text>.+?)\s*$",
    re.IGNORECASE,
)


class AdapterError(Exception):
    """Expected adapter policy or fixture validation failure."""


def canonical_family(value: str) -> str:
    normalized = re.sub(r"[ _]", "-", value.strip().upper())
    aliases = {
        "MA5608T": "MA5608T",
        "SMARTAX-MA5608T": "MA5608T",
        "MA5800-X7": "MA5800-X7",
        "MA5800X7": "MA5800-X7",
        "SMARTAX-MA5800-X7": "MA5800-X7",
    }
    if normalized not in aliases:
        raise AdapterError("unsupported_family")
    return aliases[normalized]


def profile_for(family: str, release: str) -> dict[str, Any]:
    key = (canonical_family(family), release.strip().upper())
    profile = PROFILES.get(key)
    if profile is None:
        raise AdapterError("unsupported_family_release")
    return profile


def env_enabled(name: str) -> bool:
    return os.getenv(name, "").strip().lower() in {"1", "true", "yes", "on"}


def require_production_gate(profile: dict[str, Any]) -> None:
    if not env_enabled(FEATURE_FLAG):
        raise AdapterError("feature_flag_disabled")
    if not profile["hardware_validated"]:
        raise AdapterError("real_hardware_not_validated")


def normalize_command(command: str) -> str:
    if any(character in command for character in ("\n", "\r", ";", "|", "&")):
        raise AdapterError("command_rejected")
    return " ".join(command.strip().lower().split())


def validate_command(profile: dict[str, Any], command: str) -> str:
    normalized = normalize_command(command)
    allowed = {normalize_command(value): value for value in profile["commands"].values()}
    if normalized not in allowed:
        raise AdapterError("command_not_allowlisted")
    return allowed[normalized]


def canonical_oid(oid: str) -> str:
    normalized = oid.strip().lstrip(".")
    if not re.fullmatch(r"\d+(?:\.\d+)+", normalized):
        raise AdapterError("invalid_oid")
    return normalized


def validate_oid(oid: str, operation: str, fixture_instance: bool = False) -> str:
    normalized = canonical_oid(oid)
    operation = operation.strip().lower()
    if operation == "get" and normalized in SCALAR_OIDS.values():
        return normalized
    if operation == "walk" and normalized in WALK_OIDS.values():
        return normalized
    if fixture_instance and operation == "fixture":
        if normalized in SCALAR_OIDS.values():
            return normalized
        if any(normalized.startswith(root + ".") for root in WALK_OIDS.values()):
            return normalized
    raise AdapterError("oid_or_operation_not_allowlisted")


def build_plan(profile: dict[str, Any], mock: bool = False) -> dict[str, Any]:
    if not mock:
        require_production_gate(profile)

    commands = []
    for key, command in profile["commands"].items():
        commands.append({"key": key, "command": validate_command(profile, command), "operation": "exec"})

    snmp = []
    for key, oid in SCALAR_OIDS.items():
        snmp.append({"key": key, "oid": validate_oid(oid, "get"), "operation": "get"})
    for key, oid in WALK_OIDS.items():
        snmp.append({"key": key, "oid": validate_oid(oid, "walk"), "operation": "walk"})

    return {
        "ok": True,
        "schema_version": SCHEMA_VERSION,
        "adapter": "huawei_readonly_discovery",
        "mode": "mock_plan" if mock else "production_plan",
        "transport": "none",
        "family": profile["family"],
        "release": profile["release"],
        "source_catalog": CATALOG_FILES[(profile["family"], profile["release"])],
        "feature_flag": FEATURE_FLAG,
        "hardware_validated": profile["hardware_validated"],
        "cli": commands,
        "snmp": snmp,
    }


def assert_fixture_has_no_credentials(value: Any, path: str = "fixture") -> None:
    if isinstance(value, dict):
        for key, child in value.items():
            if FORBIDDEN_FIXTURE_KEYS.search(str(key)):
                raise AdapterError("fixture_contains_credential_field")
            assert_fixture_has_no_credentials(child, f"{path}.{key}")
        return
    if isinstance(value, list):
        for index, child in enumerate(value):
            assert_fixture_has_no_credentials(child, f"{path}[{index}]")
        return
    if isinstance(value, str) and FORBIDDEN_FIXTURE_TEXT.search(value):
        raise AdapterError("fixture_contains_credential_text")


def first_field(text: str, label: str) -> str:
    match = re.search(rf"(?im)^\s*{re.escape(label)}\s*:\s*(.+?)\s*$", text)
    return match.group(1).strip() if match else ""


def parse_version(profile: dict[str, Any], text: str) -> dict[str, str]:
    version = first_field(text, "VERSION")
    product = first_field(text, "PRODUCT")
    patch = first_field(text, "PATCH")
    uptime_match = re.search(r"(?im)^\s*.+?\s+uptime\s+is\s+(.+?)\s*$", text)
    uptime = uptime_match.group(1).strip() if uptime_match else ""

    if not version or not re.fullmatch(profile["version_pattern"], version, re.IGNORECASE):
        raise AdapterError("fixture_version_mismatch")
    if not product or not re.fullmatch(profile["product_pattern"], product, re.IGNORECASE):
        raise AdapterError("fixture_product_mismatch")

    return {
        "product": product,
        "software_version": version,
        "release": profile["release"],
        "patch": patch or "unknown",
        "uptime": uptime or "unknown",
    }


def parse_boards(text: str) -> list[dict[str, str]]:
    boards = []
    for raw_line in text.splitlines():
        match = BOARD_LINE.match(raw_line)
        if match:
            boards.append(
                {
                    "slot": match.group("slot"),
                    "board": match.group("board").upper(),
                    "status": match.group("status"),
                }
            )
    return boards


def parse_alarms(text: str) -> list[dict[str, str]]:
    alarms = []
    for raw_line in text.splitlines():
        match = ALARM_LINE.match(raw_line)
        if match:
            alarm = {
                "id": match.group("id").lower(),
                "severity": match.group("severity").lower(),
                "raised_at": match.group("raised").replace(" ", "T"),
                "summary": match.group("text"),
            }
            if match.group("sequence"):
                alarm["sequence"] = match.group("sequence")
            alarms.append(alarm)
    return alarms


def oid_alias(oid: str) -> tuple[str, str]:
    for key, scalar in SCALAR_OIDS.items():
        if oid == scalar:
            return key, ""
    for key, root in WALK_OIDS.items():
        if oid.startswith(root + "."):
            return key, oid[len(root) + 1 :]
    raise AdapterError("fixture_oid_not_allowlisted")


def parse_snmp_fixture(values: dict[str, Any]) -> dict[str, Any]:
    scalars: dict[str, Any] = {}
    entities: dict[str, dict[str, Any]] = {}
    interfaces: dict[str, dict[str, Any]] = {}

    for supplied_oid in sorted(values, key=lambda value: tuple(int(part) for part in canonical_oid(value).split("."))):
        oid = validate_oid(supplied_oid, "fixture", fixture_instance=True)
        alias, index = oid_alias(oid)
        if not index:
            scalars[alias] = values[supplied_oid]
        elif alias.startswith("ent_physical_"):
            entities.setdefault(index, {"index": index})[alias.removeprefix("ent_physical_")] = values[supplied_oid]
        elif alias.startswith("if_"):
            interfaces.setdefault(index, {"index": index})[alias.removeprefix("if_")] = values[supplied_oid]

    return {
        "system": scalars,
        "entities": [entities[index] for index in sorted(entities, key=lambda value: tuple(map(int, value.split("."))))],
        "interfaces": [interfaces[index] for index in sorted(interfaces, key=lambda value: tuple(map(int, value.split("."))))],
    }


def parse_fixture(profile: dict[str, Any], fixture_path: Path) -> dict[str, Any]:
    try:
        fixture = json.loads(fixture_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise AdapterError("fixture_unreadable_or_invalid_json") from exc

    if not isinstance(fixture, dict):
        raise AdapterError("fixture_root_must_be_object")
    assert_fixture_has_no_credentials(fixture)

    fixture_family = fixture.get("family", "")
    fixture_release = fixture.get("release", "")
    if canonical_family(str(fixture_family)) != profile["family"] or str(fixture_release).upper() != profile["release"]:
        raise AdapterError("fixture_profile_mismatch")

    cli = fixture.get("cli", {})
    snmp = fixture.get("snmp", {})
    if not isinstance(cli, dict) or not isinstance(snmp, dict):
        raise AdapterError("fixture_cli_and_snmp_must_be_objects")

    normalized_cli: dict[str, str] = {}
    for supplied_command, output in cli.items():
        command = validate_command(profile, str(supplied_command))
        if not isinstance(output, str):
            raise AdapterError("fixture_cli_output_must_be_text")
        normalized_cli[normalize_command(command)] = output

    version_output = normalized_cli.get(normalize_command(profile["commands"]["version"]))
    if version_output is None:
        raise AdapterError("fixture_missing_display_version")

    board_output = normalized_cli.get(normalize_command(profile["commands"]["boards"]), "")
    alarm_output = normalized_cli.get(normalize_command(profile["commands"]["active_alarms"]), "")
    parsed_snmp = parse_snmp_fixture(snmp)

    return {
        "ok": True,
        "schema_version": SCHEMA_VERSION,
        "adapter": "huawei_readonly_discovery",
        "mode": "mock_fixture",
        "transport": "none",
        "family": profile["family"],
        "release": profile["release"],
        "device_status": parse_version(profile, version_output),
        "board_inventory": parse_boards(board_output),
        "alarm_events": parse_alarms(alarm_output),
        "snmp_discovery": parsed_snmp,
        "unsupported": {
            "pon_ports": "pending_real_hardware_validation",
            "ont_identity": "pending_real_hardware_validation",
            "ont_state": "pending_real_hardware_validation",
            "optical_metrics": "pending_real_hardware_validation",
            "huawei_private_mibs": "matching_vendor_mib_package_not_validated",
        },
    }


def catalog() -> dict[str, Any]:
    return {
        "ok": True,
        "schema_version": SCHEMA_VERSION,
        "adapter": "huawei_readonly_discovery",
        "feature_flag": FEATURE_FLAG,
        "transport": "none",
        "profiles": [
            {
                "family": profile["family"],
                "release": profile["release"],
                "hardware_validated": profile["hardware_validated"],
                "source_catalog": CATALOG_FILES[(profile["family"], profile["release"])],
            }
            for profile in PROFILES.values()
        ],
    }


def output(payload: dict[str, Any], code: int = 0) -> int:
    print(json.dumps(payload, ensure_ascii=True, sort_keys=True, indent=2))
    return code


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="action", required=True)

    subparsers.add_parser("catalog")

    plan = subparsers.add_parser("plan")
    plan.add_argument("--family", required=True)
    plan.add_argument("--release", required=True)
    plan.add_argument("--mock", action="store_true", help="Build a transport-free mock plan")

    fixture = subparsers.add_parser("parse-fixture")
    fixture.add_argument("--family", required=True)
    fixture.add_argument("--release", required=True)
    fixture.add_argument("--fixture", required=True, type=Path)

    command = subparsers.add_parser("validate-command")
    command.add_argument("--family", required=True)
    command.add_argument("--release", required=True)
    command.add_argument("--command", required=True)

    oid = subparsers.add_parser("validate-oid")
    oid.add_argument("--oid", required=True)
    oid.add_argument("--operation", required=True, choices=("get", "walk", "set"))

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        if args.action == "catalog":
            return output(catalog())
        if args.action == "validate-oid":
            return output({"ok": True, "oid": validate_oid(args.oid, args.operation), "operation": args.operation})

        profile = profile_for(args.family, args.release)
        if args.action == "plan":
            return output(build_plan(profile, mock=args.mock))
        if args.action == "parse-fixture":
            return output(parse_fixture(profile, args.fixture))
        if args.action == "validate-command":
            return output({"ok": True, "command": validate_command(profile, args.command)})
        raise AdapterError("unsupported_action")
    except AdapterError as exc:
        return output({"ok": False, "error": str(exc)}, 3)


if __name__ == "__main__":
    sys.exit(main())
