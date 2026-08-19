<?php
/**
 * buy.php — start een Stripe Checkout-sessie voor 1 of 2 kaarten.
 * De browser post hierheen; wij checken capaciteit/limiet, leggen een
 * pending order vast en sturen de koper door naar Stripe.
 */

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/stripe.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$cfg = load_tickets_config();

if (empty($cfg['sales_open'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'De verkoop is nog niet gestart.']);
    exit;
}

$pdo = tickets_db();

$name     = trim($_POST['name'] ?? '');
$email    = strtolower(trim($_POST['email'] ?? ''));
$quantity = (int) ($_POST['quantity'] ?? 0);

if ($name === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Vul je naam in.']);
    exit;
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Vul een geldig e-mailadres in.']);
    exit;
}
if ($quantity < 1 || $quantity > $cfg['max_per_person']) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => "Je kunt 1 tot {$cfg['max_per_person']} kaarten kopen."]);
    exit;
}

// Capaciteit checken (alleen betaalde orders tellen mee).
$stmt = $pdo->query("SELECT COALESCE(SUM(quantity),0) FROM orders WHERE status = 'paid'");
$paidTotal = (int) $stmt->fetchColumn();
$remaining = $cfg['ticket_limit'] - $paidTotal;

if ($quantity > $remaining) {
    http_response_code(409);
    echo json_encode([
        'ok'    => false,
        'error' => $remaining > 0
            ? "Nog maar {$remaining} kaart(en) beschikbaar."
            : 'Helaas, alle kaarten zijn uitverkocht.',
    ]);
    exit;
}

// Max per e-mailadres checken.
$stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM orders WHERE status = 'paid' AND email = ?");
$stmt->execute([$email]);
$emailPaid = (int) $stmt->fetchColumn();

if ($emailPaid + $quantity > $cfg['max_per_person']) {
    $left = max(0, $cfg['max_per_person'] - $emailPaid);
    http_response_code(409);
    echo json_encode([
        'ok'    => false,
        'error' => $left > 0
            ? "Dit e-mailadres kan nog maar {$left} kaart(en) kopen (max {$cfg['max_per_person']} per persoon)."
            : "Dit e-mailadres heeft al het maximum van {$cfg['max_per_person']} kaarten gekocht.",
    ]);
    exit;
}

$amountTotal = $quantity * $cfg['ticket_price_cents'];
$now = gmdate('c');

$stmt = $pdo->prepare("
    INSERT INTO orders (created_at, updated_at, status, quantity, email, name, amount_total, currency)
    VALUES (?, ?, 'pending', ?, ?, ?, ?, ?)
");
$stmt->execute([$now, $now, $quantity, $email, $name, $amountTotal, $cfg['currency']]);
$orderId = (int) $pdo->lastInsertId();

$params = [
    'mode'                 => 'payment',
    'payment_method_types' => ['card', 'ideal'],
    'customer_email'       => $email,
    'client_reference_id'  => (string) $orderId,
    'metadata'             => ['order_id' => (string) $orderId],
    'line_items'           => [[
        'quantity'   => $quantity,
        'price_data' => [
            'currency'     => $cfg['currency'],
            'unit_amount'  => $cfg['ticket_price_cents'],
            'product_data' => [
                'name' => $cfg['event_name'] . ' — ticket',
            ],
        ],
    ]],
    'success_url' => rtrim($cfg['site_url'], '/') . '/success.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'  => rtrim($cfg['site_url'], '/') . '/cancel.html',
    'expires_at'  => time() + 1800, // Stripe-minimum is 30 min vanaf nu
];

$result = stripe_request('POST', 'checkout/sessions', $params, $cfg['stripe_secret_key']);

if (!$result['ok']) {
    $pdo->prepare("UPDATE orders SET status = 'failed', updated_at = ? WHERE id = ?")
        ->execute([gmdate('c'), $orderId]);

    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Kon geen betaling starten. Probeer het later nog eens.']);
    exit;
}

$session = $result['data'];

$pdo->prepare("UPDATE orders SET stripe_session_id = ?, updated_at = ? WHERE id = ?")
    ->execute([$session['id'] ?? null, gmdate('c'), $orderId]);

echo json_encode(['ok' => true, 'url' => $session['url']]);
