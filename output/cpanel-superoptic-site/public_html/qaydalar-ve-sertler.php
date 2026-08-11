<?php
declare(strict_types=1);

require __DIR__ . '/page-helpers.php';

$lang = app_lang();

$devices = [
    ['name' => ['az' => 'Huawei GPON modem', 'en' => 'Huawei GPON modem', 'ru' => 'Huawei GPON модем'], 'price' => 100],
    ['name' => ['az' => 'TP-Link dual-band router', 'en' => 'TP-Link dual-band router', 'ru' => 'TP-Link dual-band роутер'], 'price' => 90],
    ['name' => ['az' => 'HSGQ 2.4 GHz modem', 'en' => 'HSGQ 2.4 GHz modem', 'ru' => 'HSGQ 2.4 GHz модем'], 'price' => 65],
    ['name' => ['az' => 'HSGQ dual-band modem', 'en' => 'HSGQ dual-band modem', 'ru' => 'HSGQ dual-band модем'], 'price' => 90],
    ['name' => ['az' => 'BDCOM dual-band modem', 'en' => 'BDCOM dual-band modem', 'ru' => 'BDCOM dual-band модем'], 'price' => 90],
];

$copy = [
    'az' => [
        'title' => 'Qaydalar və şərtlər - Super Optik Tech',
        'description' => 'Super Optik Tech üzrə müqavilə, tarif, ödəniş, texniki dəstək, bərpa, dayandırma və avadanlıq qaydaları.',
        'eyebrow' => 'Hüquqi',
        'heading' => 'Qaydalar və şərtlər',
        'intro' => 'Aşağıdakı qaydalar Super Optik Tech tərəfindən təqdim olunan xidmətlərin istifadəsi, idarə olunması və hüquqi münasibətlərinin əsas çərçivəsini müəyyən edir.',
        'sections' => [
            [
                'title' => '1. Müqavilə tərəfinin dəyişdirilməsi',
                'items' => [
                    '1.1. Müqavilə tərəfinin dəyişdirilməsi yalnız Super Optik Tech-in cari abunəçisinin və yeni abunəçinin və ya onların səlahiyyətli nümayəndələrinin bu barədə əvvəlcədən verdiyi yazılı razılığı əsasında, Super Optik Tech-in müvafiq daxili prosedurlarına uyğun olaraq mümkündür.',
                ],
            ],
            [
                'title' => '2. Tarif dəyişikliyi',
                'items' => [
                    '2.1. Tarif dəyişikliyi üçün abunəçi və ya onun səlahiyyətli nümayəndəsi şəxsiyyətini təsdiq edən sənədlə Super Optik Tech-in xidmət mərkəzləri, əlaqə mərkəzi və ya dəstək kanalları vasitəsilə müraciət edə bilər və əməliyyatın icrası şirkətin daxili prosedurlarına uyğun olaraq mümkündür.',
                ],
            ],
            [
                'title' => '3. Ödəniş şərtləri',
                'items' => [
                    '3.1. Xidmət ilkin olaraq Abunəçi tərəfindən ödəniş həyata keçirildikdən sonra aktivləşdirilir və tarifdə müəyyən olunan xidmət haqqı müqabilində aylıq əsasla təmin edilir.',
                    '3.2. Xidmət haqqı ödənişi xidmət aktivləşdirildiyi gün əsasında hər növbəti ayın müvafiq günü həyata keçirilməlidir (Ödəniş günü).',
                    '3.3. Xidmət haqqı Ödəniş günündə ödənilmədikdə, 10 (on) təqvim günü ərzində xidmət məhdudlaşdırılmış sürətlə verilə bilər. Ödəniş bu güzəşt müddəti ərzində edilmədikdə xidmətin verilməsi dayandırılır.',
                    '3.4. Ödəniş güzəşt müddəti ərzində edilərsə, ödəniş günü dəyişməz olaraq qalır. Ödəniş güzəşt müddəti bitdikdən sonra həyata keçirildiyi halda isə ödəniş günü faktiki ödəniş tarixi əsasında müəyyən edilir və xidmətdən bir aylıq istifadə müddəti həmin tarixdən başlayır.',
                ],
            ],
            [
                'title' => '4. Ünvan dəyişikliyi',
                'items' => [
                    '4.1. Ünvan dəyişikliyi əməliyyatı ödənişsiz icra olunur.',
                    '4.2. Ünvan dəyişikliyi üçün növbəti ödəniş günündən ən azı 5 gün əvvəl abunəçi Super Optik Tech-in əlaqə mərkəzinə, xidmət mərkəzinə və ya rəsmi dəstək kanallarına müraciət edərək tələb olunan zəruri məlumatları təqdim etməlidir. Bu məlumatlara dəyişən ünvanlar, abunəçinin istifadəsində Super Optik Tech tərəfindən təqdim edilmiş son avadanlığın olub-olmaması və digər texniki detallar daxil ola bilər.',
                    '4.3. Əməliyyatın icrası Super Optik Tech-in daxili prosedurlarına və texniki imkanlarına uyğun olaraq həyata keçirilir.',
                    '4.4. Abunəçinin cari ünvanında Super Optik Tech tərəfindən təqdim edilmiş son avadanlıq istifadə olunursa və həmin avadanlıq yeni ünvanda da yararlıdırsa, abunəçi bu avadanlığı yeni ünvanda istifadə edə bilər.',
                ],
            ],
            [
                'title' => '5. Texniki dəstək',
                'items' => [
                    '5.1. Super Optik Tech abunəçinin müraciətinə əsasən xidmətdə yaranmış texniki problemlərin mümkün qısa müddət ərzində aradan qaldırılması üçün tədbirlər görür. Abunəçinin xidmətlərdən istifadəsi ilə bağlı yaranmış texniki çətinlik birbaşa Super Optik Tech-in fəaliyyəti nəticəsində yaranarsa, çağırış və tənzimləmə üçün ödəniş tələb olunmur.',
                    '5.2. Texniki dəstəyin göstərilməsi üçün istifadəçi Super Optik Tech-in əlaqə mərkəzi, xidmət mərkəzləri və ya rəsmi dəstək bölməsi vasitəsilə müraciət edə bilər.',
                    '5.3. Texniki dəstəyin göstərilməsinə dair sifariş qəbul olunduqdan sonra aparılan araşdırma nəticəsində aşkar edilsə ki, texniki problem abunəçinin səhvi nəticəsində baş verib, texniki heyətin hər çağırışı üçün xidmət haqqı 5 (beş) manat təşkil edir.',
                    '5.4. Abunəçinin kabel xəttində hər hansı səbəbdən qırılma və ya zədələnmə halları baş veribsə, xəttin bərpası üçün texniki heyətin hər çağırışı üçün xidmət haqqı 10 (on) manat təşkil edir.',
                    '5.5. Super Optik Tech tərəfindən abunəçinin istifadəsinə verilmiş son avadanlığın təmiri ödənişli qaydada Abunəçinin vəsaiti hesabına həyata keçirilir. Son avadanlığın təmiri mümkün olmadıqda, həmin avadanlıq yenisi ilə əvəz edilmir.',
                ],
            ],
            [
                'title' => '6. Dayandırılmış xidmətin abunəçinin müraciəti əsasında bərpası',
                'items' => [
                    '6.1. Bərpa əməliyyatı üçün abunəçi və ya onun səlahiyyətli nümayəndəsi şəxsiyyətini təsdiq edən sənədlə xidmət mərkəzləri, əlaqə mərkəzi və ya dəstək bölməsi vasitəsilə müraciət edə bilər.',
                    '6.2. Bərpa əməliyyatı üçün abunəçinin ünvanında texniki dəstəyin göstərilməsinə ehtiyac olmazsa, abunəçi tərəfindən yalnız abunə haqqı ödənildikdən sonra xidmət bərpa edilir.',
                    '6.3. Bərpa əməliyyatına dair müraciət qəbul olunduqdan sonra aparılan araşdırma nəticəsində aşkar edilsə ki, bərpa əməliyyatı üçün texniki heyət abunəçinin ünvanında müvafiq işlər aparmalıdır, bu halda əməliyyat Super Optik Tech-in cari qaydalarına və texniki imkanlarına uyğun olaraq icra edilir.',
                    '6.4. Əgər bərpa əməliyyatı abunəçinin ünvanında həyata keçirilirsə, o zaman abunəçi Super Optik Tech-in texniki heyətinə öz şəxsiyyətini təsdiq edən sənədi təqdim etməlidir.',
                    '6.5. Bərpa əməliyyatı bu barədə müraciət Super Optik Tech-ə daxil olduqdan sonra 2 (iki) iş günü ərzində həyata keçirilir.',
                ],
            ],
            [
                'title' => '7. Müvəqqəti dayandırma',
                'items' => [
                    '7.1. Abunəçi Super Optik Tech tərəfindən təqdim edilən xidmətlərdən istifadəni müvəqqəti olaraq dayandırmaq üçün şəxsiyyətini təsdiq edən sənədlə xidmət mərkəzləri, əlaqə mərkəzi və ya dəstək bölməsi vasitəsilə müraciət edə bilər.',
                    '7.2. Xidmətlərin müvəqqəti dayandırılma müddəti ən azı 1 (bir) ay, ən çoxu isə 4 ay müddəti üçün ola bilər.',
                    '7.3. Xidmətlərin müvəqqəti dayandırılması üçün haqq aylıq 1 (bir) manat təşkil edir.',
                ],
            ],
            [
                'title' => '8. Xidmətlərin ləğv edilməsi və avadanlıq qaydaları',
                'items' => [
                    '8.1. Müqaviləyə xitam verildikdə Super Optik Tech tərəfindən Abunəçinin istifadəsinə verilmiş avadanlıqlar, müqaviləyə xitam verilməsi tarixindən sonra 20 (iyirmi) təqvim günü ərzində Abunəçi tərəfindən Super Optik Tech-ə qaytarılmalıdır. Bu öhdəlik pozulduqda, həmin müddət bitdikdən sonrakı 5 (beş) təqvim günü ərzində cədvələ əsasən müəyyən edilmiş məbləğdə cərimə ödənilməlidir. Abunəçi bu öhdəliyi icra etmədikdə, Super Optik Tech qanunvericiliklə nəzərdə tutulmuş hüquqi tədbirlər görmək hüququna malikdir.',
                    '8.2. Huawei GPON modem, TP-Link dual-band router, HSGQ 2.4 GHz modem, HSGQ dual-band modem və BDCOM dual-band modem abunəçilərə istifadə və ya icarə əsasında təqdim oluna bilər. Müqavilə hər hansı səbəbdən ləğv olunarsa, təqdim edilmiş avadanlıq abunəçi tərəfindən geri qaytarılmalıdır. Avadanlıq geri qaytarılmadıqda və ya istifadəyə yararsız hala gətirildikdə, avadanlığın dəyəri aşağıdakı amortizasiya cədvəlinə uyğun olaraq Super Optik Tech-ə ödənilir.',
                ],
                'note' => 'Qeyd: Abunəçiyə istifadə müddəti ərzində müxtəlif səbəblərdən yenidən istifadə üçün avadanlıq təqdim olunarsa, müqaviləyə xitam verildiyi zaman amortizasiya cədvəlinə uyğun müəyyən edilmiş məbləğin 50 faizi tətbiq olunur. Bu müddət avadanlığın yenidən təqdim edildiyi tarixdən hesablanır.',
            ],
            [
                'title' => '9. Kampaniyalardan istifadə',
                'items' => [
                    '9.1. Abunəçi eyni zamanda yalnız bir kampaniyanın istifadəçisi ola bilər.',
                    '9.2. Super Optik Tech tərəfindən müvafiq kampaniyalar çərçivəsində və ya kommersiya strategiyasından irəli gələrək vaxtaşırı abunəçilərə təqdim olunan xüsusi tariflər bir qayda olaraq qoşulmadan sonrakı bir il və ya daha uzun müddət üçün təklif edilir. Bu müddətlərdən sonra münasibətlər həmin anda mövcud olan baza tarifinin şərtləri ilə tənzimlənir.',
                ],
            ],
        ],
        'table_title' => 'Avadanlıqlar üzrə amortizasiya cədvəli',
        'periods' => ['0-12 ay', '13-18 ay', '19-24 ay', '25-30 ay', '31-36 ay', '37-42 ay', '43-48 ay'],
        'device_label' => 'Cihazlar',
    ],
    'en' => [
        'title' => 'Terms and conditions - Super Optik Tech',
        'description' => 'Contract, tariff, payment, support, restoration, suspension and equipment rules of Super Optik Tech.',
        'eyebrow' => 'Legal',
        'heading' => 'Terms and conditions',
        'intro' => 'The following rules define the core framework for the use, management and legal treatment of services provided by Super Optik Tech.',
        'sections' => [
            ['title' => '1. Change of contract party', 'items' => ['1.1. A change of contract party is possible only on the basis of prior written consent from the current subscriber and the new subscriber, or their authorized representatives, and in accordance with the internal procedures of Super Optik Tech.']],
            ['title' => '2. Tariff change', 'items' => ['2.1. For a tariff change, the subscriber or an authorized representative may apply through service centers, the contact center or support channels with identity-confirming documents, and the operation is carried out in line with the internal procedures of Super Optik Tech.']],
            ['title' => '3. Payment terms', 'items' => ['3.1. The service is activated after payment by the Subscriber and is provided on a monthly basis for the service fee defined in the selected tariff.', '3.2. The service fee must be paid on the corresponding day of each following month based on the day the service was activated (Payment day).', '3.3. If the service fee is not paid on the Payment day, the service may be provided at limited speed for 10 calendar days. If payment is not made within this grace period, the service is suspended.', '3.4. If payment is made during the grace period, the payment day remains unchanged. If payment is made after the grace period, the payment day is set according to the actual payment date and the one-month period starts from that date.']],
            ['title' => '4. Address change', 'items' => ['4.1. Address change is carried out free of charge.', '4.2. For an address change, the subscriber must contact Super Optik Tech at least 5 days before the next payment day and provide all required information, including the old and new address and whether company-provided end equipment is in use.', '4.3. The operation is carried out according to internal procedures and technical feasibility.', '4.4. If company-provided end equipment used at the current address is also suitable for the new address, the subscriber may continue using it there.']],
            ['title' => '5. Technical support', 'items' => ['5.1. Super Optik Tech takes action to resolve technical issues within the shortest practical time after a subscriber request. If the issue arises directly from the company’s service or network, no charge is applied for the call-out and adjustment.', '5.2. The user may request technical support through the contact center, service centers or official support channels.', '5.3. If the investigation shows that the issue was caused by the subscriber, each call-out by the technical team is charged at 5 AZN.', '5.4. If the subscriber’s cable line is broken or physically damaged, each call-out for restoration is charged at 10 AZN.', '5.5. Repair of end equipment provided by Super Optik Tech is performed on a paid basis at the Subscriber’s expense. If repair is not possible, the end equipment is not automatically replaced with a new unit.']],
            ['title' => '6. Restoration of suspended service at the subscriber’s request', 'items' => ['6.1. For restoration, the subscriber or an authorized representative may apply through service centers, the contact center or support channels with identity-confirming documents.', '6.2. If no on-site technical support is required, the service is restored only after the subscription fee is paid.', '6.3. If the investigation shows that restoration requires work at the subscriber’s address, the operation is performed according to current technical and internal rules of Super Optik Tech.', '6.4. If the restoration is carried out at the subscriber’s address, the subscriber must present identity-confirming documents to the technical staff.', '6.5. Restoration is completed within 2 business days after the request is received by Super Optik Tech.']],
            ['title' => '7. Temporary suspension', 'items' => ['7.1. The subscriber may request temporary suspension of services through service centers, the contact center or support channels with identity-confirming documents.', '7.2. Temporary suspension may be applied for a minimum of 1 month and a maximum of 4 months.', '7.3. The fee for temporary suspension is 1 AZN per month.']],
            ['title' => '8. Service cancellation and equipment rules', 'items' => ['8.1. When the contract is terminated, equipment provided by Super Optik Tech must be returned within 20 calendar days after the termination date. If this obligation is breached, the subscriber must pay the amount defined in the amortization table within 5 calendar days after that period. Super Optik Tech may take legal measures to recover the amount if needed.', '8.2. Huawei GPON modem, TP-Link dual-band router, HSGQ 2.4 GHz modem, HSGQ dual-band modem and BDCOM dual-band modem may be provided for subscriber use or rental. If the contract is cancelled, the equipment must be returned. If it is not returned or is made unusable, the corresponding amount is paid according to the amortization schedule below.'], 'note' => 'Note: If equipment is re-issued to the subscriber during the usage period, 50 percent of the amortized amount applies upon termination. The period is calculated from the date of re-issue.'],
            ['title' => '9. Campaign usage', 'items' => ['9.1. A subscriber may only benefit from one campaign at a time.', '9.2. Special tariffs offered within campaigns or as part of commercial strategy are generally provided for one year or longer after connection. After those periods, the relationship is governed by the then-current base tariff terms.']],
        ],
        'table_title' => 'Equipment amortization schedule',
        'periods' => ['0-12 months', '13-18 months', '19-24 months', '25-30 months', '31-36 months', '37-42 months', '43-48 months'],
        'device_label' => 'Devices',
    ],
    'ru' => [
        'title' => 'Правила и условия - Super Optik Tech',
        'description' => 'Правила Super Optik Tech по договору, тарифам, оплате, поддержке, восстановлению, приостановке и оборудованию.',
        'eyebrow' => 'Юридическое',
        'heading' => 'Правила и условия',
        'intro' => 'Ниже приведены основные правила, определяющие порядок использования услуг Super Optik Tech, а также ключевые юридические условия.',
        'sections' => [
            ['title' => '1. Изменение стороны договора', 'items' => ['1.1. Изменение стороны договора возможно только на основании предварительного письменного согласия текущего абонента и нового абонента либо их уполномоченных представителей и в соответствии с внутренними процедурами Super Optik Tech.']],
            ['title' => '2. Изменение тарифа', 'items' => ['2.1. Для изменения тарифа абонент или его уполномоченный представитель может обратиться в сервисные центры, контактный центр или каналы поддержки, предоставив документы, подтверждающие личность. Операция выполняется в соответствии с внутренними процедурами Super Optik Tech.']],
            ['title' => '3. Условия оплаты', 'items' => ['3.1. Услуга активируется после оплаты Абонентом и предоставляется на ежемесячной основе за плату, установленную тарифом.', '3.2. Оплата должна производиться в соответствующий день каждого следующего месяца, исходя из даты активации услуги (день оплаты).', '3.3. Если оплата не произведена в день оплаты, услуга может предоставляться на ограниченной скорости в течение 10 календарных дней. Если в течение этого льготного периода оплата не поступает, оказание услуги приостанавливается.', '3.4. Если оплата произведена в течение льготного периода, день оплаты не меняется. Если оплата произведена после льготного периода, днем оплаты считается фактическая дата платежа, и месячный период начинается с этой даты.']],
            ['title' => '4. Смена адреса', 'items' => ['4.1. Смена адреса выполняется бесплатно.', '4.2. Для смены адреса абонент должен обратиться в Super Optik Tech не позднее чем за 5 дней до следующего дня оплаты и предоставить необходимые сведения, включая старый и новый адрес, а также информацию о выданном оборудовании.', '4.3. Операция выполняется в соответствии с внутренними процедурами и технической возможностью.', '4.4. Если выданное компанией конечное оборудование подходит и для нового адреса, абонент может продолжить его использование.']],
            ['title' => '5. Техническая поддержка', 'items' => ['5.1. Super Optik Tech принимает меры для устранения технических неисправностей в максимально короткий практический срок после обращения абонента. Если проблема возникла непосредственно по вине сети или услуги компании, плата за выезд и настройку не взимается.', '5.2. Пользователь может обратиться за технической поддержкой через контактный центр, сервисные центры или официальные каналы поддержки.', '5.3. Если по результатам проверки установлено, что проблема возникла по вине абонента, каждый выезд технической команды оплачивается в размере 5 AZN.', '5.4. При обрыве или физическом повреждении кабельной линии абонента каждый выезд для восстановления оплачивается в размере 10 AZN.', '5.5. Ремонт конечного оборудования, предоставленного Super Optik Tech, осуществляется на платной основе за счет Абонента. Если ремонт невозможен, оборудование автоматически не заменяется новым.']],
            ['title' => '6. Восстановление приостановленной услуги по заявлению абонента', 'items' => ['6.1. Для восстановления абонент или его уполномоченный представитель может обратиться через сервисные центры, контактный центр или каналы поддержки, предоставив документы, подтверждающие личность.', '6.2. Если выезд технической команды не требуется, услуга восстанавливается только после оплаты абонентской суммы.', '6.3. Если по результатам проверки выясняется, что восстановление требует работ по адресу абонента, операция выполняется в соответствии с действующими техническими и внутренними правилами Super Optik Tech.', '6.4. Если восстановление осуществляется по адресу абонента, он обязан предъявить техническому персоналу документ, удостоверяющий личность.', '6.5. Восстановление выполняется в течение 2 рабочих дней после поступления обращения в Super Optik Tech.']],
            ['title' => '7. Временная приостановка', 'items' => ['7.1. Абонент может запросить временную приостановку услуг через сервисные центры, контактный центр или каналы поддержки с документами, удостоверяющими личность.', '7.2. Срок временной приостановки составляет не менее 1 месяца и не более 4 месяцев.', '7.3. Плата за временную приостановку составляет 1 AZN в месяц.']],
            ['title' => '8. Прекращение услуг и правила по оборудованию', 'items' => ['8.1. При расторжении договора оборудование, предоставленное Super Optik Tech, должно быть возвращено в течение 20 календарных дней после даты расторжения. При нарушении этого обязательства абонент обязан выплатить сумму по таблице амортизации в течение следующих 5 календарных дней. Super Optik Tech вправе принять предусмотренные законом меры для взыскания суммы.', '8.2. Huawei GPON модем, TP-Link dual-band роутер, HSGQ 2.4 GHz модем, HSGQ dual-band модем и BDCOM dual-band модем могут предоставляться для использования или аренды. При прекращении договора оборудование должно быть возвращено. Если оно не возвращено либо приведено в негодность, выплачивается сумма согласно таблице амортизации ниже.'], 'note' => 'Примечание: если в течение срока использования оборудование было повторно выдано абоненту, при расторжении применяется 50 процентов от суммы по амортизации. Срок считается с даты повторной выдачи.'],
            ['title' => '9. Использование кампаний', 'items' => ['9.1. Абонент может одновременно пользоваться только одной активной кампанией.', '9.2. Специальные тарифы, предоставляемые в рамках кампаний или коммерческой стратегии, как правило, действуют в течение одного года или более после подключения. По истечении этого срока отношения регулируются условиями действующего базового тарифа.']],
        ],
        'table_title' => 'Таблица амортизации оборудования',
        'periods' => ['0-12 мес.', '13-18 мес.', '19-24 мес.', '25-30 мес.', '31-36 мес.', '37-42 мес.', '43-48 мес.'],
        'device_label' => 'Устройства',
    ],
];

$page = $copy[$lang] ?? $copy['az'];

page_render_header($lang, $page['title'], $page['description'], 'legal');
?>
<main>
    <section class="section inner-page-hero">
        <div class="container inner-page-hero-content">
            <span class="eyebrow"><?= e($page['eyebrow']); ?></span>
            <h1><?= e($page['heading']); ?></h1>
            <p><?= e($page['intro']); ?></p>
        </div>
    </section>

    <section class="section">
        <div class="container legal-layout">
            <?php foreach ($page['sections'] as $section): ?>
                <article class="policy-card">
                    <h2><?= e($section['title']); ?></h2>
                    <ul class="policy-list">
                        <?php foreach ($section['items'] as $item): ?>
                            <li><?= e($item); ?></li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if (!empty($section['note'])): ?>
                        <p class="policy-note"><?= e($section['note']); ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>

            <article class="policy-card">
                <h2><?= e($page['table_title']); ?></h2>
                <div class="policy-table-wrap">
                    <table class="policy-table">
                        <thead>
                            <tr>
                                <th><?= e($page['device_label']); ?></th>
                                <?php foreach ($page['periods'] as $period): ?>
                                    <th><?= e($period); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($devices as $device): ?>
                                <tr>
                                    <td><?= e($device['name'][$lang] ?? $device['name']['az']); ?></td>
                                    <?php foreach (page_device_schedule($device['price']) as $value): ?>
                                        <td><?= e((string) $value); ?> AZN</td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </section>
</main>
<?php page_render_footer($lang); ?>
