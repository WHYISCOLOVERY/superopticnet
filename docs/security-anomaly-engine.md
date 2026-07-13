# SuperOptic Security Anomaly Engine

Bu modul şəbəkədə virus, botnet, DDoS iştirakı, router/ONT compromise və uplink-i dolduran müştəri davranışını əvvəlcədən aşkarlamaq üçündür.

## Prinsip

- Sistem əvvəlcə sübut toplayır, sonra risk balı hesablayır.
- Canlı bloklama avtomatik edilmir.
- CoA disconnect, rate-limit, quarantine VLAN/pool və ACL kimi addımlar Superpilot/NOC təsdiqi ilə icra olunmalıdır.

## Birinci mərhələ data mənbələri

- `radacct`: aktiv sessiya, traffic, NAS, IP, MAC.
- `radius_nat_logs`: source IP, NAT IP, destination IP/port fanout.
- `billing_radius_services`: username, assigned IP, static IP, customer link.
- `portal_accounts`: müştəri kodu, ad, telefon, zona.

## Aşkarlanan risklər

- `top_talker`: 24 saatda yüksək trafik.
- `upload_abuse`: upload ağırlıqlı anormal trafik.
- `session_storm`: eyni istifadəçidə çox sayda aktiv RADIUS sessiyası.
- `nat_fanout`: qısa müddətdə çox fərqli destination IP/port bağlantısı.

## Risk balı

- `0-54`: info, izləmə.
- `55-74`: warning, ticket və müştəri xəbərdarlığı.
- `75-89`: critical, NOC təsdiqi ilə RADIUS refresh/rate-limit təklif edilir.
- `90-100`: emergency, admin policy ilə quarantine təklif edilir.

## CLI

Detect-only:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\security-anomaly-scan.php
```

Test alarm/audit yazmaq:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\security-anomaly-scan.php --record
```

Live statuslu event yazmaq üçün yalnız təsdiqli NOC əməliyyatında:

```powershell
D:\xampp\php\php.exe D:\xampp\htdocs\superopticnet\tools\security-anomaly-scan.php --record --live
```

## Superpilot sualları

- `Şəbəkəni kim doldurur?`
- `Virus ehtimali olan müştəriləri göstər`
- `Top talker kimdir?`
- `NAT fanout və session storm varmı?`
- `Anormal upload edən müştəri varmı?`

## Növbəti mərhələ

- Core router-dən NetFlow/IPFIX export.
- Interface utilization SNMP counter baseline.
- DNS/SMTP/NTP/SSDP abuse pattern kartları.
- Policy engine: təsdiqli rate-limit və quarantine workflow.
