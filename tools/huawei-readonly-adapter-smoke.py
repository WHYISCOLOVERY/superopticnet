#!/usr/bin/env python3
"""Deterministic mock-only tests for huawei-readonly-adapter.py."""

from __future__ import annotations

import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest import mock


ADAPTER_PATH = Path(__file__).with_name("huawei-readonly-adapter.py")
PROJECT_ROOT = ADAPTER_PATH.parent.parent
SPEC = importlib.util.spec_from_file_location("huawei_readonly_adapter", ADAPTER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("adapter_import_failed")
ADAPTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ADAPTER)


def fixture_for(family: str) -> dict:
    if family == "MA5608T":
        return {
            "family": "MA5608T",
            "release": "V800R019C10",
            "cli": {
                "display version": (
                    "VERSION : MA5600V800R019C10\n"
                    "PATCH   : SPC100\n"
                    "PRODUCT : MA5608T\n"
                    "MA5608T uptime is 12 day, 3 hour, 4 minute, 5 second\n"
                ),
                "display board 0": (
                    "SlotID BoardName Status\n"
                    "0 H801MABO Active_normal\n"
                    "1 H805GPFD Normal\n"
                ),
                "display alarm active": (
                    "1 0x2e112007 Major 2026-07-15 10:20:30 GPON ONT signal loss\n"
                ),
            },
            "snmp": {
                "1.3.6.1.2.1.1.1.0": "Huawei SmartAX MA5608T",
                "1.3.6.1.2.1.1.2.0": "1.3.6.1.4.1.2011.2.300",
                "1.3.6.1.2.1.1.3.0": 10622500,
                "1.3.6.1.2.1.1.5.0": "lab-ma5608t",
                "1.3.6.1.2.1.47.1.1.1.1.2.1": "Main control board",
                "1.3.6.1.2.1.47.1.1.1.1.7.1": "H801MABO",
                "1.3.6.1.2.1.47.1.1.1.1.13.1": "H801MABO",
                "1.3.6.1.2.1.31.1.1.1.1.101": "gpon-0/1/0",
                "1.3.6.1.2.1.2.2.1.8.101": 1,
            },
        }
    return {
        "family": "MA5800-X7",
        "release": "V100R024C10",
        "cli": {
            "display version": (
                "VERSION : MA5800V100R024C10\n"
                "PATCH   : SPC200\n"
                "PRODUCT : MA5800-X7\n"
                "MA5800-X7 uptime is 8 day, 1 hour, 2 minute, 3 second\n"
            ),
            "display board 0": (
                "SlotID BoardName Status\n"
                "0/8 H901MPLA Active_normal\n"
                "0/0 H901GPHF Normal\n"
            ),
            "display alarm active": "",
        },
        "snmp": {
            "1.3.6.1.2.1.1.1.0": "Huawei SmartAX MA5800-X7",
            "1.3.6.1.2.1.1.2.0": "1.3.6.1.4.1.2011.2.400",
            "1.3.6.1.2.1.1.3.0": 6955230,
            "1.3.6.1.2.1.1.5.0": "lab-ma5800-x7",
            "1.3.6.1.2.1.47.1.1.1.1.2.8": "Main control board",
            "1.3.6.1.2.1.47.1.1.1.1.7.8": "H901MPLA",
            "1.3.6.1.2.1.47.1.1.1.1.13.8": "H901MPLA",
            "1.3.6.1.2.1.31.1.1.1.1.201": "gpon-0/0/0",
            "1.3.6.1.2.1.2.2.1.8.201": 1,
        },
    }


class HuaweiReadonlyAdapterTests(unittest.TestCase):
    def setUp(self) -> None:
        self.ma5608 = ADAPTER.profile_for("MA5608T", "V800R019C10")
        self.ma5800 = ADAPTER.profile_for("MA5800-X7", "V100R024C10")

    def write_fixture(self, payload: dict) -> Path:
        handle = tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", suffix=".json", delete=False)
        self.addCleanup(lambda: Path(handle.name).unlink(missing_ok=True))
        with handle:
            json.dump(payload, handle, sort_keys=True)
        return Path(handle.name)

    def test_only_exact_profiles_are_supported(self) -> None:
        self.assertEqual(self.ma5608["release"], "V800R019C10")
        self.assertEqual(self.ma5800["release"], "V100R024C10")
        with self.assertRaisesRegex(ADAPTER.AdapterError, "unsupported_family_release"):
            ADAPTER.profile_for("MA5800-X7", "V100R024C00")

    def test_production_plan_is_disabled_by_default(self) -> None:
        with mock.patch.dict(os.environ, {}, clear=True):
            with self.assertRaisesRegex(ADAPTER.AdapterError, "feature_flag_disabled"):
                ADAPTER.build_plan(self.ma5608)

    def test_feature_flag_cannot_bypass_hardware_validation(self) -> None:
        with mock.patch.dict(os.environ, {ADAPTER.FEATURE_FLAG: "true"}, clear=True):
            with self.assertRaisesRegex(ADAPTER.AdapterError, "real_hardware_not_validated"):
                ADAPTER.build_plan(self.ma5800)

    def test_mock_plan_is_transport_free_and_read_only(self) -> None:
        plan = ADAPTER.build_plan(self.ma5608, mock=True)
        self.assertEqual(plan["mode"], "mock_plan")
        self.assertEqual(plan["transport"], "none")
        self.assertFalse(plan["hardware_validated"])
        self.assertTrue(all(item["command"].startswith("display ") for item in plan["cli"]))
        self.assertTrue(all(item["operation"] in {"get", "walk"} for item in plan["snmp"]))
        self.assertEqual(plan["source_catalog"], "vendors/huawei/ma5608t-v800r019c10.json")

    def test_source_catalogs_keep_unverified_commands_out_of_execution(self) -> None:
        for profile in (self.ma5608, self.ma5800):
            relative_path = ADAPTER.CATALOG_FILES[(profile["family"], profile["release"])]
            catalog = json.loads((PROJECT_ROOT / relative_path).read_text(encoding="utf-8"))
            self.assertEqual(catalog["family"], profile["family"])
            self.assertEqual(catalog["release"], profile["release"])
            self.assertFalse(catalog["hardware_validated"])
            self.assertTrue(all(source["publisher"] == "Huawei" for source in catalog["sources"]))
            self.assertTrue(
                all("support.huawei.com/" in source["url"] for source in catalog["sources"])
            )

            executable = {
                item["command_family"]
                for item in catalog["cli"]
                if item["executable"]
            }
            self.assertEqual(executable, set(profile["commands"].values()))
            self.assertTrue(
                all(
                    not item["executable"]
                    for item in catalog["cli"]
                    if item["status"] == "source_confirmed_syntax_unverified"
                )
            )
            self.assertTrue(
                all(not item["oid"].startswith("1.3.6.1.4.1.2011") for item in catalog["snmp"])
            )
            self.assertFalse(catalog["private_mib_policy"]["bulk_walk_allowed"])

    def test_cli_allowlist_rejects_writes_context_and_chaining(self) -> None:
        self.assertEqual(ADAPTER.validate_command(self.ma5608, " DISPLAY   VERSION "), "display version")
        rejected = [
            "config",
            "interface gpon 0/1",
            "ont reset 0 1",
            "board reset 0/1",
            "save",
            "undo service-port 1",
            "display current-configuration",
            "display version; save",
            "display version | include VERSION",
            "display ont info 0/1/0 all",
        ]
        for command in rejected:
            with self.subTest(command=command):
                with self.assertRaises(ADAPTER.AdapterError):
                    ADAPTER.validate_command(self.ma5608, command)

    def test_snmp_allowlist_rejects_set_and_private_huawei_oids(self) -> None:
        self.assertEqual(
            ADAPTER.validate_oid(".1.3.6.1.2.1.1.1.0", "get"),
            "1.3.6.1.2.1.1.1.0",
        )
        with self.assertRaisesRegex(ADAPTER.AdapterError, "oid_or_operation_not_allowlisted"):
            ADAPTER.validate_oid("1.3.6.1.2.1.1.5.0", "set")
        with self.assertRaisesRegex(ADAPTER.AdapterError, "oid_or_operation_not_allowlisted"):
            ADAPTER.validate_oid("1.3.6.1.4.1.2011.6.128", "walk")

    def test_ma5608_fixture_parses_without_transport(self) -> None:
        result = ADAPTER.parse_fixture(self.ma5608, self.write_fixture(fixture_for("MA5608T")))
        self.assertEqual(result["mode"], "mock_fixture")
        self.assertEqual(result["device_status"]["software_version"], "MA5600V800R019C10")
        self.assertEqual([row["board"] for row in result["board_inventory"]], ["H801MABO", "H805GPFD"])
        self.assertEqual(result["alarm_events"][0]["id"], "0x2e112007")
        self.assertEqual(result["alarm_events"][0]["sequence"], "1")
        self.assertEqual(result["snmp_discovery"]["interfaces"][0]["name"], "gpon-0/1/0")

    def test_ma5800_fixture_accepts_frame_slot_board_shape(self) -> None:
        result = ADAPTER.parse_fixture(self.ma5800, self.write_fixture(fixture_for("MA5800-X7")))
        self.assertEqual(result["device_status"]["software_version"], "MA5800V100R024C10")
        self.assertEqual(result["board_inventory"][0]["slot"], "0/8")
        self.assertEqual(result["snmp_discovery"]["entities"][0]["model_name"], "H901MPLA")

    def test_fixture_rejects_wrong_release_banner(self) -> None:
        fixture = fixture_for("MA5800-X7")
        fixture["cli"]["display version"] = fixture["cli"]["display version"].replace(
            "V100R024C10", "V100R024C00"
        )
        with self.assertRaisesRegex(ADAPTER.AdapterError, "fixture_version_mismatch"):
            ADAPTER.parse_fixture(self.ma5800, self.write_fixture(fixture))

        fixture = fixture_for("MA5608T")
        fixture["cli"]["display version"] = fixture["cli"]["display version"].replace(
            "V800R019C10", "V800R019C100"
        )
        with self.assertRaisesRegex(ADAPTER.AdapterError, "fixture_version_mismatch"):
            ADAPTER.parse_fixture(self.ma5608, self.write_fixture(fixture))

    def test_fixture_rejects_credentials_and_unknown_commands(self) -> None:
        credential_fixture = fixture_for("MA5608T")
        credential_fixture["password"] = "not-a-real-password"
        with self.assertRaisesRegex(ADAPTER.AdapterError, "fixture_contains_credential_field"):
            ADAPTER.parse_fixture(self.ma5608, self.write_fixture(credential_fixture))

        credential_text_fixture = fixture_for("MA5608T")
        credential_text_fixture["cli"]["display version"] += "community = placeholder\n"
        with self.assertRaisesRegex(ADAPTER.AdapterError, "fixture_contains_credential_text"):
            ADAPTER.parse_fixture(self.ma5608, self.write_fixture(credential_text_fixture))

        command_fixture = fixture_for("MA5608T")
        command_fixture["cli"]["display current-configuration"] = "redacted"
        with self.assertRaisesRegex(ADAPTER.AdapterError, "command_not_allowlisted"):
            ADAPTER.parse_fixture(self.ma5608, self.write_fixture(command_fixture))


if __name__ == "__main__":
    unittest.main(verbosity=2)
