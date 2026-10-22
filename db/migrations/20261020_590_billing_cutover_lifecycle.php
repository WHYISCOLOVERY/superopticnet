<?php
declare(strict_types=1);

const APP_BILLING_CUTOVER_LIFECYCLE_MIGRATION_20261020_590 = '20261020_590_billing_cutover_lifecycle';

return [
    'version' => APP_BILLING_CUTOVER_LIFECYCLE_MIGRATION_20261020_590,
    'description' => 'Use package-specific activation days in customer billing notifications.',
    'up' => static function (PDO $db): void {
        $statement = $db->prepare(
            'UPDATE communication_templates
                SET title = :title, body = :body, updated_at = NOW()
              WHERE code = "service_activated_30d"'
        );
        $statement->execute([
            'title' => 'Xidmet aktiv edildi',
            'body' => 'Hormetli {full_name}, {customer_code} nomreli xidmetiniz {days_added} gun ucun aktiv edildi. Bitme tarixi: {expires_at}.',
        ]);
    },
    'down' => static function (PDO $db): void {
        $statement = $db->prepare(
            'UPDATE communication_templates
                SET title = :title, body = :body, updated_at = NOW()
              WHERE code = "service_activated_30d"'
        );
        $statement->execute([
            'title' => 'Xidmet 30 gun aktiv edildi',
            'body' => 'Hormetli {full_name}, {customer_code} nomreli xidmetiniz novbeti 30 gun ucun aktiv edildi. Bitme tarixi: {expires_at}.',
        ]);
    },
];
