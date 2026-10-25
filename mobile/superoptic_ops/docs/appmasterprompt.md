SUPEROPTIC MGS — APP UI/UX REDESIGN MASTER PROMPT v5.0
markdown
# SUPEROPTIC APP — FIELD OPERATIONS REDESIGN PROMPT v5.0

> **Məqsəd:** Mövcud işlək SuperOptic app-in UI/UX-ini tam yenidən dizayn etmək,
> rol əsaslı görünürlük, offline mode, portfolio sahibliyi, bonus sistemi,
> avtomatik ticket bağlanması və ərazi əsaslı routing əlavə etmək.
>
> **Prinsip:** Texniki işçi "işçi" deyil — "kiçik sahibkar"dır. App onun
> gündəlik iş yoldaşıdır, sadəcə data girişi paneli deyil.
>
> Hazırlanma: 2026 · v5.0

---

## 0. SİSTEM ROL TƏRİFİ

Sən SuperOptic MGS layihəsinin **baş UI/UX arxitekti və frontend lead**-isən.

Rolların:
- Senior Product Designer (mobile-first, field operations)
- UX Researcher (field technician workflows)
- Frontend Architect (React Native / Flutter + React/Next.js)
- Design System Engineer
- Offline-First Application Architect
- Role-Based Access Control (RBAC) Specialist
- Mobile Performance Engineer
- Accessibility Specialist (WCAG 2.1 AA)

Sənin missiyan: **mövcud işlək app-i pozmadan**, onun üzərinə tam yeni
UI/UX qatı qurmaq. Hər dəyişiklik **kiçik patch** şəklində olmalıdır,
böyük rewrite qadağandır.

---

## 1. ƏSAS PRİNSİPLƏR (dəyişməz)

1. **Ownership First** — Texniki öz müştərilərinin sahibi kimi hiss etsin
2. **Human Language** — Ticket "SO-2026-00123" deyil, "Rəşad Məmmədov — İnternet işləmir"
3. **Offline Always Works** — Sahədə internet olmasa da iş davam edir
4. **Role-Based Vision** — Hər rol yalnız öz işinə aid olanı görür
5. **Territory-Aware** — Ticket yalnız həmin ərazidəki texnikiə gedir
6. **Auto-Resolve** — Problem özü həll olsa, ticket özü bağlanır
7. **Transparent Bonus** — İşçi real vaxtda nə qazandığını görür
8. **Privacy First** — Texnikin konumu yalnız admin/region_manager görür
9. **Portfolio Health** — Müştəri saxlama = bonus + karyera
10. **No Big Rewrite** — Mövcud işləyən modulu pozma, kiçik patch et

---

## 2. DİZAYN SİSTEMİ (Design Tokens)

### 2.1 Rəng Palitrası

```css
:root {
  /* Əsas palitra */
  --primary-900: #0A2540;
  --primary-700: #1B4B7A;
  --primary-500: #2E7BC4;
  --primary-100: #E6F0FA;

  /* Status */
  --success: #10B981;
  --warning: #F59E0B;
  --danger:  #EF4444;
  --info:    #3B82F6;
  --neutral: #6B7280;

  /* Status rəngləri */
  --status-online:      #10B981;
  --status-offline:     #EF4444;
  --status-degraded:    #F59E0B;
  --status-maintenance: #8B5CF6;

  /* Prioritization */
  --priority-critical: #DC2626;
  --priority-high:     #F97316;
  --priority-medium:   #3B82F6;
  --priority-low:      #6B7280;

  /* Background */
  --bg-primary:  #F9FAFB;
  --bg-card:     #FFFFFF;
  --bg-sidebar:  #0A2540;
  --border:      #E5E7EB;

  /* Dark mode (offline/sahə üçün) */
  --dark-bg:     #0F172A;
  --dark-card:   #1E293B;
  --dark-text:   #F1F5F9;
}
Qaydalar:

Tünd rejim (dark mode) default sahə texnikləri üçün (günəş altında oxunaqlı)

Status rəngləri hər yerdə eyni məna daşıyır

Heç bir yerdə 3-dən çox rəng bir yerdə olmaz

2.2 Tipoqrafiya
css
font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;

--text-xs:   12px;  /* metadata, timestamp */
--text-sm:   14px;  /* body, secondary */
--text-base: 16px;  /* əsas oxu */
--text-lg:   18px;  /* kart başlığı */
--text-xl:   24px;  /* səhifə başlığı */
--text-2xl:  32px;  /* KPI rəqəmləri */
--text-3xl:  48px;  /* hero dashboard */

font-weights: 400 / 500 / 600 / 700;
line-height: 1.5 (body), 1.2 (heading);
2.3 Boşluq (Spacing Scale)
css
--space-1: 4px;   --space-2: 8px;
--space-3: 12px;  --space-4: 16px;
--space-5: 20px;  --space-6: 24px;
--space-8: 32px;  --space-10: 40px;
2.4 Komponent Kitabxanası
yaml
Button:
  variants: [primary, secondary, danger, ghost, icon]
  sizes: [sm(32px), md(40px), lg(48px)]
  radius: 8px
  primary:
    bg: var(--primary-700)
    color: white
    hover: var(--primary-500)
    active: scale(0.98)
    disabled: opacity 0.5
  touch_target_minimum: 44x44px  # WCAG
  loading_state: spinner + disabled

Card:
  radius: 12px
  shadow: 0 1px 3px rgba(0,0,0,0.08)
  hover_shadow: 0 4px 12px rgba(0,0,0,0.12)
  padding: 20px

TicketCard:
  layout:
    left_stripe: 4px (priority rəngi)
    content:
      title: "Müştəri adı — Problem"
      meta: "Zona · #Code · Vaxt"
      badges: [prioritet, kateqoriya, SLA]
    right: SLA_countdown + action_button
  min_height: 120px

StatusBadge:
  dot: 8px radius, rəng status
  text: 12px, semibold
  padding: 4px 10px
  radius: 999px (pill)

ProgressBar:
  height: 8px
  radius: 999px
  fill: gradient (primary → success)
  label: percent (right aligned)

EmptyState:
  icon: 64px
  title: 18px semibold
  description: 14px neutral
  action_button: primary

SkeletonLoader:
  shimmer_animation: 1.5s infinite
  use_for: bütün async data
  no_spinner_alone: həmişə skeleton
3. ROL ƏSASLI GÖRÜNÜRLÜK MATRİSİ
yaml
roles:
  super_admin:
    sees: [bütün_ekranlar, xəritə, konumlar, maliyyə, HR, audit]
    can_see_technician_location: true
    can_see_all_portfolios: true
    can_modify_bonus_rules: true
    can_delete_data: true

  admin:
    sees: [bütün_ekranlar, xəritə, konumlar, maliyyə]
    can_see_technician_location: true
    can_see_all_portfolios: true
    can_modify_bonus_rules: false
    can_delete_data: false

  region_manager:
    sees: [öz_regionu, komanda, ticketlər, xəritə_öz_regionu]
    can_see_technician_location: true   # yalnız öz regionundakı
    can_see_all_portfolios: "yalnız öz regionu"
    scope: "region:{region_id}"

  noc_engineer:
    sees: [alarms, devices, diagnostics, xəritə_şəbəkə]
    can_see_technician_location: false
    can_see_portfolios: false

  field_technician:
    sees: [öz_ticketləri, öz_portfolio, öz_ərazi, öz_marşrutu]
    can_see_technician_location: false
    can_see_own_location: true
    can_see_others_portfolio: false

  inventory_worker:
    sees: [anbar, material_soğrusu, baraban_izləmə]
    can_see_field_tickets: false  # HEÇ VAXT sahə ticketi görməz
    can_receive_field_tickets: false

  billing_operator:
    sees: [müştəri, ödəniş, borc, hesabat]
    can_see_technician_location: false

  viewer:
    sees: [dashboard_readonly]
    can_see_technician_location: false
3.1 Konum Məxfiliyi Siyasəti
yaml
location_policy:
  collection:
    - Yalnız AKTİV ticket zamanı
    - Ticket qəbul edildikdə başlayır
    - Ticket bağlandıqda dayanır
    - Background GPS yox (yalnız app foreground)
    - İşçi "yoldasan" statusuna keçəndə aktivləşir

  storage:
    - Encrypted at rest
    - 30 gün saxlanılır
    - Sonra avtomatik silinir

  visibility:
    super_admin: full_history
    admin: full_history
    region_manager: yalnız öz regionu
    field_technician: yalnız öz konumu (real-time)
    others: görünməz

  offline:
    - GPS koordinatları lokal saxlanılır
    - Online olanda sync edilir
    - Sync vaxtı "aktual vaxt" kimi qeyd olunur

  consent:
    - İlk işə başlayanda işçidən razılıq alınır
    - "İş saatlarında konumunuz yalnız aktiv ticket üçün izlənir"
    - Razılıq versiyası audit-də saxlanılır
Qadağan:

İş saatlarından kənar konum toplama

Sahə texnikinə başqa texnikin konumunu göstərmək

Konumu 3-cü tərəfə satmaq / ötürmək

Konumdan işçinin performansını qiymətləndirmək (yalnız ticket SLA)

4. TICKET ANLAMLANMASI (Semantic Tickets)
4.1 Ticket İki Səviyyədə Təqdim Edilir
İşçi rəqəm görməməli, hekayə görməli:

text
┌────────────────────────────────────────────────────────┐
│ 🟠 YÜKSƏK    ⏱ 45 dəq qaldı    📍 Nərimanov          │
│                                                        │
│ Rəşad Məmmədov — İnternet işləmir                    │
│                                                        │
│ 💡 Auto-diagnostika: ONT offline, son signal -28.2 dBm│
│    Ehtimal: fiber qırıq və ya ONT söndürülüb          │
│                                                        │
│ 🏢 Müştəri: 3 il, VIP · Aylıq 45 AZN · 0 borc        │
│                                                        │
│ [Qəbul et]  [Detallar]  [Zəng et]                    │
└────────────────────────────────────────────────────────┘
4.2 Ticket Məcburi Sahələri
yaml
ticket_display:
  must_show:
    - müştəri_adı (böyük, bold)
    - problem_insan_dilində (1 cümlə)
    - AI_auto_diagnostika (2-3 sətir)
    - müştəri_konteksti (illər, VIP, borc)
    - SLA_countdown (rəngli)
    - zona_ərazi
    - prioritet_badge

  must_not_show_to_technician:
    - customer_id (rəqəm)
    - internal_notes (yalnız admin)
    - maliyyə detalları (ödəniş tarixçəsi istisna)
    - digər texniklərin adları
    - şirkət daxili kodlar

  examples:
    good: "Rəşad Məmmədov — İnternet işləmir"
    bad:  "Ticket SO-2026-00123 — Category: CONN_ISSUE — Status: OPEN"
4.3 Ticket Kateqoriyaları (İnsan Dilində)
yaml
ticket_categories:
  connectivity:
    label: "İnternet işləmir"
    icon: "🌐"
    auto_diagnostic: true
  slow_speed:
    label: "İnternet yavaşdır"
    icon: "🐌"
    auto_diagnostic: true
  wifi_issue:
    label: "Wi-Fi problemi"
    icon: "📡"
    auto_diagnostic: true
  tv_service:
    label: "TV şəkli donur"
    icon: "📺"
  billing:
    label: "Ödəniş / borc"
    icon: "💳"
  install:
    label: "Yeni quraşdırma"
    icon: "🔧"
  other:
    label: "Digər"
    icon: "❓"
5. TICKET LIFECYCLE
yaml
states:
  created:
    display: "Yeni"
    color: var(--info)
    actions: [qəbul_et, rədd_et]

  accepted:
    display: "Qəbul edildi"
    color: var(--warning)
    actions: [yola_düş, ləğv_et]

  en_route:
    display: "Yoldasan"
    color: var(--status-maintenance)
    actions: [çatdım, geri_qaytar]
    note: "GPS aktiv, admin xəritədə görür"

  on_site:
    display: "Məkanda"
    color: var(--status-maintenance)
    actions: [işə_başla]

  in_progress:
    display: "İşlənir"
    color: var(--warning)
    actions: [foto_əlavə, qeyd_yaz, bağla]

  resolved:
    display: "Həll edildi"
    color: var(--success)
    actions: [bağla, yenidən_aç]

  closed:
    display: "Bağlandı"
    color: var(--success)
    actions: []  # read-only

  auto_resolved:
    display: "Avtomatik bağlandı"
    color: var(--success)
    badge: "AUTO"
    actions: []

  escalated:
    display: "Eskalasiya"
    color: var(--danger)
    actions: [yenidən_qəbul]

  cancelled:
    display: "Ləğv edildi"
    color: var(--neutral)
    actions: []
5.1 Ticket Bağlama — Məcburi Formu
Ticket bağlamaq üçün bütün bu sahələr məcburidir:

yaml
ticket_close_form:
  required:
    problem_solved:
      type: dropdown
      options:
        - "Fiber qırığı bərpa edildi"
        - "ONT restart edildi, işləyir"
        - "Konnektor təmizləndi"
        - "Splitter dəyişdirildi"
        - "ONT dəyişdirildi"
        - "Router konfiqurasiya edildi"
        - "Müştəri tərəfində problem idi"
        - "Müştəri yox idi, sonra zəng edildi"
        - "Problem təkrar etdi, eskalasiya"
        - "Digər (qeyd yaz)"

    root_cause:
      type: textarea
      min_length: 20
      placeholder: "Problemin əsl səbəbi nə idi?"

    actions_taken:
      type: textarea
      min_length: 30
      placeholder: "Nə etdiniz? Addım-addım yazın"

    photo_before:
      type: image
      max_size: 5MB
      mime: [jpg, png, heic]
      required: true

    photo_after:
      type: image
      max_size: 5MB
      required: true

    time_spent_minutes:
      type: number
      min: 1
      max: 480

    materials_used:
      type: multi_select
      source: inventory_service
      optional: true

    customer_signature:
      type: signature_pad
      required_if: "müştəri evdədir"

  optional:
    voice_note:
      type: audio
      max_duration_seconds: 30

    follow_up_needed:
      type: boolean

    follow_up_date:
      type: date
      required_if: follow_up_needed

    customer_feedback:
      type: rating (1-5)
      note: "Müştəri sizin işinizi qiymətləndirsin"

  validation:
    - Bütün required sahələr doldurulmalıdır
    - Foto minimum 1, maksimum 5
    - Root cause minimum 20 simvol
    - Əgər "Digər" seçilibsə, qeyd minimum 30 simvol
Niyə bu qədər məcburi?

Gələcək müraciətlərdə kontekst üçün

İşçinin işi sübutlanır (before/after foto)

Anbar inventarı avtomatik azalır

Bonus hesablanması üçün data

Müştəri məmnuniyyəti üçün əsas

6. OFFLINE MODE (Kritik)
Qeyd: Mövcud app-də offline mode işləyir. Bunu genişləndir və təkmilləşdir.

6.1 Arxitektura
yaml
offline_first:
  storage:
    web: IndexedDB (Dexie.js)
    mobile: SQLite (react-native-sqlite / drift)
    cache_size_limit: 100MB

  cached_data:
    - bütün açıq ticketlər (işçinin özünə aid)
    - müştəri əsas məlumatları (ad, ünvan, telefon)
    - müştəri son 5 ticket-i
    - ONT məlumatları
    - material siyahısı (son sync)
    - bonus cari vəziyyət

  offline_allowed_actions:
    - ticket_qəbul_et
    - status_dəyiş (yolda, çatdım, işləyirəm)
    - foto_çək (lokal saxla)
    - qeyd_yaz
    - ticket_bağla
    - material_istifadə_et (lokal)
    - voice_note_çək
    - customer_signature_al

  offline_blocked_actions:
    - yeni_ticket_yarat (server lazım)
    - müştəri_əlavə_et
    - ödəniş_qəbul_et
    - VLAN_əlavə_et
    - config_push

  queue_management:
    - hər əməliyyat UUID ilə işarələnir
    - server tərəfdə idempotency
    - conflict: server_wins (timestamp əsaslı)
    - retry: 3 dəfə, exponential backoff (1s, 5s, 30s)

  sync_on_reconnect:
    batch_upload:
      - bütün dəyişikliklər bir dəfəyə
      - paralel upload (5 eyni anda)
      - progress göstər
    sla_calculation:
      - SLA işçinin ƏMƏLİYYAT etdiyi vaxta görə
      - Sync vaxtına görə DEYİL
    photo_upload:
      - arxa fonda
      - thumbnail əvvəl, full sonra
    offline_banner:
      - offline başlayanda: "⚠️ Offline rejim — işləməyə davam edə bilərsən"
      - sync zamanı: "🔄 Sinxronlaşdırılır... (5/12)"
      - sync bitdikdə: "✅ Hər şey sinxronlaşdırıldı"
6.2 Real Ssenari
text
14:30 — Rəşad Nərimanova çatdı, internet yoxdur
14:32 — App offline rejimə keçdi, banner göstərir
14:35 — Rəşad "Qəbul et" basdı → lokal saxlanıldı (UUID: abc-123)
14:40 — Foto çəkdi → lokal
14:50 — Fiber qırığını tapdı, birləşdirdi
15:00 — "Bağla" formu doldurdu → lokal
15:15 — Müştərinin evindən çıxdı
15:16 — 4G gəldi, app avtomatik sync:
        ✅ Ticket 14:35-də qəbul edildi (SLA düzgün)
        ✅ Fotolar yükləndi
        ✅ Status bağlandı
        ✅ Anbar azaldıldı
        ✅ Bonus hesablandı
        ✅ NOC məlumatlandırıldı
6.3 Offline UI Göstəricisi
yaml
offline_indicator:
  position: top_bar (fixed)
  states:
    online:
      color: var(--success)
      text: none (gizli)
    offline:
      color: var(--warning)
      text: "⚠️ Offline — məlumatlar lokal saxlanılır"
      icon: cloud_off
    syncing:
      color: var(--info)
      text: "🔄 Sinxronlaşdırılır... {progress}/{total}"
      icon: sync (animated)
    sync_complete:
      color: var(--success)
      text: "✅ Sinxron tamamlandı"
      icon: cloud_done
      auto_hide_seconds: 3
    sync_error:
      color: var(--danger)
      text: "❌ Sinxron xətası — təkrar cəhd edilir"
      action: "Manual cəhd et"

  pending_queue_badge:
    show: "Yadda saxlanmış {n} əməliyyat"
    position: bottom_right
    tap: shows queue details
7. AUTO-RESOLVE ENGINE
Sənin istədiyin: "internet gələn kimi həmin saat üçün biletin bağlanmış olduğu sinxronlaşır"

python
class AutoResolveEngine:
    """
    Hər 5 dəqiqədə işləyir.
    Əgər ticket-də göstərilən problem artıq yoxdursa və
    müştəri online-dırsa → ticket avtomatik bağlanır.
    Real resolve vaxtı qeyd olunur (sync vaxtı deyil).
    """

    AUTO_RESOLVABLE_TYPES = [
        'ont_offline',
        'pppoe_down',
        'fiber_cut',
        'signal_degraded',
        'los_alarm'
    ]

    async def check_resolved_tickets(self):
        open_tickets = await Ticket.query() \
            .where('status', 'in', ['accepted', 'en_route', 'on_site', 'in_progress']) \
            .where('problem_type', 'in', self.AUTO_RESOLVABLE_TYPES) \
            .where('auto_resolvable', True) \
            .get()

        for ticket in open_tickets:
            customer = await Customer.find(ticket.customer_id)

            # 1. Müştəri online-dır?
            session = await radius.get_session(customer.id)

            # 2. Problem növü üzrə yoxlama
            if ticket.problem_type == 'ont_offline':
                ont = await olt.get_ont(customer.id)
                if ont and ont.status == 'online' and ont.rx_power > -27:
                    await self.auto_close(
                        ticket,
                        reason='ONT yenidən online oldu',
                        resolved_at=ont.last_online_at  # TAM VAXT
                    )

            elif ticket.problem_type == 'pppoe_down':
                if session and session.uptime > 300:  # 5 dəq stabil
                    await self.auto_close(
                        ticket,
                        reason='PPPoE sessiya bərpa olundu',
                        resolved_at=session.start_time
                    )

            elif ticket.problem_type == 'fiber_cut':
                # Eyni PON-da bütün ONT-lər online-dırsa
                if await self.all_onts_online(ticket.pon_port_id):
                    await self.auto_close(
                        ticket,
                        reason='Fiber bərpa olundu, bütün ONT-lər online',
                        resolved_at=datetime.now()
                    )

    async def auto_close(self, ticket, reason, resolved_at):
        # SLA vaxtı DÜZGÜN hesablanır — real bərpa vaxtına görə
        ticket.status = 'auto_resolved'
        ticket.resolved_at = resolved_at
        ticket.resolution_notes = f"[AUTO] {reason}"
        ticket.auto_resolved = True
        ticket.resolved_by = 'system'
        await ticket.save()

        # İşçiyə bildiriş
        if ticket.assigned_to:
            await notify(
                ticket.assigned_to,
                title="✅ Ticket avtomatik bağlandı",
                body=f"{ticket.customer_name} — {reason}",
                action="detail",
                action_id=ticket.id
            )

        # Bonus hesablanması
        await bonus_engine.credit_auto_resolve(ticket)

        # Audit log
        await audit.log('ticket.auto_resolved', {
            'ticket_id': ticket.id,
            'reason': reason,
            'resolved_at': resolved_at,
            'previous_status': ticket.status_before
        })
7.1 İşçiyə Təsiri
Əgər işçi yoldadırsa və problem avtomatik həll olubsa:

text
┌─────────────────────────────────────────────────────┐
│  🔔 BİLDİRİŞ                                        │
│                                                     │
│  ✅ Ticket #SO-2026-00123 avtomatik bağlandı       │
│                                                     │
│  Rəşad Məmmədov — İnternet işləmir                 │
│  Səbəb: ONT yenidən online oldu (14:48)            │
│                                                     │
│  Sən artıq getmək lazım deyil.                     │
│  Bonus: +10 AZN (avtomatik həll)                   │
│                                                     │
│  [Ana səhifəyə qayıt]  [Müştəriyə zəng et]        │
└─────────────────────────────────────────────────────┘
Qayda: İşçi heç vaxt artıq həll olunmuş problemin yerinə getməyə məcbur edilmir. Bu vaxt, yanacaq, əsəb qənaətidir.

8. ƏRAZİ ƏSASLI TICKET ROUTING
python
class TicketRoutingEngine:
    """
    Ticket yalnız həmin ərazidə işləyən texnikiə gedir.
    Bakı ticketi Gəncə işçisinə düşmür.
    """

    async def assign_ticket(self, ticket: Ticket) -> Employee:
        # 1. Ticket-in zonasını müəyyən et
        zone = await self.get_zone(ticket.customer_id)

        # 2. Həmin zonada işləyən işçiləri tap
        candidates = await Employee.query() \
            .where('status', 'active') \
            .where('on_vacation', False) \
            .where('current_shift', True) \
            .where_raw("? = ANY(zone_ids)", zone.id) \
            .get()

        if not candidates:
            # fallback: qonşu zonaya yönləndir
            candidates = await self.get_nearby_employees(zone)
            await self.notify_region_manager(
                zone.region_id,
                f"Zona {zone.name} üçün texniki yoxdur, qonşu zonadan göndərildi"
            )

        # 3. Material tələbi varsa, material olan texnikiə
        if ticket.required_materials:
            candidates = await self.filter_by_materials(
                candidates, ticket.required_materials
            )

        # 4. Yükə görə sırala
        candidates.sort(key=lambda e: (
            e.active_tickets_count,      # az yük
            -e.kpi_score,                 # yüksək KPI
            e.distance_to_ticket          # yaxın məsafə
        ))

        # 5. Ən uyğun işçini seç
        best = candidates[0]

        # 6. İşçiyə bildiriş
        await self.notify_technician(best, ticket)

        return best
8.1 Rol Əsaslı Routing
yaml
role_ticket_routing:
  field_technician:
    gets: [sahə_problemləri, ont_offline, fiber_qırığı, pppoe_down, wifi, tv]

  noc_engineer:
    gets: [core_problem, uplink_down, bgp_issue, ddos]

  inventory_worker:
    gets: [material_sorgusu, anbar_tələbi]
    never_gets: [sahə_ticketi]  # HEÇ VAXT

  billing_operator:
    gets: [ödəniş_problemi, borc_sualı]

  install_team:
    gets: [yeni_quraşdırma, yeni_müştəri]
8.2 Zona Əməliyyatları
yaml
zone_assignment_rules:
  primary_zone:
    - texnikin əsas iş ərazisi
    - həmişə prioritet

  secondary_zones:
    - texnikin kömək edə biləcəyi zonalar
    - yalnız primary_zone boşdursa

  forbidden:
    - uzaq_zona (30km+ məsafə)
    - başqa_region (region_id fərqlidirsə)

  emergency_override:
    - super_admin tərəfindən əl ilə
    - audit log məcburi
    - səbəb qeyd olunmalı
9. MÜŞTƏRİ PORTFOLİO (İşçinin "Öz Müştəriləri")
Bu motivasiya mühərrikidir. İşçi öz müştərilərini tanımalı, onlarla əlaqə saxlamalı, saxlamağa çalışmalıdır.

9.1 Portfolio Dashboard (Əsas Ekran)
text
┌─────────────────────────────────────────────────────┐
│  MÜŞTƏRİ PORTFOLİOM                                 │
├─────────────────────────────────────────────────────┤
│                                                     │
│  ┌─────────┬─────────┬─────────┬─────────┐        │
│  │   142   │   132   │    10   │   98    │        │
│  │ Ümumi   │ Aktiv   │ Deaktiv │ Online  │        │
│  └─────────┴─────────┴─────────┴─────────┘        │
│                                                     │
│  ─── DİQQƏT TƏLƏB EDƏN MÜŞTƏRİLƏR ───             │
│                                                     │
│  🔴 7 müştəri 2 həftədir offlinedır                │
│     → Zəng et, soruş. Onları itirmə!              │
│     [Siyahıya bax]                                 │
│                                                     │
│  🟡 3 müştəri bu ay ödəməyib                        │
│     → Xatırlatma SMS göndər                        │
│     [Siyahıya bax]                                 │
│                                                     │
│  🟢 12 müştəri 12+ aydır sadiqdir                   │
│     → Minnətdarlıq + bonus təklif                  │
│     [Siyahıya bax]                                 │
│                                                     │
│  ─── MÜŞTƏRİ SAXLAMA BALI ───                      │
│                                                     │
│  Bu ay: 3 müştəri saxlaya bildin                   │
│  Keçən ay: 5 müştəri saxlaya bildin                │
│  Bonus qazancı: +90 AZN                            │
│                                                     │
│  ─── PORTFOLIO SAĞLAMLIQ SKORU ───                │
│                                                     │
│  ▓▓▓▓▓▓▓░░░  78/100                                │
│  Yaxşı! 85-ə çatmaq üçün 4 müştəri zəng et.        │
│                                                     │
└─────────────────────────────────────────────────────┘
9.2 Müştəri Siyahısı
text
┌─────────────────────────────────────────────────────┐
│  MÜŞTƏRİLƏRİM (142)          [🔍 Axtar]  [⚙️ Filter]│
├─────────────────────────────────────────────────────┤
│                                                     │
│  🟢 Rəşad Məmmədov     VIP     3 il    45 AZN     │
│     Online · Son ticket: 2 həftə əvvəl             │
│     [Zəng et]  [SMS]  [Profil]                     │
│                                                     │
│  🔴 Aysel Əliyeva       —      1 il    35 AZN     │
│     Offline 2 həftə ⚠️ risk                        │
│     [Zəng et]  [SMS]  [Profil]                     │
│                                                     │
│  🟢 Kamran Həsənov     VIP     5 il    60 AZN     │
│     Online · 0 problem · Sadiq müştəri             │
│     [Zəng et]  [SMS]  [Profil]                     │
│                                                     │
│  🟡 Elvin Quliyev       —      6 ay    35 AZN     │
│     Borc: 35 AZN · Bu ay ödəməyib                  │
│     [Zəng et]  [SMS]  [Profil]                     │
│                                                     │
│  ...                                                │
│                                                     │
└─────────────────────────────────────────────────────┘
9.3 Portfolio Sağlamlıq Skoru
python
PORTFOLIO_HEALTH_WEIGHTS = {
    "active_ratio":        0.30,  # aktiv müştərilər / ümumi
    "online_ratio":        0.25,  # online müştərilər / aktiv
    "payment_ratio":       0.20,  # vaxtında ödəyənlər
    "retention_ratio":     0.15,  # saxlanan müştərilər
    "satisfaction_score":  0.10   # müştəri məmnuniyyəti
}

def calculate_portfolio_health(employee_id: int) -> PortfolioHealth:
    customers = get_customers_by_technician(employee_id)

    active_ratio = count_active(customers) / len(customers)
    online_ratio = count_online(customers) / count_active(customers)
    payment_ratio = count_on_time_payers(customers) / count_active(customers)
    retention_ratio = count_retained_30d(customers) / count_at_risk(customers)
    satisfaction = avg_satisfaction(customers)

    score = (
        active_ratio * 100 * 0.30 +
        online_ratio * 100 * 0.25 +
        payment_ratio * 100 * 0.20 +
        retention_ratio * 100 * 0.15 +
        satisfaction * 20 * 0.10
    )

    return PortfolioHealth(
        score=round(score, 1),
        breakdown={
            "active": active_ratio * 100,
            "online": online_ratio * 100,
            "payment": payment_ratio * 100,
            "retention": retention_ratio * 100,
            "satisfaction": satisfaction * 20
        },
        tier=classify_tier(score),
        recommendations=generate_recommendations(score, breakdown)
    )

# Tier-lər:
# < 50:  "Yaxşılaşdırma lazımdır" — menecer ilə görüş
# 50-70: "Normal" — davam et
# 70-85: "Yaxşı" — bonus aktiv
# > 85:  "Əla" — əlavə bonus + tanınma
10. BONUS / RETENTION ENGINE
10.1 Bonus Qaydaları
python
class RetentionBonusEngine:

    BONUS_RULES = {
        "customer_retained": {
            "amount": 15,  # AZN
            "condition": "müştəri deaktiv olacaqdı, işçi saxladı",
            "verification": "müştəri 30 gün sonra hələ də aktiv"
        },
        "proactive_contact": {
            "amount": 5,
            "condition": "işçi offline müştəriyə zəng edib, problem həll olunub",
            "verification": "zəng log + müştəri online oldu"
        },
        "upsell": {
            "amount": 25,
            "condition": "işçi müştəriyə daha yüksək paket satdı",
            "verification": "package upgrade təsdiqlənmiş"
        },
        "ticket_fast_close": {
            "amount": 3,
            "condition": "SLA-dan 50% tez bağlandı",
            "verification": "resolved_at < deadline * 0.5"
        },
        "no_repeat_ticket": {
            "amount": 10,
            "condition": "eyni müştəri 30 gün ərzində təkrar müraciət etmədi",
            "verification": "30 gün sonra avtomatik hesablanır"
        },
        "auto_resolve_credit": {
            "amount": 10,
            "condition": "problem avtomatik həll olundu, işçi getmədi",
            "note": "işçi yol xərci çəkmədi, buna görə kiçik bonus"
        },
        "five_star_rating": {
            "amount": 5,
            "condition": "müştəri 5 ulduz verdi",
            "verification": "customer_feedback = 5"
        }
    }

    async def calculate_monthly_bonus(self, employee_id: int) -> BonusReport:
        employee = await Employee.find(employee_id)

        bonuses = []

        # 1. Retained customers
        retained = await self.count_retained(employee_id, days=30)
        if retained > 0:
            bonuses.append(Bonus("Müştəri saxlama", retained * 15, count=retained))

        # 2. Proactive contacts
        proactive = await self.count_proactive_contacts(employee_id, days=30)
        if proactive > 0:
            bonuses.append(Bonus("Proaktiv əlaqə", proactive * 5, count=proactive))

        # 3. SLA performance
        fast_closes = await self.count_fast_closes(employee_id, days=30)
        if fast_closes > 0:
            bonuses.append(Bonus("Sürətli bağlama", fast_closes * 3, count=fast_closes))

        # 4. Customer satisfaction
        avg_rating = await self.avg_customer_rating(employee_id, days=30)
        if avg_rating >= 4.5:
            bonuses.append(Bonus("Müştəri məmnuniyyəti", 100, detail=f"{avg_rating:.1f}/5"))

        # 5. Portfolio health
        portfolio = await self.calculate_portfolio_health(employee_id)
        if portfolio.score > 80:
            bonuses.append(Bonus("Portfolio sağlamlığı", 150, detail=f"{portfolio.score}/100"))

        # 6. Upsells
        upsells = await self.count_upsells(employee_id, days=30)
        if upsells > 0:
            bonuses.append(Bonus("Paket yüksəltmə", upsells * 25, count=upsells))

        total_bonus = sum(b.amount for b in bonuses)

        return BonusReport(
            employee=employee,
            base_salary=employee.base_salary,
            bonuses=bonuses,
            total_bonus=total_bonus,
            total_pay=employee.base_salary + total_bonus,
            month=current_month(),
            projection_next_month=self.project_next_month(employee_id)
        )
10.2 Bonus UI (İşçi Görür)
text
┌─────────────────────────────────────────────────────┐
│  BONUS TRACKER — MAY 2026                           │
├─────────────────────────────────────────────────────┤
│                                                     │
│  💰 Bu ay qazandığın bonus: 245 AZN                │
│  📈 Proqnoz (ay sonu): 380 AZN                     │
│                                                     │
│  ─── BONUS DETALLARI ───                           │
│                                                     │
│  ✅ Müştəri saxlama (3 × 15)            +45 AZN   │
│  ✅ Proaktiv əlaqə (8 × 5)              +40 AZN   │
│  ✅ Sürətli bağlama (12 × 3)            +36 AZN   │
│  ✅ Müştəri məmnuniyyəti                +100 AZN  │
│  ✅ Paket yüksəltmə (1 × 25)            +25 AZN   │
│  ⏳ Portfolio sağlamlığı (gözləyir)      +150 AZN │
│  ⏳ Təkrar müraciət yoxdur (gözləyir)   +30 AZN   │
│                                                     │
│  ─── NÖVBƏTİ AY ÜÇÜN HƏDƏFLƏR ───                │
│                                                     │
│  • 2 daha çox müştəri saxla → +30 AZN             │
│  • 5 proaktiv zəng et → +25 AZN                   │
│  • Portfolio 85-ə çatdır → +150 AZN               │
│                                                     │
│  [Detallı hesabat]  [Bonus qaydaları]             │
│                                                     │
└─────────────────────────────────────────────────────┘
11. İŞÇİNİN ŞƏXSİ PROFİLİ (Ownership Mindset)
11.1 Şəxsi Brendinq Ekranı
text
┌─────────────────────────────────────────────────────┐
│  👤 RƏŞAD MƏMMƏDOV                                  │
│  Field Technician · Nərimanov Zone                  │
│  ⭐ 4.8 / 5.0  (142 müştəridən)                    │
│  🏆 Top 3 in Nərimanov (bu ay)                     │
├─────────────────────────────────────────────────────┤
│                                                     │
│  ─── KARYERA YOLU ───                              │
│  🥉 Junior Technician (2024)                        │
│  🥈 Technician (2025)                               │
│  🥇 Senior Technician (2026) ← hazırda             │
│  🎯 Team Lead (2027 hədəf)                         │
│     Növbəti addım üçün: 3 ay əla performans        │
│                                                     │
│  ─── BUGÜNKÜ NƏTİCƏLƏR ───                         │
│  ✅ 6 ticket bağlandı                               │
│  ⏱ Orta cavab vaxtı: 18 dəq                        │
│  💰 Bonus: +45 AZN                                  │
│  ⭐ 3 müştəri 5 ulduz verdi                         │
│                                                     │
│  ─── SƏNİN MÜŞTƏRİLƏRİN DEDİ ───                  │
│  ⭐⭐⭐⭐⭐ "Rəşad çox sürətli gəldi"              │
│  ⭐⭐⭐⭐⭐ "Hər zaman kömək edir, təşəkkürlər!"   │
│  ⭐⭐⭐⭐   "İşini yaxşı gördü"                     │
│                                                     │
│  ─── KOMANDA ───                                    │
│  Nərimanov komandası: 4 texniki                     │
│  Bu həftə ən yaxşı: Aysel (43 ticket)              │
│  Sən: 38 ticket (2-ci)                             │
│                                                     │
│  ─── NÖVBƏTİ HƏDƏFLƏR ───                         │
│  ☐ Bugün 2 daha ticket bağla                       │
│  ☐ 3 offline müştəriyə zəng et                     │
│  ☐ 4.8 reytinqini 4.9-a qaldır                     │
│                                                     │
└─────────────────────────────────────────────────────┘
11.2 Şəxsi Statistika
İşçi görməli:

Bugünkü performansı

Bu həftəki trend

Bu aylıq bonus proqnozu

Komanda içində reytinqi (yalnız yaxşı tərəf göstərilir)

Müştərilərin onun haqqında nə dediyi

Növbəti karyera addımı üçün nə etməli

12. MÜŞTƏRİ TARİXÇƏSİ (Tam Görünüş)
İstənilən müştəri üçün açılan ekran:

text
┌─────────────────────────────────────────────────────┐
│  RƏŞAD MƏMMƏDOV  ·  SO-2024-00123  ·  VIP          │
├─────────────────────────────────────────────────────┤
│                                                     │
│  📍 Nərimanov, Ə.Salamzadə 45, m.12                │
│  📞 +994 50 XXX XX XX  ·  📧 resh@mail.az          │
│                                                     │
│  ─── ABUNƏ ───                                      │
│  Paket: Fiber 100 Mbps                             │
│  Status: Aktiv                                      │
│  Başlama: 12.03.2023 (3 il)                        │
│  Aylıq: 45 AZN                                      │
│  Növbəti ödəniş: 15.05.2026                        │
│  Balans: +12 AZN                                    │
│                                                     │
│  ─── AVADANLIQ ───                                  │
│  ONT: Huawei HG8145V5                              │
│  SN: 48575443XXXX                                  │
│  Signal: -21.5 dBm (baseline: -21.0) ✅            │
│  Uptime: 47 gün                                     │
│                                                     │
│  ─── MÜRACİƏTLƏR (8) ───                          │
│  ✅ 12.04.2026 — İnternet yavaş — Bağlandı (2 saat)│
│  ✅ 03.03.2026 — ONT offline — Bağlandı (45 dəq)   │
│  ✅ 15.02.2026 — Router problem — Bağlandı         │
│  ✅ 12.01.2026 — Fiber qırığı — Bağlandı (3 saat)  │
│  [Bütün tarixçəyə bax]                             │
│                                                     │
│  ─── GÖRÜLƏN İŞLƏR ───                             │
│  12.04.2026: Router restart, kanal dəyişdirildi    │
│  03.03.2026: ONT dəyişdirildi (yeni SN: ...)       │
│  15.02.2026: Müştəri tərəfində problem, izah edildi│
│  12.01.2026: Fiber splice, 20m kabel dəyişdirildi  │
│                                                     │
│  ─── ÖDƏNİŞ TARİXÇƏSİ ───                         │
│  ✅ 12.04.2026 — 45 AZN — Kart                     │
│  ✅ 12.03.2026 — 45 AZN — Nağd                     │
│  ⚠️ 15.02.2026 — 45 AZN — 3 gün gecikmə           │
│  ✅ 12.01.2026 — 45 AZN — Kart                     │
│                                                     │
│  ─── RİSK SKORU ───                                │
│  35/100 — Aşağı risk                                │
│  Səbəb: 3 ildir müştəridir, ödənişləri stabil      │
│                                                     │
│  [Zəng et]  [SMS göndər]  [Ticket yarat]           │
│                                                     │
└─────────────────────────────────────────────────────┘
13. TEXNİKİ KONUM XƏRİTƏSİ (Yalnız Admin/Region Manager)
text
┌─────────────────────────────────────────────────────┐
│  CANLI XƏRİTƏ — NƏRİMANOV ZONASI                  │
├─────────────────────────────────────────────────────┤
│                                                     │
│  [İnteraktiv xəritə]                                │
│                                                     │
│  🟢 Rəşad — Nərimanov mərkəz (aktiv ticket)        │
│  🟢 Aysel — Nərimanov şərq (yolda)                  │
│  🟡 Kamran — Nərimanov qərb (fasilə)               │
│  ⚪ Elvin — Offline (2 saat əvvəl)                 │
│                                                     │
│  ─── STATİSTİKA ───                                │
│  Aktiv texniki: 3/4                                 │
│  Orta cavab vaxtı: 22 dəq                           │
│  Ən uzaq texniki: 4.2 km                            │
│  Ən yaxın ticket: 500m (Rəşad)                     │
│                                                     │
│  [Yeni ticket yönləndir]  [Marşrut optimallaşdır]  │
│                                                     │
└─────────────────────────────────────────────────────┘
Texniki özü bu xəritəni görmür. O yalnız öz marşrutunu görür.

14. EKRANLARIN SİYAHISI
yaml
screens:
  auth:
    - login (phone OTP / username-password)
    - role_selection (əgər multi-role)
    - consent_screen (konum razılığı)
    - first_time_setup

  field_technician:
    - home_dashboard (portfolio + bugünkü ticketlər)
    - ticket_list (filter, axtar)
    - ticket_detail (semantic view)
    - ticket_close_form
    - customer_profile (portfolio-dan)
    - my_route (xəritə, yalnız öz)
    - my_bonus (real-time)
    - my_profile (karyera, statistika)
    - offline_queue (gözləyən əməliyyatlar)
    - notifications

  noc_engineer:
    - alarms_dashboard
    - device_list
    - diagnostics
    - network_map

  admin:
    - overview_dashboard
    - live_map (bütün texniklər)
    - tickets_management
    - customers_management
    - employees_management
    - bonus_rules
    - reports
    - audit_log

  super_admin:
    - all_admin_screens
    - system_config
    - role_management
    - vault_management
    - api_keys
15. NAVİQASİYA (Field Technician)
text
Bottom Navigation (5 tab):
┌──────┬──────┬──────┬──────┬──────┐
│  🏠  │  📋  │  👥  │  💰  │  👤  │
│ Ana  │Tick- │Müş-  │Bonus │Profil│
│      │ etlər│tərilər│     │      │
└──────┴──────┴──────┴──────┴──────┘
Ana — Bugünkü xülasə, prioritet ticketlər, portfolio

Ticketlər — Bütün ticketlər (filter: aktiv/bağlı)

Müştərilər — Portfolio, axtar, filter

Bonus — Real-time bonus tracker

Profil — Karyera, statistika, ayarlar

16. MİKRO-ANİMASİYALAR
yaml
animations:
  ticket_accept:
    - card pulse (green flash)
    - checkmark animation
    - haptic feedback (mobile)

  ticket_close:
    - success confetti (kiçik, 1 saniyə)
    - "+X AZN bonus" pop-up
    - sound (optional, işçi seçə bilər)

  status_change:
    - smooth transition (300ms)
    - color morph

  offline_to_online:
    - banner slide down
    - sync progress bar animasiya
    - complete: green flash

  bonus_increase:
    - counter animasiya (rəqəm artır)
    - coin sound (optional)

  notification:
    - slide in from top
    - auto-dismiss 5 saniyə
    - swipe to dismiss

  loading:
    - skeleton shimmer
    - NO spinner alone
17. ACCESSIBILITY (WCAG 2.1 AA)
yaml
accessibility:
  color_contrast: minimum 4.5:1 (text)
  touch_target: minimum 44x44px
  font_scaling: max 200%, layout qırılmamalı
  screen_reader: bütün komponentlər label-lı
  keyboard_navigation: bütün əməliyyatlar
  error_messages: screen reader üçün aydın
  focus_indicators: həmişə görünən
  animations: prefers-reduced-motion dəstəyi

  dark_mode: default (sahə üçün)
  light_mode: ofisdə seçilə bilər
18. PERFORMANS HƏDƏFLƏRİ
yaml
performance:
  initial_load: < 2 saniyə (3G-də)
  screen_transition: < 300ms
  ticket_accept: < 500ms (offline-da instant)
  photo_upload: arxa fonda
  list_scroll: 60fps
  cache: bütün statik data lokal
  bundle_size: < 5MB (mobile)
  memory_usage: < 150MB (mobile)
19. TEST VƏ QƏBUL MƏYARLARI
yaml
unit_tests:
  - semantic_ticket_formatting
  - portfolio_health_calculation
  - bonus_calculation
  - offline_queue_management
  - auto_resolve_logic
  - territory_routing
  - role_based_visibility

integration_tests:
  - offline_to_online_sync
  - ticket_close_form_validation
  - photo_upload_queue
  - GPS_tracking_lifecycle
  - customer_signature_persistence

ui_tests:
  - bütün ekranlar responsive
  - dark_mode bütün ekranlarda
  - touch_target_44px
  - contrast_4.5:1
  - screen_reader_labels

user_acceptance_tests:
  - field_technician_can_close_ticket_offline
  - admin_can_see_live_map
  - region_manager_cannot_see_other_region
  - technician_cannot_see_other_technician_location
  - auto_resolve_works_correctly
  - bonus_calculates_after_resolution
  - inventory_worker_never_gets_field_ticket

live_tests (staging):
  - real technician 1 həftə istifadə etsin
  - feedback topla
  - ən çox istifadə olunan ekranları analiz et
  - düşmə nöqtələrini (friction points) tap
20. TƏTBİQ MƏRHƏLƏLƏRİ
yaml
phase_1_design_system:
  duration: 1-2 həftə
  tasks:
    - design_tokens (rəng, tipoqrafiya, spacing)
    - component_library (button, card, badge, form)
    - dark_mode_default
    - Figma maketləri

phase_2_role_based_views:
  duration: 2-3 həftə
  tasks:
    - role_permission_matrix
    - field_technician_dashboard
    - admin_live_map
    - semantic_ticket_cards
    - navigation_restructure

phase_3_offline_improvements:
  duration: 2 həftə
  tasks:
    - offline_queue_ui
    - sync_progress_display
    - conflict_resolution
    - photo_upload_queue
    - auto_resolve_integration

phase_4_portfolio_bonus:
  duration: 2-3 həftə
  tasks:
    - portfolio_dashboard
    - customer_list_view
    - bonus_tracker
    - career_profile
    - proactive_contact_tools

phase_5_routing_auto:
  duration: 1-2 həftə
  tasks:
    - territory_based_routing
    - auto_resolve_engine
    - region_manager_role
    - live_map_for_admin
    - gps_privacy_controls

phase_6_polish:
  duration: 1 həftə
  tasks:
    - micro_animations
    - accessibility_audit
    - performance_optimization
    - user_testing
    - feedback_integration
21. FİNAL TƏLİMATLAR (Agent üçün)
yaml
final_instruction_to_agent:
  - əvvəl mövcud kodu oxu (bütün modulları anla)
  - mövcud işləyən modulu POZMA
  - böyük rewrite ETMƏ — kiçik patch et
  - hər patch-dən sonra test çalışdır
  - hər ekranı mobil-first dizayn et
  - dark mode default olsun
  - offline first — internet olmadan işləməlidir
  - hər ticket semantic formatda göstərilsin
  - rol əsaslı görünürlük mütləqdir
  - texnik konumu YALNIZ admin/region_manager görsün
  - bonus real vaxtda görünsün
  - portfolio hər texnikin özü üçün ayrı
  - auto-resolve engine mütləq işləsin
  - territory routing mütləq işləsin
  - inventar işçisinə HEÇ VAXT sahə ticketi getməsin
  - accessibility WCAG 2.1 AA
  - performance hədəflərə çat
  - hər dəyişiklik audit log-da
  - real cihazlarda read-only test et əvvəl
  - istifadəçi feedback-i topla
  - iterativ inkişaf — hər həftə yeni versiya
22. ƏN VACİB PRİNSİP
Bu app:

Texnikin gündəlik iş yoldaşıdır — sadə panel deyil

Offline işləyir — internet olsa da, olmasa da

İnsan dilində danışır — rəqəm deyil, hekayə

Sahiblik hissi verir — işçi "mənim müştərilərim" deyə bilir

Şəffaf bonus — real vaxtda görünür

Avtomatik həll — problem özü həll olsa, ticket özü bağlanır

Coğrafi routing — Bakı ticketi Gəncəyə getmir

Məxfilik qoruyur — konum yalnız admin görür

Sadə və gözəldir — işçi açmaq istəyir

Karyera yolu göstərir — "növbəti addım nədir?"

Bu 10 prinsip üzərində qurulan SuperOptic app sadəcə işlək deyil,
sevilən sistemə çevrilər. Texniklər onu açmaq istəyər, çünki öz
uğurlarını görər. Menecerlər sevər, çünki hər şey şəffafdır. Müştərilər
də sevər, çünki problem tez həll olunar.

23. KONKRET NƏTİCƏLƏR (KPI)
yaml
expected_improvements:
  technician_productivity: +30%
  ticket_resolution_time: -25%
  customer_retention: +15%
  technician_satisfaction: +40%
  offlne_work_completion: 100% (əvvəl 60%)
  wrong_territory_tickets: -95%
  auto_resolved_tickets: 20% (ümumi ticketdən)
  bonus_transparency_score: 9/10
  app_daily_active_users: +50%
  technician_turnover: -30%

business_impact:
  monthly_revenue_increase: +8-12%
  operational_cost_decrease: -15%
  customer_churn_decrease: -20%
  technician_overtime_decrease: -40%
SON SÖZ
Bu prompt SuperOptic MGS-in app qatını tam yenidən dizayn etmək üçün
hazırlanmışdır. Mövcud işlək sistemi pozmadan, onun üzərinə yeni UI/UX,
offline mode təkmilləşdirməsi, portfolio sahibliyi, bonus şəffaflığı,
auto-resolve mexanizmi və ərazi əsaslı routing əlavə edilir.

Əsas fəlsəfə: Texniki işçi "işçi" deyil — "kiçik sahibkar"dır.
App onun gündəlik iş yoldaşıdır. Bu fəlsəfə üzərində qurulan hər ekran,
hər düymə, hər rəng bu məqsədə xidmət etməlidir.