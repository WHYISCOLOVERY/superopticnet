<?php

return [
    // Copy this file for non-secret settings only. Put every credential in the OS/service environment.
    'app_environment' => 'production',
    'app_demo_fixtures_enabled' => '0',
    'app_url' => 'https://billing.superoptic.net',
    'public_site_url' => 'https://superoptic.net/',
    'ticket_close_base_url' => 'https://billing.superoptic.net',
    'billing_mode' => 'mysql',
    'portal_storage_driver' => 'mysql',
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'superoptic_portal',
    'db_user' => 'superoptic_app',
    'db_charset' => 'utf8mb4',

    'radius_sql_host' => '127.0.0.1',
    'radius_sql_port' => '3306',
    'radius_sql_db' => 'superoptic_portal',
    'radius_sql_user' => 'radius',
    'radius_core_nas_ip' => '',
    // Provider credentials are secrets and belong in the service environment.
    // Keep separate allowlists after Emanat and United Payment confirm their egress IPs.
    'provider_api_allow_legacy_shared_credentials' => '0',
    'provider_api_require_hmac' => '1',
    'emanat_provider_allowed_ips' => '',
    'unitedpayment_provider_allowed_ips' => '',
    'telegram_chat_id' => 'CHANGE_ME_TELEGRAM_CHAT_ID',
    'sms_provider_endpoint' => 'https://api.poctgoyercini.com/gateway/api/sms/v1/message/send',
    'sms_provider_driver' => 'poctgoyercini_json',
    'sms_provider_sender' => '',
    'whatsapp_provider_enabled' => '0',
    'whatsapp_provider_endpoint' => '',
    'whatsapp_provider_sender' => 'SuperOptic',

    'billing_customer_url' => 'https://billing.superoptic.net/portal-login.php',
    'billing_employee_url' => 'https://billing.superoptic.net/staff-login.php',
    'billing_sso_entrypoint' => '',
    'billing_sso_issuer' => 'mgs.superoptic.net',
    'billing_sso_audience' => 'billing.superoptic.net',
    'billing_sso_default_return' => '/',

    'mygov_enabled' => '0',
    'mygov_mock_enabled' => '0',
    'mygov_client_id' => '',
    'mygov_authorize_url' => '',
    'mygov_token_url' => '',
    'mygov_userinfo_url' => '',
    'mygov_redirect_uri' => 'https://mgs.superoptic.net/mygov-auth.php?callback=1',
    'mygov_scope' => 'openid profile fin',

    'portal_token_issuer' => 'mgs.superoptic.net',
    'portal_ticket_close_audience' => 'portal.ticket.close',

    'olt_sso_entrypoint' => 'https://mgs.superoptic.net/api/olt-sso/consume',
    'olt_user_sync_endpoint' => '',
    'olt_sso_issuer' => 'mgs.superoptic.net',
    'olt_sso_audience' => 'superoptic-mgs',
    'olt_sso_default_return' => '/',

    'payment_url' => 'https://www.million.az/services/internet/Super%20Optik',
];
