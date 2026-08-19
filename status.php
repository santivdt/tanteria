<?php
/**
 * status.php — publieke, read-only info voor de kaartverkoop-widget
 * (prijs, hoeveel er nog beschikbaar zijn, of de verkoop open is).
 */

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/db.php';

header('Content-Type: application/json; charset=utf-8');

$cfg = load_tickets_config();
$pdo = tickets_db();

$stmt = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM orders WHERE status = 'paid'");
$sold = (int) $stmt->fetchColumn();
$remaining = max(0, $cfg['ticket_limit'] - $sold);

echo json_encode([
    'ok'             => true,
    'sales_open'     => (bool) ($cfg['sales_open'] ?? false),
    'event_name'     => $cfg['event_name'],
    'event_date_label' => $cfg['event_date_label'] ?? '',
    'price_cents'    => $cfg['ticket_price_cents'],
    'currency'       => $cfg['currency'],
    'max_per_person' => $cfg['max_per_person'],
    'limit'          => $cfg['ticket_limit'],
    'sold'           => $sold,
    'remaining'      => $remaining,
    'sold_out'       => $remaining <= 0,
]);
