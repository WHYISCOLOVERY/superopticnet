# 13 Inventory

## Purpose

Inventory manages warehouse stock, equipment assets, cable drums, employee handovers, customer-linked devices and inventory reports.

## Required Functions

- Item/SKU creation and edit.
- Stock entry.
- Stock exit.
- Purchase/expense link.
- Transfer between warehouse, employee and customer.
- Employee handover and return confirmation.
- Customer equipment assignment.
- Barcode scan.
- Critical stock alert.
- Cable drum meter tracking.
- Inventory reports.

## Required Objects

- Product/SKU: category, model, unit, minimum stock, barcode policy.
- Stock movement: entry, exit, transfer, return, write-off and sale.
- Employee asset: item, serial, quantity, handover status and confirmation.
- Customer equipment: ONT/modem/router, SN, MAC, model, install ticket and service link.
- Cable drum: initial meters, used meters, remaining meters and technician usage.

## UI Requirements

- Visual stock overview cards.
- Critical stock cards.
- Barcode scan action.
- Technician assignment view.
- Cable drum progress bars.
- Item detail side panel with movement timeline.

## AI/Superpilot Use

- Answer who has this modem.
- Answer which employee took this item.
- Answer what items are assigned to this employee.
- Recommend missing stock, suspicious write-offs and repeated unreturned equipment.

## Audit Rule

Every stock movement requires actor, reason, timestamp and source document/ticket where applicable.
