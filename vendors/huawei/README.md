# Huawei read-only vendor catalogs

This directory stores source-backed discovery facts for the exact OLT releases
supported by the adapter scaffold. Catalog data does not enable a transport.

- `ma5608t-v800r019c10.json` maps Huawei's MA5608T release reference and
  official troubleshooting commands.
- `ma5800-x7-v100r024c10.json` maps Huawei's MA5800 release reference and the
  same officially documented OLT/ONT diagnostic command families.
- `catalog.schema.json` enforces the boundary between executable mock commands,
  source-confirmed candidates, and standard SNMP objects.

Private Huawei OIDs are intentionally absent. They can be added only after the
matching release MIB package is obtained from Huawei, its SHA-256 checksums are
recorded, and each object is verified against the real OLT release and board.
The Huawei enterprise root must never be bulk-walked.

`hardware_validated` remains `false` until sanitized command and SNMP fixtures
are captured from each real OLT. No catalog entry contains credentials.
