<?php

return [
    'customers' => [
        'SO-100245' => [
            'id' => 'cust-100245',
            'password' => 'Demo1234',
            'name' => 'Rasim Memmedov',
            'subscriber_code' => 'SO-100245',
            'category' => 'Ferdi musteri',
            'dashboard' => [
                'profile' => [
                    'full_name' => 'Rasim Memmedov',
                    'category' => 'Ferdi musteri',
                    'subscriber_code' => 'SO-100245',
                    'contract_no' => 'CN-24-5512',
                    'plan' => '500 Mb/s Internet + TV',
                    'status' => 'Aktiv',
                    'area' => 'Zabrat',
                    'address' => 'Baku, Sabunchu rayonu, Zabrat qesebesi',
                    'email' => 'rasim@example.com',
                    'phone' => '+994501112233',
                ],
                'traffic' => [
                    'download_speed' => '500 Mb/s',
                    'upload_speed' => '250 Mb/s',
                    'period' => 'Aprel 2026',
                    'download_used' => '684 GB',
                    'upload_used' => '148 GB',
                    'session_uptime' => '19 gun 06 saat',
                    'last_online' => '22.04.2026 10:35',
                ],
                'payments' => [
                    ['date' => '05.04.2026', 'amount' => '46 AZN', 'method' => 'Online odeme', 'status' => 'Tesdiqlendi'],
                    ['date' => '05.03.2026', 'amount' => '46 AZN', 'method' => 'MilliOn', 'status' => 'Tesdiqlendi'],
                    ['date' => '05.02.2026', 'amount' => '46 AZN', 'method' => 'Terminal', 'status' => 'Tesdiqlendi'],
                ],
                'statements' => [
                    ['period' => 'Aprel 2026', 'charge' => '46 AZN', 'paid' => '46 AZN', 'balance' => '0 AZN'],
                    ['period' => 'Mart 2026', 'charge' => '46 AZN', 'paid' => '46 AZN', 'balance' => '0 AZN'],
                    ['period' => 'Fevral 2026', 'charge' => '46 AZN', 'paid' => '46 AZN', 'balance' => '0 AZN'],
                ],
                'tickets' => [
                    ['id' => 'REQ-9182', 'subject' => 'Wi-Fi suret testi', 'status' => 'Baglandi', 'updated_at' => '18.04.2026 14:20', 'message' => 'Modem yeniden basladildiqdan sonra suret normallasdi.'],
                    ['id' => 'REQ-9051', 'subject' => 'TV Box aktivlesdirme', 'status' => 'Gozlenilir', 'updated_at' => '21.04.2026 09:10', 'message' => 'TV xidmeti aktivlesdirilmesi ucun geri donus gozlenilir.'],
                ],
            ],
        ],
        'SO-220781' => [
            'id' => 'cust-220781',
            'password' => 'Demo1234',
            'name' => 'Aysel Huseynova',
            'subscriber_code' => 'SO-220781',
            'category' => 'Biznes musteri',
            'dashboard' => [
                'profile' => [
                    'full_name' => 'Aysel Huseynova',
                    'category' => 'Biznes musteri',
                    'subscriber_code' => 'SO-220781',
                    'contract_no' => 'CN-24-9911',
                    'plan' => '1000 Mb/s Biznes interneti',
                    'status' => 'Aktiv',
                    'area' => 'Narimanov',
                    'address' => 'Baku, Narimanov rayonu, ofis zonasi',
                    'email' => 'office@example.com',
                    'phone' => '+994551234567',
                ],
                'traffic' => [
                    'download_speed' => '1000 Mb/s',
                    'upload_speed' => '1000 Mb/s',
                    'period' => 'Aprel 2026',
                    'download_used' => '2.8 TB',
                    'upload_used' => '1.4 TB',
                    'session_uptime' => '31 gun 00 saat',
                    'last_online' => '22.04.2026 10:35',
                ],
                'payments' => [
                    ['date' => '01.04.2026', 'amount' => '120 AZN', 'method' => 'Kocurme', 'status' => 'Tesdiqlendi'],
                    ['date' => '01.03.2026', 'amount' => '120 AZN', 'method' => 'Kocurme', 'status' => 'Tesdiqlendi']
                ],
                'statements' => [
                    ['period' => 'Aprel 2026', 'charge' => '120 AZN', 'paid' => '120 AZN', 'balance' => '0 AZN'],
                    ['period' => 'Mart 2026', 'charge' => '120 AZN', 'paid' => '120 AZN', 'balance' => '0 AZN'],
                ],
                'tickets' => [
                    ['id' => 'REQ-9934', 'subject' => 'Static IP sorgusu', 'status' => 'Icrada', 'updated_at' => '22.04.2026 08:45', 'message' => 'Yeni static IP telabi emelda saxlanilir.'],
                ],
            ],
        ],
    ],
    'employees' => [
        'support.agent' => [
            'id' => 'emp-001',
            'password' => 'Support123',
            'name' => 'Nicat Support',
            'employee_role' => 'support',
            'dashboard' => [
                'summary' => [
                    'role_title' => 'Texniki destek emekdasi',
                    'billing_access' => 'Aktiv',
                    'shift' => 'Gun novbesi',
                    'open_queue' => '18 aciq muraciet',
                ],
                'permissions' => [
                    'Abonent profiline baxis',
                    'Muraciet qeydlerinin acilmasi',
                    'Nomre deyisikliyi ve ilkin yoxlama',
                ],
            ],
        ],
        'noc.admin' => [
            'id' => 'emp-002',
            'password' => 'NocAdmin123',
            'name' => 'Kamran NOC',
            'employee_role' => 'noc',
            'dashboard' => [
                'summary' => [
                    'role_title' => 'NOC ve OLT idareetme',
                    'billing_access' => 'Aktiv',
                    'shift' => '24/7 monitorinq',
                    'open_queue' => '4 kritik cihaz',
                ],
                'permissions' => [
                    'OLT ve ONU idareetmesi',
                    'Provisioning ve profil deyisikliyi',
                    'Port ve signal nezareti',
                ],
            ],
        ],
    ],
    'technicians' => [
        [
            'id' => 'tech-001',
            'name' => 'Orxan Efselov',
            'areas' => ['Astara', 'Masalli', 'Lenkeran', 'Neftcala'],
        ],
        [
            'id' => 'tech-002',
            'name' => 'Nurlan Huseynli',
            'areas' => ['Qarachuxur', 'Ehmedli', 'Guneshli', 'Xetai', 'Bakixanov'],
        ],
        [
            'id' => 'tech-003',
            'name' => 'Elvin Memmedov',
            'areas' => ['Sabunchu', 'Zabrat', 'Pirsagi', 'Kurdexani', 'BinEqedi', 'Mehdiabad', 'Memmedli'],
        ],
        [
            'id' => 'tech-004',
            'name' => 'Kamran Eliyev',
            'areas' => ['Absheron', 'Nizami', 'Suraxani', 'Bileceri', 'Ismayilli', 'Narimanov'],
        ],
    ],
];
