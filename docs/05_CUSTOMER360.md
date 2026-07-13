# 05 Customer360

## Purpose

Customer360 is the single operational view for everything related to one customer. Operators, support, NOC, GPON engineers, billing staff and AI must understand the customer state without opening separate systems.

## Required Header

- Customer name, customer code and contract code.
- Status badge: active, deactive, suspended, debtor or risk.
- Online/offline badge with visible color difference.
- Package, balance and remaining days.
- Risk score and QoE score.
- Main IP, NAT IP, static IP and service type.
- Quick actions with confirmation where required.

## Required Context

- Personal data: name, phone, FIN, email and customer portal identity.
- Address, branch and zone.
- Contract and stable billing day if configured.
- Equipment: ONT/modem, SN, MAC, model, barcode, assigned employee and inventory state.
- Network location: OLT, PON, ONT ID, cable length and connector notes.
- RADIUS/IP: username, service type, IP assignment, NAS, session state and sync status.

## Required Tabs

- Overview, Services, Payments, Statements, RADIUS, Traffic, ONT / Optical, Tickets, SMS / Notifications, Logs and Documents.

## Quick Actions

- Take payment.
- Open ticket.
- Send SMS/Telegram/WhatsApp/app notification.
- Change package.
- Extend or set stable billing day.
- RADIUS disconnect or CoA.
- ONT restart.
- Change IP.
- Assign static IP.
- Link equipment.

## Safety Rule

Customer360 can recommend and prepare actions, but destructive network actions and financial corrections require permission and confirmation.
