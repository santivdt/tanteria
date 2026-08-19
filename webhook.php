<?php
/**
 * webhook.php — Stripe stuurt hierheen zodra een betaling afgerond is.
 * Dit is de ENIGE plek waar een order definitief op 'paid' gezet wordt
 * (niet de success.html-redirect, die kan een koper altijd wegklikken).
 *
 * Stel deze URL in bij Stripe → Developers → Webhooks:
 *   https://tanteria.nl/webhook.php
 * Events: checkout.session.completed, checkout.session.async_payment_succeeded,
 *         checkout.session.async_payment_failed, checkout.session.expired
 */

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/stripe.php';
require __DIR__ . '/lib/ahasend.php';
require __DIR__ . '/lib/sheets.php';

$cfg = load_tickets_config();
$pdo = tickets_db();

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!stripe_verify_webhook_signature($payload, $sigHeader, $cfg['stripe_webhook_secret'])) {
    http_response_code(400);
    echo 'Ongeldige handtekening';
    exit;
}

$event = json_decode($payload, true);
$type  = $event['type'] ?? '';

$succeeded = ['checkout.session.completed', 'checkout.session.async_payment_succeeded'];
$failed    = ['checkout.session.async_payment_failed', 'checkout.session.expired'];

if (!in_array($type, array_merge($succeeded, $failed), true)) {
    http_response_code(200);
    echo 'genegeerd (irrelevant event type)';
    exit;
}

$session = $event['data']['object'] ?? [];
$orderId = (int) ($session['client_reference_id'] ?? ($session['metadata']['order_id'] ?? 0));

if (!$orderId) {
    http_response_code(200);
    echo 'genegeerd (geen order_id)';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(200);
    echo 'order niet gevonden';
    exit;
}

if (in_array($type, $failed, true)) {
    if ($order['status'] === 'pending') {
        $pdo->prepare("UPDATE orders SET status = 'failed', updated_at = ? WHERE id = ?")
            ->execute([gmdate('c'), $orderId]);
    }
    http_response_code(200);
    echo 'ok';
    exit;
}

if (($session['payment_status'] ?? '') !== 'paid') {
    http_response_code(200);
    echo 'nog niet betaald';
    exit;
}

if ($order['status'] === 'paid') {
    // Stripe kan events dubbel afleveren — idempotent negeren.
    http_response_code(200);
    echo 'al verwerkt';
    exit;
}

// Nog één keer de limieten checken vlak voor bevestiging. Bij gelijktijdige
// aankopen rond de grens kan een order net over de limiet heen bevestigd
// worden door Stripe — die wordt dan direct terugbetaald i.p.v. stilzwijgend
// als geldig ticket behandeld.
$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM orders WHERE status = 'paid' AND id != ?");
$stmt->execute([$orderId]);
$paidTotal = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM orders WHERE status = 'paid' AND email = ? AND id != ?");
$stmt->execute([$order['email'], $orderId]);
$emailPaid = (int) $stmt->fetchColumn();

$quantity = (int) $order['quantity'];
$oversold = ($paidTotal + $quantity > $cfg['ticket_limit'])
    || ($emailPaid + $quantity > $cfg['max_per_person']);

$paymentIntent = $session['payment_intent'] ?? '';

if ($oversold) {
    if ($paymentIntent) {
        stripe_request('POST', 'refunds', ['payment_intent' => $paymentIntent], $cfg['stripe_secret_key']);
    }

    $pdo->prepare("UPDATE orders SET status = 'refunded_oversold', stripe_payment_intent = ?, updated_at = ? WHERE id = ?")
        ->execute([$paymentIntent, gmdate('c'), $orderId]);

    ahasend_send_email(
        $cfg,
        $order['email'],
        $order['name'],
        'Helaas — kaarten waren net uitverkocht',
        "<p>Hoi {$order['name']},</p><p>Sorry, de kaarten waren op het moment van bevestigen net uitverkocht door gelijktijdige aankopen. Je betaling is direct teruggestort.</p>",
        "Hoi {$order['name']},\n\nSorry, de kaarten waren op het moment van bevestigen net uitverkocht door gelijktijdige aankopen. Je betaling is direct teruggestort."
    );

    http_response_code(200);
    echo 'oversold, terugbetaald';
    exit;
}

$ticketCode = 'TR-' . strtoupper(bin2hex(random_bytes(4)));

$pdo->prepare("UPDATE orders SET status = 'paid', stripe_payment_intent = ?, ticket_code = ?, updated_at = ? WHERE id = ?")
    ->execute([$paymentIntent, $ticketCode, gmdate('c'), $orderId]);

$amountEuros = $order['amount_total'] / 100;
$amount = number_format($amountEuros, 2, ',', '.');

$html = "<p>Hoi {$order['name']},</p>"
    . "<p>Je bestelling is bevestigd! 🎉</p>"
    . "<ul>"
    . "<li>Event: {$cfg['event_name']}</li>"
    . "<li>Aantal kaarten: {$quantity}</li>"
    . "<li>Totaal betaald: € {$amount}</li>"
    . "<li>Ticketcode: <strong>{$ticketCode}</strong></li>"
    . "</ul>"
    . "<p>Tot dan!</p>";

$text = "Hoi {$order['name']},\n\nJe bestelling is bevestigd!\n\n"
    . "Event: {$cfg['event_name']}\nAantal kaarten: {$quantity}\nTotaal betaald: € {$amount}\nTicketcode: {$ticketCode}\n\nTot dan!";

ahasend_send_email($cfg, $order['email'], $order['name'], "Bevestiging: {$cfg['event_name']}", $html, $text);

$sheetResult = sheets_append_order($cfg, [
    'order_id'              => $orderId,
    'datum'                 => gmdate('Y-m-d H:i'),
    'naam'                  => $order['name'],
    'email'                 => $order['email'],
    'aantal'                => $quantity,
    'bedrag'                => $amountEuros,
    'ticket_code'           => $ticketCode,
    'stripe_payment_intent' => $paymentIntent,
]);

if ($sheetResult['ok']) {
    $pdo->prepare('UPDATE orders SET sheet_synced = 1 WHERE id = ?')->execute([$orderId]);
} else {
    error_log("Tante Ria: sheet-sync mislukt voor order {$orderId} (status {$sheetResult['status']}): {$sheetResult['error']}");
}

http_response_code(200);
echo 'ok';
