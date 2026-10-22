---
type: "implementation"
date: "2026-09-09T23:59:13.932649+00:00"
question: "ONT TX RX və temperatur üçün birbaşa müqayisəli canlı fleet endpointi və Cisco health ölçüləri"
contributor: "graphify"
outcome: "useful"
source_nodes: ["app_mgs_hsgq_live_ont_inventory", "app_mgs_device_access_profile", "ont_analysis_matches", "collect", "parse"]
---

# Q: ONT TX RX və temperatur üçün birbaşa müqayisəli canlı fleet endpointi və Cisco health ölçüləri

## Answer

ONT endpointi ONT TX, ONT RX və temperaturu gt/gte/lt/lte qaydaları ilə birbaşa filtr edir, OLT/PON/ONT/serial sübutu qaytarır. Cisco health helper RAM, PPPoE session və sanitizasiya edilmiş fan statusu toplayır; xam credential və CLI çıxışı qaytarılmır.

## Outcome

- Signal: useful

## Source Nodes

- app_mgs_hsgq_live_ont_inventory
- app_mgs_device_access_profile
- ont_analysis_matches
- collect
- parse