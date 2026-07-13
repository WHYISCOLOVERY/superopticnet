CREATE DATABASE IF NOT EXISTS superoptic_analytics;

CREATE TABLE IF NOT EXISTS superoptic_analytics.radius_nat_logs
(
    logged_at DateTime64(3, 'UTC') CODEC(Delta, ZSTD(3)),
    node_name LowCardinality(String) CODEC(ZSTD(3)),
    protocol LowCardinality(String) CODEC(ZSTD(3)),
    source_ip IPv4 CODEC(ZSTD(3)),
    source_port UInt16 CODEC(T64, ZSTD(3)),
    nat_ip IPv4 CODEC(ZSTD(3)),
    nat_port UInt16 CODEC(T64, ZSTD(3)),
    destination_ip IPv4 CODEC(ZSTD(3)),
    destination_port UInt16 CODEC(T64, ZSTD(3)),
    username String CODEC(ZSTD(3)),
    subscriber_code String CODEC(ZSTD(3)),
    raw_message String CODEC(ZSTD(6)),
    message_hash FixedString(64) CODEC(ZSTD(3)),
    ingested_at DateTime64(3, 'UTC') DEFAULT now64(3) CODEC(Delta, ZSTD(3))
)
ENGINE = ReplicatedMergeTree('/clickhouse/tables/{shard}/radius_nat_logs', '{replica}')
PARTITION BY toYYYYMM(logged_at)
ORDER BY (logged_at, source_ip, source_port, nat_ip, nat_port, destination_ip, destination_port)
TTL logged_at + INTERVAL 365 DAY TO VOLUME 'archive',
    logged_at + INTERVAL 1095 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';

CREATE TABLE IF NOT EXISTS superoptic_analytics.nat_logs_hourly
(
    bucket_start DateTime('UTC'),
    node_name LowCardinality(String),
    nat_ip IPv4,
    destination_ip IPv4,
    destination_port UInt16,
    protocol LowCardinality(String),
    event_count UInt64,
    unique_customers AggregateFunction(uniqCombined64, String),
    unique_sources AggregateFunction(uniqCombined64, IPv4),
    unique_destinations AggregateFunction(uniqCombined64, IPv4)
)
ENGINE = ReplicatedAggregatingMergeTree('/clickhouse/tables/{shard}/nat_logs_hourly', '{replica}')
PARTITION BY toYYYYMM(bucket_start)
ORDER BY (bucket_start, node_name, nat_ip, destination_ip, destination_port, protocol)
TTL bucket_start + INTERVAL 1095 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';

CREATE MATERIALIZED VIEW IF NOT EXISTS superoptic_analytics.mv_nat_logs_hourly
TO superoptic_analytics.nat_logs_hourly
AS
SELECT
    toStartOfHour(logged_at) AS bucket_start,
    node_name,
    nat_ip,
    destination_ip,
    destination_port,
    protocol,
    count() AS event_count,
    uniqCombined64State(subscriber_code) AS unique_customers,
    uniqCombined64State(source_ip) AS unique_sources,
    uniqCombined64State(destination_ip) AS unique_destinations
FROM superoptic_analytics.radius_nat_logs
GROUP BY bucket_start, node_name, nat_ip, destination_ip, destination_port, protocol;

CREATE TABLE IF NOT EXISTS superoptic_analytics.nat_logs_daily
(
    bucket_date Date,
    node_name LowCardinality(String),
    nat_ip IPv4,
    event_count UInt64,
    unique_customers AggregateFunction(uniqCombined64, String),
    unique_sources AggregateFunction(uniqCombined64, IPv4),
    unique_destinations AggregateFunction(uniqCombined64, IPv4)
)
ENGINE = ReplicatedAggregatingMergeTree('/clickhouse/tables/{shard}/nat_logs_daily', '{replica}')
PARTITION BY toYYYYMM(bucket_date)
ORDER BY (bucket_date, node_name, nat_ip)
TTL bucket_date + INTERVAL 1095 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';

CREATE MATERIALIZED VIEW IF NOT EXISTS superoptic_analytics.mv_nat_logs_daily
TO superoptic_analytics.nat_logs_daily
AS
SELECT
    toDate(logged_at) AS bucket_date,
    node_name,
    nat_ip,
    count() AS event_count,
    uniqCombined64State(subscriber_code) AS unique_customers,
    uniqCombined64State(source_ip) AS unique_sources,
    uniqCombined64State(destination_ip) AS unique_destinations
FROM superoptic_analytics.radius_nat_logs
GROUP BY bucket_date, node_name, nat_ip;

CREATE TABLE IF NOT EXISTS superoptic_analytics.radacct_events
(
    acctstarttime DateTime64(3, 'UTC') CODEC(Delta, ZSTD(3)),
    acctupdatetime DateTime64(3, 'UTC') CODEC(Delta, ZSTD(3)),
    acctstoptime Nullable(DateTime64(3, 'UTC')) CODEC(Delta, ZSTD(3)),
    username String CODEC(ZSTD(3)),
    nasipaddress String CODEC(ZSTD(3)),
    framedipaddress String CODEC(ZSTD(3)),
    callingstationid String CODEC(ZSTD(3)),
    acctsessionid String CODEC(ZSTD(3)),
    acctuniqueid String CODEC(ZSTD(3)),
    acctinputoctets UInt64 CODEC(T64, ZSTD(3)),
    acctoutputoctets UInt64 CODEC(T64, ZSTD(3)),
    acctsessiontime UInt64 CODEC(T64, ZSTD(3)),
    acctterminatecause LowCardinality(String) CODEC(ZSTD(3)),
    ingested_at DateTime64(3, 'UTC') DEFAULT now64(3) CODEC(Delta, ZSTD(3))
)
ENGINE = ReplicatedReplacingMergeTree('/clickhouse/tables/{shard}/radacct_events', '{replica}', acctupdatetime)
PARTITION BY toYYYYMM(acctstarttime)
ORDER BY (acctstarttime, username, nasipaddress, acctsessionid)
TTL acctstarttime + INTERVAL 730 DAY TO VOLUME 'archive',
    acctstarttime + INTERVAL 1095 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';

CREATE TABLE IF NOT EXISTS superoptic_analytics.radacct_hourly_rollup
(
    bucket_start DateTime('UTC'),
    username String,
    nasipaddress String,
    session_count UInt64,
    input_octets UInt64,
    output_octets UInt64,
    max_session_seconds UInt64
)
ENGINE = ReplicatedSummingMergeTree('/clickhouse/tables/{shard}/radacct_hourly_rollup', '{replica}')
PARTITION BY toYYYYMM(bucket_start)
ORDER BY (bucket_start, username, nasipaddress)
TTL bucket_start + INTERVAL 1095 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';

CREATE MATERIALIZED VIEW IF NOT EXISTS superoptic_analytics.mv_radacct_hourly_rollup
TO superoptic_analytics.radacct_hourly_rollup
AS
SELECT
    toStartOfHour(acctstarttime) AS bucket_start,
    username,
    nasipaddress,
    count() AS session_count,
    sum(acctinputoctets) AS input_octets,
    sum(acctoutputoctets) AS output_octets,
    max(acctsessiontime) AS max_session_seconds
FROM superoptic_analytics.radacct_events
GROUP BY bucket_start, username, nasipaddress;

CREATE TABLE IF NOT EXISTS superoptic_analytics.radacct_daily_rollup
(
    bucket_date Date,
    username String,
    nasipaddress String,
    session_count UInt64,
    input_octets UInt64,
    output_octets UInt64,
    max_session_seconds UInt64
)
ENGINE = ReplicatedSummingMergeTree('/clickhouse/tables/{shard}/radacct_daily_rollup', '{replica}')
PARTITION BY toYYYYMM(bucket_date)
ORDER BY (bucket_date, username, nasipaddress)
TTL bucket_date + INTERVAL 1095 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';

CREATE MATERIALIZED VIEW IF NOT EXISTS superoptic_analytics.mv_radacct_daily_rollup
TO superoptic_analytics.radacct_daily_rollup
AS
SELECT
    toDate(acctstarttime) AS bucket_date,
    username,
    nasipaddress,
    count() AS session_count,
    sum(acctinputoctets) AS input_octets,
    sum(acctoutputoctets) AS output_octets,
    max(acctsessiontime) AS max_session_seconds
FROM superoptic_analytics.radacct_events
GROUP BY bucket_date, username, nasipaddress;

CREATE TABLE IF NOT EXISTS superoptic_analytics.radacct_monthly_rollup
(
    bucket_month Date,
    username String,
    nasipaddress String,
    session_count UInt64,
    input_octets UInt64,
    output_octets UInt64,
    max_session_seconds UInt64
)
ENGINE = ReplicatedSummingMergeTree('/clickhouse/tables/{shard}/radacct_monthly_rollup', '{replica}')
PARTITION BY toYYYYMM(bucket_month)
ORDER BY (bucket_month, username, nasipaddress)
TTL bucket_month + INTERVAL 1825 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';

CREATE MATERIALIZED VIEW IF NOT EXISTS superoptic_analytics.mv_radacct_monthly_rollup
TO superoptic_analytics.radacct_monthly_rollup
AS
SELECT
    toStartOfMonth(acctstarttime) AS bucket_month,
    username,
    nasipaddress,
    count() AS session_count,
    sum(acctinputoctets) AS input_octets,
    sum(acctoutputoctets) AS output_octets,
    max(acctsessiontime) AS max_session_seconds
FROM superoptic_analytics.radacct_events
GROUP BY bucket_month, username, nasipaddress;

CREATE TABLE IF NOT EXISTS superoptic_analytics.law_export_requests
(
    request_id UUID DEFAULT generateUUIDv4(),
    requested_at DateTime64(3, 'UTC') DEFAULT now64(3),
    requester String,
    reason String,
    approval_status LowCardinality(String),
    approved_by String,
    approved_at Nullable(DateTime64(3, 'UTC')),
    query_scope String,
    export_format LowCardinality(String),
    output_path String,
    row_count UInt64,
    export_hash FixedString(64),
    immutable_marker FixedString(64)
)
ENGINE = ReplicatedMergeTree('/clickhouse/tables/{shard}/law_export_requests', '{replica}')
PARTITION BY toYYYYMM(requested_at)
ORDER BY (requested_at, requester, request_id)
TTL requested_at + INTERVAL 3650 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';

CREATE TABLE IF NOT EXISTS superoptic_analytics.nat_search_audit
(
    searched_at DateTime64(3, 'UTC') DEFAULT now64(3),
    searched_by String,
    reason String,
    source_ip String,
    nat_ip String,
    destination_ip String,
    destination_port UInt16,
    time_from DateTime64(3, 'UTC'),
    time_to DateTime64(3, 'UTC'),
    result_count UInt64
)
ENGINE = ReplicatedMergeTree('/clickhouse/tables/{shard}/nat_search_audit', '{replica}')
PARTITION BY toYYYYMM(searched_at)
ORDER BY (searched_at, searched_by)
TTL searched_at + INTERVAL 3650 DAY DELETE
SETTINGS index_granularity = 8192, storage_policy = 'hot_then_archive';
