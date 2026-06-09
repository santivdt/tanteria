<?php
/**
 * subscribe.php — EmailOctopus proxy
 * --------------------------------------------------------------
 * De browser post hierheen; dit bestand praat met EmailOctopus.
 * De API-key staat in ../config/emailoctopus.php, BUITEN public_html,
 * dus de key is nooit publiek bereikbaar of zichtbaar in de browser.
 */

header('Content-Type: application/json; charset=utf-8');

// Alleen POST toestaan
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Config inladen. Voorkeur = één map BOVEN public_html (niet publiek
// bereikbaar). We checken een paar logische plekken zodat het werkt
// ongeacht hoe je het op Hostinger hebt neergezet.
$candidates = [
    __DIR__ . '/../config/emailoctopus.php',  // aanbevolen: buiten public_html
    __DIR__ . '/../emailoctopus.php',         // buiten public_html, los bestand
    __DIR__ . '/config/emailoctopus.php',     // binnen public_html (minder veilig)
    __DIR__ . '/emailoctopus.php',            // naast subscribe.php (minder veilig)
];

$configPath = null;
foreach ($candidates as $path) {
    if (is_file($path)) { $configPath = $path; break; }
}

if ($configPath === null) {
    http_response_code(500);
    // Toon waar er gezocht is, zodat je 't makkelijk kunt fixen.
    // VERWIJDER deze 'tried' regel zodra het werkt (lekt serverpaden).
    echo json_encode([
        'ok'    => false,
        'error' => 'Server niet geconfigureerd',
        'tried' => $candidates,
    ]);
    exit;
}
$cfg = require $configPath;

// Invoer valideren
$email     = trim($_POST['email_address'] ?? '');
$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Ongeldig e-mailadres']);
    exit;
}
if ($firstName === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Voornaam is verplicht']);
    exit;
}

// EmailOctopus-velden. FirstName / LastName zijn de standaard veld-tags.
$fields = ['FirstName' => $firstName];
if ($lastName !== '') {
    $fields['LastName'] = $lastName;
}

/**
 * EmailOctopus API v2 — key gaat mee als Bearer-token in de header.
 * Gebruik je nog de oude v1.6 API? Zie de opmerking onderaan dit bestand.
 */
$url = "https://api.emailoctopus.com/lists/{$cfg['list_id']}/contacts";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $cfg['api_key'],
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'email_address' => $email,
        'fields'        => $fields,
        // 'status' => 'subscribed', // weglaten = double opt-in via EmailOctopus
    ]),
]);

$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Kon nieuwsbriefdienst niet bereiken']);
    exit;
}

if ($status >= 200 && $status < 300) {
    echo json_encode(['ok' => true]);
    exit;
}

// EmailOctopus geeft een nette foutmelding terug; vang "al ingeschreven" af
$body = json_decode($response, true);
$code = $body['error']['code'] ?? ($body['detail'] ?? '');

if (stripos((string) $code, 'EXIST') !== false || $status === 409) {
    echo json_encode(['ok' => true]); // al ingeschreven = prima, geen foutmelding tonen
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Inschrijven mislukt. Probeer het later nog eens.']);

/*
 * --------------------------------------------------------------
 * OUDE API (v1.6)? Vervang het cURL-blok hierboven door:
 *
 *   $url = "https://emailoctopus.com/api/1.6/lists/{$cfg['list_id']}/contacts";
 *   CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
 *   CURLOPT_POSTFIELDS => json_encode([
 *       'api_key'       => $cfg['api_key'],   // key in de body i.p.v. header
 *       'email_address' => $email,
 *       'fields'        => $fields,           // ['FirstName' => ..., 'LastName' => ...]
 *   ]),
 *
 * Welke versie je hebt zie je in je EmailOctopus-dashboard onder
 * Account → Integrations & API / API keys.
 * --------------------------------------------------------------
 */
