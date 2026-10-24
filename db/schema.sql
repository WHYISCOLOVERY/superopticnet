CREATE DATABASE IF NOT EXISTS `superoptic_portal` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `superoptic_portal`;

CREATE TABLE IF NOT EXISTS portal_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(64) DEFAULT NULL,
    login VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL,
    account_type VARCHAR(32) NOT NULL DEFAULT 'customer',
    employee_role VARCHAR(64) NOT NULL DEFAULT '',
    full_name VARCHAR(191) NOT NULL,
    subscriber_code VARCHAR(64) DEFAULT NULL,
    contract_no VARCHAR(64) DEFAULT NULL,
    email VARCHAR(191) DEFAULT NULL,
    phone VARCHAR(32) DEFAULT NULL,
    category VARCHAR(128) DEFAULT NULL,
    area VARCHAR(128) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    plan VARCHAR(191) DEFAULT NULL,
    status VARCHAR(64) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    payload_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portal_accounts_login (login),
    UNIQUE KEY uq_portal_accounts_subscriber_code (subscriber_code),
    KEY idx_portal_accounts_role_type (role, account_type),
    KEY idx_portal_accounts_area (area),
    KEY idx_portal_accounts_contract (contract_no),
    KEY idx_portal_accounts_phone (phone),
    KEY idx_portal_accounts_name (full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_account_areas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    area VARCHAR(128) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portal_account_area (account_id, area),
    KEY idx_portal_account_area_area (area),
    CONSTRAINT fk_portal_account_areas_account
        FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_password_recovery_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(32) NOT NULL DEFAULT 'whatsapp',
    token_hash CHAR(64) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    destination_hash CHAR(64) NOT NULL,
    requested_ip_hash CHAR(64) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    invalidated_at DATETIME DEFAULT NULL,
    created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
    metadata_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portal_password_recovery_token (token_hash),
    KEY idx_portal_password_recovery_account (account_id, created_at),
    KEY idx_portal_password_recovery_expiry (expires_at, consumed_at, invalidated_at),
    CONSTRAINT fk_portal_password_recovery_account
        FOREIGN KEY (account_id) REFERENCES portal_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_portal_password_recovery_actor
        FOREIGN KEY (created_by_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_no VARCHAR(32) NOT NULL,
    customer_account_id BIGINT UNSIGNED DEFAULT NULL,
    subscriber_code VARCHAR(64) DEFAULT NULL,
    contract_no VARCHAR(64) DEFAULT NULL,
    customer_name VARCHAR(191) NOT NULL,
    phone VARCHAR(32) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    area VARCHAR(128) DEFAULT NULL,
    subject VARCHAR(128) NOT NULL,
    message VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'Aciqdir',
    opened_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    resolved_at DATETIME DEFAULT NULL,
    assigned_technician_account_id BIGINT UNSIGNED DEFAULT NULL,
    assigned_technician_name VARCHAR(191) DEFAULT NULL,
    resolution_reason VARCHAR(191) DEFAULT NULL,
    resolution_note VARCHAR(255) DEFAULT NULL,
    handled_by VARCHAR(191) DEFAULT NULL,
    created_by_account_id BIGINT UNSIGNED DEFAULT NULL,
    created_by_role VARCHAR(32) DEFAULT NULL,
    created_by_name VARCHAR(191) DEFAULT NULL,
    telegram_message_ids_json LONGTEXT DEFAULT NULL,
    metadata_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portal_tickets_ticket_no (ticket_no),
    KEY idx_portal_tickets_status (status),
    KEY idx_portal_tickets_area (area),
    KEY idx_portal_tickets_subject (subject),
    KEY idx_portal_tickets_opened_at (opened_at),
    KEY idx_portal_tickets_resolved_at (resolved_at),
    KEY idx_portal_tickets_assigned (assigned_technician_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_ticket_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    event_note VARCHAR(255) DEFAULT NULL,
    actor_account_id BIGINT UNSIGNED DEFAULT NULL,
    actor_name VARCHAR(191) DEFAULT NULL,
    payload_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_portal_ticket_events_ticket (ticket_id, created_at),
    CONSTRAINT fk_portal_ticket_events_ticket
        FOREIGN KEY (ticket_id) REFERENCES portal_tickets(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS portal_sequences (
    name VARCHAR(64) PRIMARY KEY,
    current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS billing_tariffs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(64) DEFAULT NULL,
    name VARCHAR(191) NOT NULL,
    service_type ENUM('pppoe','ipoe') NOT NULL DEFAULT 'pppoe',
    download_mbps INT UNSIGNED NOT NULL DEFAULT 0,
    upload_mbps INT UNSIGNED NOT NULL DEFAULT 0,
    monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    radius_group VARCHAR(128) NOT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    metadata_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_tariffs_external (external_id),
    UNIQUE KEY uq_billing_tariffs_name_service (name, service_type),
    UNIQUE KEY uq_billing_tariffs_radius_group (radius_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS billing_ip_pools (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    service_type ENUM('pppoe','ipoe') NOT NULL DEFAULT 'pppoe',
    pool_type ENUM('dynamic','static') NOT NULL DEFAULT 'dynamic',
    range_start VARCHAR(45) NOT NULL,
    range_end VARCHAR(45) NOT NULL,
    gateway VARCHAR(45) DEFAULT NULL,
    netmask VARCHAR(45) DEFAULT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_ip_pools_name (name),
    KEY idx_billing_ip_pools_type (service_type, pool_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS billing_radius_services (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(191) NOT NULL,
    service_type ENUM('pppoe','ipoe') NOT NULL DEFAULT 'pppoe',
    tariff_id BIGINT UNSIGNED DEFAULT NULL,
    ip_pool_id BIGINT UNSIGNED DEFAULT NULL,
    assigned_ip VARCHAR(45) DEFAULT NULL,
    static_ip VARCHAR(45) DEFAULT NULL,
    status ENUM('active','suspended','expired','disabled') NOT NULL DEFAULT 'active',
    expires_at DATETIME DEFAULT NULL,
    auto_suspend TINYINT(1) NOT NULL DEFAULT 1,
    last_radius_sync_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_radius_services_username (username),
    KEY idx_billing_radius_services_account (account_id),
    KEY idx_billing_radius_services_status (status, expires_at),
    CONSTRAINT fk_billing_radius_services_account
        FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS billing_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    external_id VARCHAR(64) DEFAULT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    subscriber_code VARCHAR(64) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(64) NOT NULL DEFAULT 'manual',
    transaction_id VARCHAR(191) DEFAULT NULL,
    note VARCHAR(255) DEFAULT NULL,
    metadata_json LONGTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billing_payments_external (external_id),
    KEY idx_billing_payments_account (account_id, created_at),
    KEY idx_billing_payments_subscriber (subscriber_code),
    KEY idx_billing_payments_transaction (transaction_id),
    KEY idx_billing_payments_created (created_at),
    CONSTRAINT fk_billing_payments_account
        FOREIGN KEY (account_id) REFERENCES portal_accounts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radcheck (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '==',
    value VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radcheck_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radreply (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radreply_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radusergroup (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL DEFAULT '',
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    priority INT NOT NULL DEFAULT 1,
    UNIQUE KEY uq_radusergroup_user_group (username, groupname),
    KEY idx_radusergroup_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radgroupreply (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    groupname VARCHAR(64) NOT NULL DEFAULT '',
    attribute VARCHAR(64) NOT NULL DEFAULT '',
    op CHAR(2) NOT NULL DEFAULT '=',
    value VARCHAR(253) NOT NULL DEFAULT '',
    KEY idx_radgroupreply_groupname (groupname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS radacct (
    radacctid BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    acctsessionid VARCHAR(64) NOT NULL DEFAULT '',
    acctuniqueid VARCHAR(64) NOT NULL DEFAULT '',
    username VARCHAR(64) NOT NULL DEFAULT '',
    realm VARCHAR(64) DEFAULT '',
    nasipaddress VARCHAR(15) NOT NULL DEFAULT '',
    nasportid VARCHAR(32) DEFAULT NULL,
    nasporttype VARCHAR(32) DEFAULT NULL,
    acctstarttime DATETIME DEFAULT NULL,
    acctupdatetime DATETIME DEFAULT NULL,
    acctstoptime DATETIME DEFAULT NULL,
    acctinterval INT UNSIGNED DEFAULT NULL,
    acctsessiontime INT UNSIGNED DEFAULT NULL,
    acctauthentic VARCHAR(32) DEFAULT NULL,
    connectinfo_start VARCHAR(50) DEFAULT NULL,
    connectinfo_stop VARCHAR(50) DEFAULT NULL,
    acctinputoctets BIGINT DEFAULT NULL,
    acctoutputoctets BIGINT DEFAULT NULL,
    calledstationid VARCHAR(50) NOT NULL DEFAULT '',
    callingstationid VARCHAR(50) NOT NULL DEFAULT '',
    acctterminatecause VARCHAR(32) NOT NULL DEFAULT '',
    servicetype VARCHAR(32) DEFAULT NULL,
    framedprotocol VARCHAR(32) DEFAULT NULL,
    framedipaddress VARCHAR(15) NOT NULL DEFAULT '',
    KEY idx_radacct_username (username),
    KEY idx_radacct_acctsessionid (acctsessionid),
    KEY idx_radacct_acctuniqueid (acctuniqueid),
    KEY idx_radacct_start_user (acctstarttime, username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
