<?php
/**
 * Minimale Stripe REST-client via cURL — geen SDK/Composer nodig, past bij
 * de rest van deze site (vanilla PHP, geen dependencies).
 */
function stripe_request(string $method, string $path, array $params, string $secretKey): array
{
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $secretKey,
        ],
    ];

    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'status' => 0, 'error' => $curlErr, 'data' => []];
    }

    $data = json_decode((string) $response, true) ?? [];

    return [
        'ok'     => $status >= 200 && $status < 300,
        'status' => $status,
        'data'   => $data,
    ];
}

/**
 * Verifieert de Stripe-Signature header volgens Stripe's eigen algoritme
 * (HMAC-SHA256 over "{timestamp}.{payload}"), zonder de SDK.
 */
function stripe_verify_webhook_signature(string $payload, string $sigHeader, string $secret, int $tolerance = 300): bool
{
    $parts = [];
    foreach (explode(',', $sigHeader) as $pair) {
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $parts[$k][] = $v;
    }

    $timestamp   = $parts['t'][0] ?? null;
    $signatures  = $parts['v1'] ?? [];

    if (!$timestamp || !$signatures) {
        return false;
    }
    if (abs(time() - (int) $timestamp) > $tolerance) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }

    return false;
}
