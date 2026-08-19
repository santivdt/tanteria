<?php
/**
 * AhaSend — transactionele bevestigingsmail.
 *
 * LET OP: controleer bij het instellen de actuele endpoint/payload-vorm in
 * het AhaSend-dashboard (Developers → API docs) — dit is een kleinere,
 * relatief nieuwe dienst en de exacte vorm hieronder kan intussen gewijzigd
 * zijn. Zelfde soort voorbehoud als de EmailOctopus v1.6/v2-opmerking
 * onderaan subscribe.php.
 */
function ahasend_send_email(array $cfg, string $toEmail, string $toName, string $subject, string $html, string $text): array
{
    $url = 'https://api.ahasend.com/v2/email/send';

    $payload = [
        'from' => [
            'email' => $cfg['ahasend_from_email'],
            'name'  => $cfg['ahasend_from_name'],
        ],
        'recipients' => [
            ['email' => $toEmail, 'name' => $toName],
        ],
        'subject'      => $subject,
        'html_content' => $html,
        'text_content' => $text,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['ahasend_api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    return [
        'ok'       => !$curlErr && $status >= 200 && $status < 300,
        'status'   => $status,
        'error'    => $curlErr,
        'response' => $response,
    ];
}
