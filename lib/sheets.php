<?php
/**
 * Stuurt een verkochte bestelling naar een Google Apps Script Web App die
 * een rij toevoegt aan een Google Sheet — dat IS het verkoopoverzicht,
 * geen aparte admin-pagina nodig. Zie de losse Apps Script-code die je
 * apart hebt gekregen om in het Sheet te plakken.
 */
function sheets_append_order(array $cfg, array $order): array
{
    $payload = array_merge($order, ['secret' => $cfg['sheets_shared_secret']]);

    $ch = curl_init($cfg['sheets_webapp_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true, // Apps Script redirect't vaak eerst
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    return [
        'ok'     => !$curlErr && $status >= 200 && $status < 300,
        'status' => $status,
        'error'  => $curlErr,
    ];
}
