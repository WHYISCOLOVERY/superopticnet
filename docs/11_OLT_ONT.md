# 11 OLT ONT

## Purpose

Manage OLTs, PON ports, ONTs, optical health, modem/ONT inventory linkage and customer diagnostics.

## Required Views

- OLT list as health cards.
- ONT list with SN, MAC, customer, OLT, PON, signal and status filters.
- PON tree: OLT -> PON -> ONT -> Customer.
- Optical dashboard with RX/TX, OLT RX/TX, temperature, health score and degraded ONT list.
- ONT restart log.
- Device discovery and native OLT command results.

## Customer Linkage

- Customer create/edit can select OLT/PON/ONT where known.
- Ticket close can link installed ONT SN/MAC, cable length and connector notes.
- Linked ONT data appears in Customer360.
- Modem search bot should answer using this same data layer.

## Live Actions

- Live ONT optical read.
- ONT restart.
- OLT/PON detail refresh.
- Discovery scan.
- Configuration backup where supported.

## Safety Rules

- Do not run full ONT optical polling on all OLTs at the same time.
- Stagger jobs by OLT/PON and cache latest state.
- Restart and disruptive actions require permission and confirmation.
- If SNMP/internal VLAN is unavailable, show low confidence instead of fake certainty.
- Vendor command/OID mappings must be vendor-specific and tested before production.

## Ticket Integration

- Multiple customer tickets on the same PON should be grouped as network problem.
- Poor signal, high temperature, frequent restart/flapping and repeated auth failures can create automatic tickets by policy.
