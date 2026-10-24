<?php
declare(strict_types=1);

/**
 * Return only operational alarms that have passed the existing NOC ticket/
 * correlation layer. Raw probes, smoke rows and already closed incidents do
 * not belong in the mobile action feed.
 */
function app_mobile_actionable_alarm_feed(PDO $db, int $limit = 40): array
{
    $limit = max(1, min($limit, 100));
    $rows = $db->query(
        "SELECT a.id, a.alarm_type, a.severity, a.source, a.object_type, a.object_id,
                a.title, a.status, a.ticket_no, a.last_seen_at, a.seen_count, a.payload_json,
                t.status AS ticket_status
           FROM mgs_alarm_events a
           LEFT JOIN portal_tickets t ON t.ticket_no = a.ticket_no
          WHERE a.status IN ('open','ack')
            AND a.source NOT LIKE '%smoke%'
            AND a.object_id NOT LIKE '%SMOKE%'
            AND a.title NOT LIKE '%smoke%'
            AND a.last_seen_at >= (NOW() - INTERVAL 30 MINUTE)
            AND (a.ticket_no IS NOT NULL OR a.source = 'olt_ticket_rule_engine')
            AND (a.ticket_no IS NULL OR t.id IS NULL OR t.status NOT IN ('closed','resolved','Baglandi','Tesdiqlendi'))
          ORDER BY FIELD(a.status, 'open','ack'),
                   FIELD(a.severity, 'emergency','critical','warning','info'),
                   a.last_seen_at DESC
          LIMIT {$limit}"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $feed = [];
    foreach ($rows as $row) {
        $feed[] = app_mobile_explain_alarm($row);
    }
    return $feed;
}

function app_mobile_explain_alarm(array $row): array
{
    $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
    $payload = is_array($payload) ? $payload : [];
    $raw = is_array($payload['raw'] ?? null) ? $payload['raw'] : [];
    $alert = is_array($payload['alert'] ?? null) ? $payload['alert'] : $payload;

    $type = strtoupper((string) ($row['alarm_type'] ?? 'NETWORK_EVENT'));
    $rawTitle = trim((string) ($raw['title'] ?? $row['title'] ?? ''));
    $titleDevice = trim((string) preg_replace('/\s+offline$/iu', '', $rawTitle));
    $device = trim((string) ($alert['device_name'] ?? $raw['device_name'] ?? ''));
    if ($device === '') {
        $device = $titleDevice !== '' ? $titleDevice : trim((string) ($row['object_id'] ?? 'Şəbəkə avadanlığı'));
    }
    $ip = trim((string) ($alert['device_ip'] ?? $raw['device_ip'] ?? ''));
    $pon = trim((string) ($alert['pon'] ?? $alert['interface_name'] ?? $raw['interface_name'] ?? ''));
    $area = trim((string) ($alert['area'] ?? $raw['area'] ?? ''));
    $affected = (int) ($alert['issue_count'] ?? $raw['affected_count'] ?? 0);
    $ticketNo = trim((string) ($row['ticket_no'] ?? $alert['ticket_no'] ?? ''));

    $templates = [
        'DEVICE_OFFLINE' => [
            'group' => 'Əlaqə kəsintisi',
            'title' => "{$device} ilə əlaqə kəsilib" . ($ip !== '' ? " ({$ip})" : ''),
            'impact' => 'Bu avadanlıqdan asılı xidmətlər və abunəçilər təsirlənə bilər.',
            'cause' => 'Enerji, uplink, idarəetmə VLAN-ı və ya SNMP/SSH rabitəsi kəsilmiş ola bilər.',
            'action' => 'Əvvəl enerji və uplink-i, sonra idarəetmə yolu və cihazın əlçatanlığını yoxlayın.',
        ],
        'PON_MASS_OPTICAL_DEGRADATION' => [
            'group' => 'Kütləvi PON problemi',
            'title' => "{$device}" . ($pon !== '' ? " / {$pon}" : '') . ' üzrə kütləvi optik problem',
            'impact' => ($affected > 0 ? "{$affected} abunəçinin" : 'Bir neçə abunəçinin') . ' siqnalı eyni anda normadan çıxıb.',
            'cause' => 'PON portu, splitter, feeder kabeli, kabinet enerjisi və ya ümumi optik xətt səbəb ola bilər.',
            'action' => 'Ayrı-ayrı müştəri bileti açmayın; PON xəttini vahid hadisə kimi yoxlayın.',
        ],
        'ONT_OPTICAL_DEGRADATION' => [
            'group' => 'Fərdi optik problem',
            'title' => "{$device}" . ($pon !== '' ? " / {$pon}" : '') . ' üzrə ONT siqnalı normadan çıxıb',
            'impact' => 'Bu abonentin interneti qeyri-sabit işləyə və ya kəsilə bilər.',
            'cause' => 'Drop kabel, konnektor, splitter itkisi, ONT lazeri və ya cihazın temperaturu səbəb ola bilər.',
            'action' => 'Biletdəki SN və ölçülərlə yerində optik xətti yoxlayın.',
        ],
        'PON_DOWN' => [
            'group' => 'PON kəsintisi',
            'title' => "{$device}" . ($pon !== '' ? " / {$pon}" : '') . ' PON portu dayanıb',
            'impact' => ($affected > 0 ? "{$affected} abunəçi" : 'Bu PON-a bağlı abunəçilər') . ' əlaqəni itirib.',
            'cause' => 'OLT portu, uplink, optik modul, splitter və ya enerji problemi ola bilər.',
            'action' => 'OLT port statusunu, optik modulu və feeder xəttini yoxlayın.',
        ],
        'PON_HIGH_LOAD' => [
            'group' => 'Şəbəkə yüklənməsi',
            'title' => "{$device}" . ($pon !== '' ? " / {$pon}" : '') . ' üzərində yüksək yük var',
            'impact' => 'Pik saatlarda sürət azalması və gecikmə yarana bilər.',
            'cause' => 'Port tutumu abunəçi trafikini daşımaq üçün yetərli olmaya bilər.',
            'action' => 'Cari və pik trafiki tutumla müqayisə edin; bölünmə və ya uplink artırılması planlayın.',
        ],
        'ONT_HIGH_TEMP' => [
            'group' => 'Müştəri avadanlığı',
            'title' => "{$device} modemində yüksək temperatur", 
            'impact' => 'Modem qeyri-sabit işləyə və bağlantı kəsilə bilər.',
            'cause' => 'Havalandırma zəifdir, cihaz qapalı sahədədir və ya enerji bloku nasazdır.',
            'action' => 'Müştəri ilə yerləşməni yoxlayın; davam edərsə cihaz baxışı üçün iş yaradın.',
        ],
        'ONT_LOW_RX' => [
            'group' => 'Müştəri optik siqnalı',
            'title' => "{$device} üçün RX siqnalı zəifdir",
            'impact' => 'Bağlantı zəifləyə və periodik kəsintilər yarana bilər.',
            'cause' => 'Kabel əyilməsi, çirkli konnektor, zəif qaynaq və ya splitter itkisi ola bilər.',
            'action' => 'RX dəyərini ölçün, konnektor və optik xətti yoxlayın.',
        ],
        'ONT_TX_OUT_OF_RANGE' => [
            'group' => 'Müştəri optik siqnalı',
            'title' => "{$device} üçün TX siqnalı normadan çıxıb",
            'impact' => 'ONT-dən OLT-yə ötürmə qeyri-sabit ola bilər.',
            'cause' => 'ONT lazeri, enerji bloku və ya optik xətt nasazlığı ola bilər.',
            'action' => 'TX/RX dəyərlərini birlikdə yoxlayın; lazım gələrsə ONT-ni dəyişin.',
        ],
        'DEVICE_HIGH_CPU' => [
            'group' => 'Core tutum problemi',
            'title' => "{$device} prosessor yükü yüksəkdir",
            'impact' => 'Marşrutlaşdırma, idarəetmə və xidmət cavabları gecikə bilər.',
            'cause' => 'Trafik sıçrayışı, routing dəyişməsi və ya idarəetmə prosesi yük yarada bilər.',
            'action' => 'İnterfeys trafikini və prosesləri yoxlayın; səbəbi təsdiqləmədən restart etməyin.',
        ],
        'DEVICE_HIGH_MEMORY' => [
            'group' => 'Core tutum problemi',
            'title' => "{$device} yaddaş istifadəsi yüksəkdir",
            'impact' => 'Cihaz sabitliyi və idarəetmə funksiyaları risk altındadır.',
            'cause' => 'Uzunmüddətli proses, route cədvəli və ya proqram xətası yaddaşı doldura bilər.',
            'action' => 'Yaddaş bölgüsünü və prosesləri yoxlayın, dəyişiklik tarixçəsi ilə müqayisə edin.',
        ],
        'CORE_INTERFACE_BOTTLENECK' => [
            'group' => 'Core dar boğazı',
            'title' => "{$device}" . ($pon !== '' ? " / {$pon}" : '') . ' üzərində davamlı yüksək trafik',
            'impact' => 'İnternet sürəti, gecikmə və paket itkisi üzrə geniş təsir yarana bilər.',
            'cause' => 'İnterfeys yükü son ölçmələrdə tutumun təhlükəli həddini keçib.',
            'action' => 'Trafik istiqamətini və top talker-ləri yoxlayın; balanslama və ya uplink tutum artımını planlayın.',
        ],
    ];

    $template = $templates[$type] ?? [
        'group' => 'Şəbəkə hadisəsi',
        'title' => trim((string) ($row['title'] ?? $device)),
        'impact' => trim((string) ($alert['human_problem'] ?? $raw['description'] ?? 'Xidmətə təsir ehtimalı var.')),
        'cause' => 'Səbəb telemetriya və asılı avadanlıqlar yoxlanaraq dəqiqləşdirilməlidir.',
        'action' => trim((string) ($alert['recommendation'] ?? 'Əlaqəli avadanlıq və son dəyişiklikləri yoxlayın.')),
    ];

    $visibleTicketNo = str_starts_with($ticketNo, 'LEGACY-') ? '' : $ticketNo;
    $details = array_values(array_filter([
        $area !== '' ? 'Ərazi: ' . $area : '',
        'Təsir: ' . $template['impact'],
        'Ehtimal olunan səbəb: ' . $template['cause'],
        'Addım: ' . $template['action'],
        $visibleTicketNo !== '' ? 'Bilet: ' . $visibleTicketNo : '',
    ]));

    return [
        'id' => 'alarm-' . (int) ($row['id'] ?? 0),
        'kind' => 'network_incident',
        'group' => $template['group'],
        'severity' => (string) ($row['severity'] ?? 'warning'),
        'title' => $template['title'],
        'message' => implode("\n", $details),
        'status' => (string) ($row['status'] ?? 'open'),
        'ticket_no' => $ticketNo,
        'has_ticket' => $ticketNo !== '',
        'affected_count' => $affected,
        'device_name' => $device,
        'device_ip' => $ip,
        'pon' => $pon,
        'occurred_at' => (string) ($row['last_seen_at'] ?? ''),
        'seen_count' => (int) ($row['seen_count'] ?? 1),
    ];
}
