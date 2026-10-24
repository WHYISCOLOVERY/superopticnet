"""Fixed, bounded Cisco EXEC observations. No arbitrary command input or config mode."""
import re
import time

COMMANDS = {
    'uptime': 'show version | include uptime',
    'cpu': 'show processes cpu | include CPU utilization',
    'memory': 'show processes memory | include Processor Pool',
    'sessions': 'show pppoe session | count UP',
    'environment': 'show environment all | include FAN|Fan|fan|OK|Fail|fail|Critical|Warning',
    # Runtime counters do not reveal the configured community value. They let
    # us distinguish an ACL/community/version rejection from an SNMP process
    # that has never received the poller's packets.
    'snmp_runtime': 'show snmp',
    'interfaces': 'show interfaces | include line protocol|input rate|output rate|packets input|packets output|input errors|output errors|Total output drops',
}
PROMPT = re.compile(r'(?m)^[\w.():/-]+[>#]\s*$')


def read_until_prompt(chan, seconds=3.0):
    chunks = bytearray()
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline and len(chunks) < 65536:
        if chan.recv_ready():
            part = chan.recv(min(4096, 65536 - len(chunks)))
            if not part:
                break
            chunks.extend(part)
            text = chunks.decode('utf-8', errors='replace').replace('\r', '')
            if PROMPT.search(text) and text.rstrip().endswith(('>', '#')):
                return text, True
            if '--More--' in text:
                return text, False
        elif chan.closed:
            break
        else:
            time.sleep(.03)
    return chunks.decode('utf-8', errors='replace'), False


def parse(name, text):
    if re.search(r'%\s*(Invalid|Error|Incomplete|Ambiguous)|Authorization failed', text, re.I):
        return None
    if name == 'uptime':
        m = re.search(r'\buptime is ([\w ,.:/-]{1,160})', text)
        return {'uptime': m.group(1).strip()} if m else None
    if name == 'cpu':
        m = re.search(r'CPU utilization for five seconds:\s*(\d+)%/(\d+)%;\s*one minute:\s*(\d+)%;\s*five minutes:\s*(\d+)%', text)
        if m and all(0 <= int(v) <= 100 for v in m.groups()):
            return dict(zip(('cpu_5s_percent', 'interrupt_5s_percent', 'cpu_1m_percent', 'cpu_5m_percent'), map(int, m.groups())))
        return None
    if name == 'memory':
        m = re.search(r'Processor Pool Total:\s*(\d+)\s+Used:\s*(\d+)\s+Free:\s*(\d+)', text, re.I)
        if not m:
            return None
        total, used, free = map(int, m.groups())
        if total <= 0 or used < 0 or free < 0:
            return None
        return {
            'memory_total_bytes': total,
            'memory_used_bytes': used,
            'memory_free_bytes': free,
            'memory_used_percent': round((used / total) * 100, 1),
        }
    if name == 'sessions':
        patterns = (
            r'Total\s+PPPoE\s+sessions?\s*[:=]?\s*(\d+)',
            r'PPPoE\s+Session\s+Count\s*[:=]?\s*(\d+)',
            r'Total\s+number\s+of\s+sessions?\s*[:=]?\s*(\d+)',
            r'Sessions?\s+total\s*[:=]?\s*(\d+)',
            r'Number\s+of\s+lines\s+which\s+match\s+regexp\s*=\s*(\d+)',
        )
        for pattern in patterns:
            m = re.search(pattern, text, re.I)
            if m:
                return {'pppoe_active_sessions': int(m.group(1))}
        return None
    if name == 'environment':
        lines = [line.strip() for line in text.splitlines()
                 if not line.strip().lower().startswith('show environment')
                 and not PROMPT.fullmatch(line.strip())
                 and re.search(r'fan|temperature|temp|power|critical|warning|fail|\bok\b', line, re.I)]
        if not lines:
            return None
        joined = ' '.join(lines)
        healthy_sensor = bool(re.search(
            r'\b(?:ok|normal|good|active|operational|present|up)\b|\b[1-9]\d*\s*(?:rpm|%)\b',
            joined,
            re.I,
        ))
        status = ('critical' if re.search(r'fail(?:ed|ure)?|critical|shutdown|not\s+ok', joined, re.I)
                  else 'warning' if re.search(r'warning|alarm', joined, re.I)
                  else 'ok' if healthy_sensor
                  else 'unknown')
        fan_lines = [line for line in lines if re.search(r'fan', line, re.I)]
        fan_joined = ' '.join(fan_lines)
        healthy_fan = bool(re.search(
            r'\b(?:ok|normal|good|active|operational|present|up)\b|\b[1-9]\d*\s*(?:rpm|%)\b',
            fan_joined,
            re.I,
        ))
        fan_status = ('not_observed' if not fan_lines else
                      'critical' if re.search(r'fail(?:ed|ure)?|critical|not\s+ok', fan_joined, re.I) else
                      'warning' if re.search(r'warning|alarm', fan_joined, re.I) else
                      'ok' if healthy_fan else 'unknown')
        # Bounded, secret-free sensor evidence helps the NOC explain an
        # unknown result without exposing prompts or arbitrary CLI output.
        evidence = [re.sub(r'\s+', ' ', line)[:180] for line in lines[:12]]
        return {
            'environment_status': status,
            'fan_status': fan_status,
            'environment_evidence': evidence,
        }
    if name == 'snmp_runtime':
        patterns = {
            'packets_input': r'(\d+)\s+SNMP packets input',
            'packets_output': r'(\d+)\s+SNMP packets output',
            'bad_version_errors': r'(\d+)\s+Bad SNMP version errors',
            'unknown_community_errors': r'(\d+)\s+Unknown community name',
            'illegal_community_operations': r'(\d+)\s+Illegal operation for community name',
        }
        values = {}
        for key, pattern in patterns.items():
            match = re.search(pattern, text, re.I)
            if match:
                values[key] = int(match.group(1))
        return {'snmp_runtime': values} if values else None
    rows, current = [], None
    for line in text.splitlines():
        m = re.match(r'^([A-Za-z][\w./:-]{0,79}) is (.+), line protocol is (\w+)', line)
        if m:
            current = {'name': m.group(1), 'link_state': m.group(2)[:40], 'protocol_state': m.group(3)}
            rows.append(current)
        elif current is not None:
            rate = re.search(r'(\d+) (second|minute) (input|output) rate (\d+) bits/sec', line)
            if rate:
                current[rate.group(3) + '_bps'] = int(rate.group(4))
                current['rate_window_seconds'] = int(rate.group(1)) * (60 if rate.group(2) == 'minute' else 1)
            for direction in ('input', 'output'):
                error = re.search(r'(\d+) ' + direction + r' errors', line)
                if error:
                    current[direction + '_errors_total'] = int(error.group(1))
            packets = re.search(r'(\d+) packets (input|output)', line)
            if packets:
                current[packets.group(2) + '_packets_total'] = int(packets.group(1))
            packet_rate = re.search(r'(input|output) rate \d+ bits/sec,\s*(\d+) packets/sec', line)
            if packet_rate:
                current[packet_rate.group(1) + '_pps'] = int(packet_rate.group(2))
            drops = re.search(r'Total output drops:\s*(\d+)', line, re.I)
            if drops:
                current['output_drops_total'] = int(drops.group(1))
    if not rows:
        return None
    observed = rows[:256]
    # Keep model/UI context bounded. Prefer physical links with traffic, then the
    # busiest remaining interfaces. Counts still describe the observed sample.
    ranked = sorted(
        observed,
        key=lambda row: (
            not row['name'].startswith('Virtual-Access'),
            row.get('input_bps', 0) + row.get('output_bps', 0),
        ),
        reverse=True,
    )
    totals = {}
    for key in ('input_packets_total', 'output_packets_total', 'input_errors_total',
                'output_errors_total', 'output_drops_total'):
        values = [int(row[key]) for row in observed if key in row]
        if values:
            totals[key] = sum(values)
    return {
        'interface_rows_observed': len(observed),
        'link_up_count': sum(1 for row in observed if row.get('protocol_state') == 'up'),
        'admin_down_count': sum(1 for row in observed if row.get('link_state') == 'administratively down'),
        'interfaces': ranked[:12],
        'interface_counter_totals': totals,
        'interface_counter_scope': 'observed_cli_rows',
        'truncated': len(rows) > len(observed) or len(observed) > 12,
    }


def collect(client):
    result = {'profile': 'cisco_health', 'commands': {}, 'observations': {}}
    chan = client.invoke_shell(width=512, height=1000)
    try:
        _, ready = read_until_prompt(chan)
        if not ready:
            return {**result, 'error': 'initial_prompt_not_received'}
        for name, command in COMMANDS.items():
            chan.sendall(command + '\n')
            text, complete = read_until_prompt(chan, 5.0 if name in ('interfaces', 'snmp_runtime', 'environment') else 3.0)
            value = parse(name, text) if complete or name == 'interfaces' else None
            result['commands'][name] = ('observed' if complete else 'partial') if value is not None else ('unsupported_or_unparsed' if complete else 'timeout_or_truncated')
            if value is not None:
                if not complete:
                    value['truncated'] = True
                result['observations'].update(value)
            if not complete:
                break  # Never send another command into a pager or unfinished response.
        return result
    finally:
        chan.close()
