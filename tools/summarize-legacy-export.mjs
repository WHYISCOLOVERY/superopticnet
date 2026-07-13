#!/usr/bin/env node
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const outDir = path.resolve(process.argv[2] || 'data/legacy-billing');
const basePath = path.join(outDir, 'customers-base.json');
const profilePath = path.join(outDir, 'customers-profiles.jsonl');
const summaryPath = path.join(outDir, 'export-manifest.json');

const base = JSON.parse(await readFile(basePath, 'utf8'));
const lines = (await readFile(profilePath, 'utf8')).split(/\r?\n/).filter(Boolean);
let parseErrors = 0;
let profileErrors = 0;
let rowsWithNotes = 0;
let rowsWithTables = 0;
let rowsWithBillingInfo = 0;
const uniqueCodes = new Set();
const tableHeaderMap = new Map();

for (const [index, line] of lines.entries()) {
  try {
    const row = JSON.parse(line);
    const code = row?.customer?.subscriber_code || row?.profile?.subscriber_code || '';
    if (code) uniqueCodes.add(code);
    if (row?.error) profileErrors++;
    if (row?.profile?.note_block) rowsWithNotes++;
    if (row?.profile?.billing_info && Object.keys(row.profile.billing_info).length > 0) rowsWithBillingInfo++;
    if (Array.isArray(row?.profile?.tables) && row.profile.tables.length > 0) {
      rowsWithTables++;
      for (const table of row.profile.tables) {
        const key = (table.headers || []).join(' | ').slice(0, 220);
        if (key) tableHeaderMap.set(key, (tableHeaderMap.get(key) || 0) + 1);
      }
    }
  } catch {
    parseErrors++;
    if (parseErrors <= 3) {
      console.error(`Parse error at line ${index + 1}`);
    }
  }
}

const topTableShapes = Array.from(tableHeaderMap.entries())
  .sort((a, b) => b[1] - a[1])
  .slice(0, 20)
  .map(([headers, count]) => ({ headers, count }));

const manifest = {
  generated_at: new Date().toISOString(),
  source: 'https://billing.superoptic.net/backend',
  base_customers_declared: base.count,
  base_customers_rows: Array.isArray(base.customers) ? base.customers.length : 0,
  profile_lines: lines.length,
  profile_unique_codes: uniqueCodes.size,
  parse_errors: parseErrors,
  profile_errors: profileErrors,
  rows_with_profile_tables: rowsWithTables,
  rows_with_profile_billing_info: rowsWithBillingInfo,
  rows_with_note_block: rowsWithNotes,
  top_profile_table_shapes: topTableShapes,
  files: {
    base: basePath,
    profiles: profilePath,
  },
};

await writeFile(summaryPath, JSON.stringify(manifest, null, 2), 'utf8');
console.log(JSON.stringify(manifest, null, 2));
