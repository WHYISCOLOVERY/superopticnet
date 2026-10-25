#!/usr/bin/env python3
"""Read Cisco IOS dynamic MAC table and emit only structured inventory."""
import argparse, json, re, sys, time, warnings
warnings.filterwarnings('ignore')
try:
 import paramiko
except Exception as exc:
 print(json.dumps({'ok':False,'error':'paramiko_import_failed'})); raise SystemExit(2)
def read(ch, initial=1.0, loops=32):
 time.sleep(initial); chunks=[]
 for _ in range(loops):
  if ch.recv_ready(): chunks.append(ch.recv(65535).decode('utf8','ignore')); time.sleep(.12)
  elif chunks: break
  else: time.sleep(.12)
 return ''.join(chunks)
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--payload-file',required=True);args=ap.parse_args()
 p=json.load(open(args.payload_file,encoding='utf8'));t=None;ch=None
 try:
  t=paramiko.Transport((p['host'],int(p.get('port',22))));opts=t.get_security_options()
  try: opts.kex=['diffie-hellman-group14-sha1','diffie-hellman-group1-sha1','diffie-hellman-group-exchange-sha1']
  except Exception: pass
  try: opts.key_types=['ssh-rsa','rsa-sha2-256','rsa-sha2-512']
  except Exception: pass
  t.banner_timeout=max(3,float(p.get('timeout_ms',9000))/1000);t.connect(username=p['username'],password=p['password'])
  ch=t.open_session();ch.get_pty(width=220,height=100);ch.invoke_shell();welcome=read(ch,1.0,20)
  ch.send('terminal length 0\r');read(ch,.35,12)
  ch.send('show mac address-table dynamic\r');out=read(ch,1.5,50)
  rows=[]
  for line in out.replace('\r','').split('\n'):
   # VLAN MAC TYPE PORT: e.g. 10 0011.2233.4455 DYNAMIC Fa0/1
   m=re.match(r'^\s*(\d+)\s+([0-9a-fA-F]{4}\.[0-9a-fA-F]{4}\.[0-9a-fA-F]{4})\s+\S+\s+(\S+)\s*$',line)
   if not m: continue
   mac=':'.join([m.group(2)[i:i+2].lower() for i in range(0,12,2) if m.group(2)[i]!='.'])
   mac=m.group(2).replace('.', '').lower();mac=':'.join(mac[i:i+2] for i in range(0,12,2))
   rows.append({'vlan_id':int(m.group(1)),'mac_address':mac,'port':m.group(3)})
  print(json.dumps({'ok':True,'rows':rows,'count':len(rows)}));return 0
 except Exception as exc:
  print(json.dumps({'ok':False,'error':exc.__class__.__name__}));return 1
 finally:
  try:
   if ch: ch.close()
   if t: t.close()
  except Exception: pass
if __name__=='__main__': raise SystemExit(main())
