<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/config.php';
$db = app_db();
if (!$db instanceof PDO) exit(2);
$sql = "SELECT COALESCE(NULLIF(payment_method,''),'unknown') AS method, COUNT(*) AS payments,
               MAX(created_at) AS last_payment_at,
               SUM(created_at >= NOW() - INTERVAL 24 HOUR) AS last_24h
          FROM billing_payments
         GROUP BY COALESCE(NULLIF(payment_method,''),'unknown')
         ORDER BY last_payment_at DESC LIMIT 30";
echo json_encode(['checked_at' => date(DATE_ATOM), 'methods' => $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE) . PHP_EOL;
