---
type: "diagnostic"
date: "2026-09-10T16:08:12.335563+00:00"
question: "Core Cisco SNMP/IF-MIB monitoring niyə timeout verir və təhlükəsiz necə bərpa olunur?"
contributor: "graphify"
outcome: "useful"
source_nodes: ["app_mgs_device_access_profile", "app_device_credential_reveal", "tools/_cisco-snmp-config-read.py", "tools/_cisco-snmp-config-apply.py", "tools/repair-cisco-snmp-monitoring.php", "tools/reconcile-cisco-snmp-profile.php", "tools/cisco-health-read.py"]
---

# Q: Core Cisco SNMP/IF-MIB monitoring niyə timeout verir və təhlükəsiz necə bərpa olunur?

## Answer

Monitor mənbə IP-si skriptlərdə 172.16.5.116 kimi sabit idi, halbuki aktiv marşrut 172.16.5.122-dən çıxırdı. Mənbə IP-ni UDP route socket ilə dinamik tapdıq, routerin mövcud RO community/ACL metadatasını secretsiz oxuduq, ACL-də boş sequence 19-a cari poller IP-ni əlavə etdik, SNMP sysDescr və IF-MIB ilə təsdiqlədik, vault-u konfiqurasiya ilə uzlaşdırdıq və uğursuz yoxlama üçün rollback_snmp_source əlavə etdik. Sensor parseri RPM/aktiv status formatlarını və məhdud evidence-i anlayır.

## Outcome

- Signal: useful

## Source Nodes

- app_mgs_device_access_profile
- app_device_credential_reveal
- tools/_cisco-snmp-config-read.py
- tools/_cisco-snmp-config-apply.py
- tools/repair-cisco-snmp-monitoring.php
- tools/reconcile-cisco-snmp-profile.php
- tools/cisco-health-read.py