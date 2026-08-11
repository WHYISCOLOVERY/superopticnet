# Stage 4 - Message Center / Live Monitor Acceptance

Date: 2026-07-25
Status: Pilot enabled

## Scope delivered

Stage 4 closes the mandatory Message Center targets from
`32_UNIFIED_UX_AUDIT.md` sections 7.1-7.3 and Merhele 4.

### 1. Canonical backend status contract (audit 7.3)

New `whatsapp_monitor_number_status()` in `whatsapp-monitor-lib.php` is the
single source of truth for a number's status. It returns exactly:

```json
{
  "status": "online|sync_delayed|offline|disabled",
  "status_reason": "human sentence",
  "last_activity_at": "datetime|null",
  "last_sync_at": "datetime|null",
  "unread_count": 0
}
```

- Thresholds come from two new settings, `heartbeat_stale_minutes` (default 10)
  and `sync_stale_minutes` (default 30); the frontend never derives status.
- Green = fresh heartbeat, yellow = pending QR or delayed sync, red = expired
  session or stale heartbeat, grey = disabled/no session.
- `whatsapp_monitor_number_status_cards()` joins the per-number groups with an
  unanswered-chat count (last inbound message with no outbound reply).
- New endpoint `api/whatsapp-monitor/number-status.php` (module-guarded)
  returns the cards for the live poll.

### 2. Live Monitor as the primary view (audit 7.1, flag-gated)

Feature flag `stage4_message_center`:

- `whatsapp_monitor_default_view()` returns `live-monitor` when the flag is on,
  so a normal user lands on the Live Monitor, not the KPI overview.
- New `views/whatsapp-monitor/live-monitor.php` renders read-only company-number
  cards: number, employee, status badge + reason, last activity, last sync,
  chat count and unanswered badge, with a "Çatları aç" link scoped by
  `number_id`. A self-contained script polls the status endpoint every
  `stream_refresh_seconds` and updates each card in place.
- The menu leads with Canlı Monitor; the video `screen-wall` view leaves the
  menu but stays reachable by direct URL as an alias
  (`whatsapp_monitor_all_view_keys()` still validates it).

### 2b. Phone-grid with inline chats and enlarge modal

Per operator request, the Live Monitor renders each company number as a
phone-style card in a 3-column grid (2 columns <=960px, 1 column <=600px):

- Each phone shows its status badge + reason, chat/unanswered counts, and an
  inline scrollable preview of its most recent chats (contact, last message,
  time) via a new `recent_chats` field on the status cards.
- Clicking a phone (or pressing Enter/Space) opens an in-page modal instead of
  navigating away: left = full chat list for that number with a contact search,
  right = the selected chat's full message history with a message search.
- The modal loads from the existing `api/whatsapp-monitor/chat-list.php` and
  `chat-messages.php` endpoints, is an `aria-modal` dialog, and closes on the
  close button, backdrop click or Escape. It remains strictly read-only.
- The status poll stays light: `number-status.php` requests cards with
  `$withChats = false` so the 10s refresh never re-ships chat previews.

### 3. Read-only guarantee

The monitor has no `send_message`/`reply_message`/`delete_message` action in
`whatsapp-monitor.php` or any view; the contract smoke asserts this. Outbound
messaging stays in the separate Message Center (`Gonderisler`) with its real
queue and approval, unchanged.

### 4. Production mock already neutralized

The synthetic `TEST MESAJI` history row in `message-center.php` is gated behind
`app_demo_fixtures_enabled()`, which returns false whenever the environment is
`production` (verified: `env=production, demo=OFF`). The smoke asserts both the
gate and the disabled state, so the empty-history production mock the audit
flagged cannot appear.

## Verification

- `tools/stage4-message-center-contract-smoke.php`:
  28 pass / 0 fail with the flag off, 29 pass / 0 fail with the flag on
  (status contract keys + enum, all threshold branches, default view/tabs,
  read-only guarantee, demo-gate, migration/cutover/API contracts, runtime flag).
- `tools/whatsapp-monitor-module-smoke.php`: passed.
- `tools/message-center-ui-contract-smoke.php`: ok.
- Logged-in browser (admin) at `whatsapp-monitor.php`:
  default view renders the Live Monitor, 2 real cards
  (`0506020055 -> Oflayn -> "Heartbeat kesilib"`), status API returns the
  canonical payload, the poll refreshes the timestamp, zero console errors,
  no mobile overflow at 375px, `?view=screen-wall` still returns 200.
- PHP lint clean on all touched files.
- Rollback cycle verified: disable -> migration rollback -> reapply -> re-enable.

## Out of scope / follow-up (Stage 5+)

- Full merge of `cases`/`performance`/`audit` into card filters and a global
  report/journal preset (the audit's longer-term consolidation).
- Real worker heartbeat wiring: `qr_last_seen_at` is the current heartbeat
  source; a dedicated bridge heartbeat column can refine `last_sync_at`.
- Stage 5 Agent Workspace (allowlist service calls, preview/confirm/audit/undo).
