#!/usr/bin/env node
import { mkdir, writeFile, appendFile } from 'node:fs/promises';
import path from 'node:path';

const args = new Map();
for (const arg of process.argv.slice(2)) {
  if (!arg.startsWith('--')) continue;
  const [key, ...rest] = arg.slice(2).split('=');
  args.set(key, rest.length ? rest.join('=') : '1');
}

const email = args.get('email') || process.env.LEGACY_BILLING_EMAIL || '';
const password = args.get('password') || process.env.LEGACY_BILLING_PASSWORD || '';
const outDir = path.resolve(args.get('out') || 'data/legacy-billing');
const pageSize = Math.max(50, Math.min(6000, Number(args.get('page-size') || 1000)));
const limit = Math.max(0, Number(args.get('limit') || 0));
const includeProfiles = args.has('profiles');
const profileOffset = Math.max(0, Number(args.get('profile-offset') || 0));
const profileLimit = Math.max(0, Number(args.get('profile-limit') || 0));
const appendProfiles = args.has('append-profiles');
const delayMs = Math.max(0, Math.min(5000, Number(args.get('delay-ms') || 150)));
const baseUrl = 'https://billing.superoptic.net';
const backendUrl = `${baseUrl}/backend`;

if (args.has('help') || !email || !password) {
  console.log([
    'Usage:',
    '  node tools/export-legacy-billing.mjs --email=user --password=pass [--profiles] [--limit=100] [--out=data/legacy-billing]',
    '  node tools/export-legacy-billing.mjs --email=user --password=pass --profiles --profile-offset=1000 --profile-limit=1000 --append-profiles',
    '',
    'Outputs:',
    '  customers-base.json',
    '  customers-profiles.jsonl when --profiles is set',
    '  export-summary.json',
    '',
    'Notes:',
    '  Exported files contain personal/customer data. data/legacy-billing is gitignored.',
  ].join('\n'));
  process.exit(email && password ? 0 : 1);
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function decodeHtml(input = '') {
  return String(input)
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#039;|&apos;/gi, "'")
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)))
    .replace(/&#x([0-9a-f]+);/gi, (_, code) => String.fromCharCode(parseInt(code, 16)));
}

function stripHtml(input = '') {
  return decodeHtml(String(input)
    .replace(/<script[\s\S]*?<\/script>/gi, ' ')
    .replace(/<style[\s\S]*?<\/style>/gi, ' ')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|li|tr|td|th|h1|h2|h3|h4|h5|h6)>/gi, '\n')
    .replace(/<[^>]+>/g, ' ')
  ).replace(/[ \t]+/g, ' ').replace(/\n\s+/g, '\n').trim();
}

function clean(input = '') {
  return stripHtml(input).replace(/\s+/g, ' ').trim();
}

function firstMatch(input, regex) {
  const match = String(input).match(regex);
  return match ? decodeHtml(match[1]).trim() : '';
}

function parseMoney(input = '') {
  const match = clean(input).replace(',', '.').match(/-?\d+(?:\.\d+)?/);
  return match ? Number(match[0]) : 0;
}

function parseCustomerRow(row) {
  const code = clean(row.id || '').replace(/[^\w-]/g, '');
  const profileHref = firstMatch(row.ad || '', /href=['"]([^'"]+)['"]/i);
  const statusText = clean(row.status || '');
  return {
    subscriber_code: code,
    profile_href: profileHref,
    full_name: clean(row.ad || ''),
    fin_serial: clean(row.fin || ''),
    address: clean(row.unvan || ''),
    phone: clean(row.telefon || ''),
    balance_text: clean(row.balans || ''),
    balance_amount: parseMoney(row.balans || ''),
    tariff: clean(row.paket || ''),
    days_text: clean(row.day || ''),
    debt_text: clean(row.borc || ''),
    status_text: statusText,
    raw: row,
  };
}

function extractTables(html) {
  const tables = [];
  const tableMatches = String(html).matchAll(/<table[\s\S]*?<\/table>/gi);
  let index = 0;
  for (const match of tableMatches) {
    const tableHtml = match[0];
    const rows = [];
    for (const rowMatch of tableHtml.matchAll(/<tr[\s\S]*?<\/tr>/gi)) {
      const cells = [];
      for (const cellMatch of rowMatch[0].matchAll(/<t[dh][^>]*>([\s\S]*?)<\/t[dh]>/gi)) {
        cells.push(clean(cellMatch[1]));
      }
      if (cells.some(Boolean)) rows.push(cells);
    }
    if (rows.length) {
      tables.push({
        index,
        headers: rows[0],
        rows: rows.slice(1),
      });
    }
    index++;
  }
  return tables;
}

function extractProfile(html, subscriberCode) {
  const text = stripHtml(html);
  const lines = text.split('\n').map(line => line.trim()).filter(Boolean);
  const profile = {
    subscriber_code: subscriberCode,
    balance: firstMatch(text, /Balans\s+([^\n]+)/i),
    remaining_days: firstMatch(text, /Qal[ıi]q g[üu]n\s+([^\n]+)/i),
    upload: firstMatch(text, /Upload\s+([^\n]+)/i),
    download: firstMatch(text, /Download\s+([^\n]+)/i),
    billing_info: {},
    phone_block: '',
    address_block: '',
    note_block: '',
    tables: extractTables(html),
    text_lines: lines.slice(0, 600),
  };

  const labelMap = {
    'İstifadəçi kodu': 'subscriber_code',
    'Sifre': 'password',
    'Şifrə': 'password',
    'Şəxsi panel şifrəsi': 'portal_password',
    'Mac': 'mac',
    'Vlan': 'vlan',
    'Ip': 'ip',
    'IP': 'ip',
    'NAS': 'nas',
    'Sessiya başlanğıcı': 'session_started_at',
    'Sessiya sayı': 'session_count',
    'Xidmət': 'service_type',
    'Paket': 'tariff',
    'Yaradılma tarixi': 'created_at',
  };

  for (const [label, key] of Object.entries(labelMap)) {
    const value = firstMatch(text, new RegExp(`${label}\\s*:?\\s*([^\\n]+)`, 'i'));
    if (value) profile.billing_info[key] = value;
  }

  profile.phone_block = firstMatch(text, /Telefon n[öo]mr[əe]si\s+([^\n]+)/i);
  profile.address_block = firstMatch(text, /Ünvan\s+([^\n]+)/i);
  profile.note_block = firstMatch(text, /Qeyd\s+([\s\S]{0,800}?)(?:Users Cədvəli|Ödəniş tarixçəsi|Bağlantı Tarixcəsi|$)/i).replace(/\s+/g, ' ').trim();

  return profile;
}

async function request(url, options = {}) {
  const response = await fetch(url, options);
  const text = await response.text();
  return { response, text };
}

async function login() {
  const first = await request(`${baseUrl}/`);
  const phpSession = (first.response.headers.get('set-cookie') || '').match(/PHPSESSID=[^;]+/)?.[0] || '';
  const csrf = first.text.match(/name="csrf_token" value="([^"]+)"/)?.[1] || '';
  if (!phpSession || !csrf) {
    throw new Error('Legacy login CSRF/session not found');
  }

  const body = new URLSearchParams({ csrf_token: csrf, email, pass: password });
  const loginResponse = await request(`${baseUrl}/core/main/funksiya.php`, {
    method: 'POST',
    headers: {
      'content-type': 'application/x-www-form-urlencoded; charset=UTF-8',
      cookie: phpSession,
      'x-requested-with': 'XMLHttpRequest',
    },
    body,
    redirect: 'manual',
  });

  let loginJson = null;
  try {
    loginJson = JSON.parse(loginResponse.text);
  } catch {
    throw new Error(`Legacy login did not return JSON: ${loginResponse.text.slice(0, 120)}`);
  }
  if (loginJson.status !== 'success') {
    throw new Error(`Legacy login failed: ${loginResponse.text.slice(0, 200)}`);
  }

  const loginCookies = loginResponse.response.headers.get('set-cookie') || '';
  const extraCookies = Array.from(loginCookies.matchAll(/(?:session_log|session_open_ip|PHPSESSID)=[^;,]+/g)).map(match => match[0]);
  return [phpSession, ...extraCookies].filter(Boolean).join('; ');
}

function tableParams(start, length) {
  const params = new URLSearchParams();
  params.set('draw', String(Math.floor(start / length) + 1));
  params.set('start', String(start));
  params.set('length', String(length));
  ['id', 'ad', 'fin', 'unvan', 'telefon', 'balans', 'paket', 'day', 'borc', 'status'].forEach((column, index) => {
    params.set(`columns[${index}][data]`, column);
    params.set(`columns[${index}][name]`, '');
    params.set(`columns[${index}][searchable]`, 'true');
    params.set(`columns[${index}][orderable]`, 'false');
    params.set(`columns[${index}][search][value]`, '');
    params.set(`columns[${index}][search][regex]`, 'false');
  });
  for (const key of ['router_open_id', 'user_status', 'internet_status', 'internet_paket', 'personal_search', 'personal_a_search', 'personal_ip_search', 'user_borc', 'zone_filter', 'static_ip_filter']) {
    params.set(key, '');
  }
  return params;
}

async function fetchCustomers(cookie) {
  const customers = [];
  let total = null;
  for (let start = 0; total === null || start < total; start += pageSize) {
    const body = tableParams(start, pageSize);
    const table = await request(`${backendUrl}/table/ppp_user.php`, {
      method: 'POST',
      headers: {
        'content-type': 'application/x-www-form-urlencoded; charset=UTF-8',
        cookie,
        'x-requested-with': 'XMLHttpRequest',
      },
      body,
    });
    let json = null;
    try {
      json = JSON.parse(table.text);
    } catch {
      throw new Error(`Customer table JSON parse failed at start=${start}: ${table.text.slice(0, 200)}`);
    }
    total = Number(json.recordsTotal || json.iTotalRecords || json.iTotalDisplayRecords || 0);
    const rows = Array.isArray(json.data) ? json.data : (Array.isArray(json.aaData) ? json.aaData : []);
    customers.push(...rows.map(parseCustomerRow));
    console.error(`Fetched customers ${customers.length}/${total || '?'}`);
    if (limit > 0 && customers.length >= limit) {
      return customers.slice(0, limit);
    }
    if (rows.length === 0) break;
  }
  return customers;
}

async function fetchProfile(cookie, customer) {
  const href = customer.profile_href || `ppp_user_profile.php?id=${encodeURIComponent(customer.subscriber_code)}`;
  const url = href.startsWith('http') ? href : `${backendUrl}/${href.replace(/^\/+/, '')}`;
  const profile = await request(url, { headers: { cookie } });
  return extractProfile(profile.text, customer.subscriber_code);
}

await mkdir(outDir, { recursive: true });
const cookie = await login();
const customers = await fetchCustomers(cookie);
const basePath = path.join(outDir, 'customers-base.json');
await writeFile(basePath, JSON.stringify({
  exported_at: new Date().toISOString(),
  count: customers.length,
  source: `${backendUrl}/table/ppp_user.php`,
  customers,
}, null, 2), 'utf8');

let profilesCount = 0;
let profileErrors = 0;
const profilePath = path.join(outDir, 'customers-profiles.jsonl');
if (includeProfiles) {
  if (!appendProfiles) {
    await writeFile(profilePath, '', 'utf8');
  }
  const profileEnd = profileLimit > 0 ? profileOffset + profileLimit : undefined;
  const profileCustomers = customers.slice(profileOffset, profileEnd);
  for (const customer of profileCustomers) {
    try {
      const profile = await fetchProfile(cookie, customer);
      await appendFile(profilePath, JSON.stringify({ customer, profile }) + '\n', 'utf8');
      profilesCount++;
      if (profilesCount % 25 === 0) {
        console.error(`Fetched profiles ${profilesCount}/${profileCustomers.length} (offset ${profileOffset})`);
      }
    } catch (error) {
      profileErrors++;
      await appendFile(profilePath, JSON.stringify({ customer, error: String(error?.message || error) }) + '\n', 'utf8');
    }
    if (delayMs > 0) await sleep(delayMs);
  }
}

const summary = {
  exported_at: new Date().toISOString(),
  customers: customers.length,
  profiles: profilesCount,
  profile_offset: includeProfiles ? profileOffset : null,
  profile_limit: includeProfiles ? profileLimit : null,
  profile_errors: profileErrors,
  out_dir: outDir,
  base_file: basePath,
  profiles_file: includeProfiles ? profilePath : null,
};
await writeFile(path.join(outDir, 'export-summary.json'), JSON.stringify(summary, null, 2), 'utf8');
console.log(JSON.stringify(summary, null, 2));
