<?php
/**
 * Moneris Cloud API v3 — Test Device Connection
 *
 * Sends a $0.01 purchase to the Moneris test endpoint.
 * Run: php moneris_test.php
 * Delete after testing.
 */

$storeId    = 'mogo006002';
$apiToken   = 'ug51LXwT46kcqkvDzzp8';
$terminalId = '66029166';
$endpoint   = 'https://ippostest.moneris.com/v3/Terminal/';

$orderId        = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0xffff), random_int(0, 0x0fff) | 0x4000,
    random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
);
$idempotencyKey = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    random_int(0, 0xffff), random_int(0, 0xffff),
    random_int(0, 0xffff), random_int(0, 0x0fff) | 0x4000,
    random_int(0, 0x3fff) | 0x8000,
    random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
);

$payload = [
    'apiVersion'    => '3.0',
    'apiToken'      => $apiToken,
    'storeId'       => $storeId,
    'istConfigCode' => 'C000993ECP',
    'polling'       => 'true',
    'dataId'        => $orderId,
    'dataTimestamp'  => date('Y-m-d H:i:s'),
    'data' => [
        'request' => [
            [
                'orderId'        => $orderId,
                'idempotencyKey' => $idempotencyKey,
                'terminalId'     => $terminalId,
                'username'       => 'test',
                'action'         => 'purchase',
                'totalAmount'    => '1',   // 1 cent = $0.01
                'subtotalAmount' => '1',
                'taxes'          => [],
            ],
        ],
    ],
];

$json = json_encode($payload, JSON_PRETTY_PRINT);

echo "=== Moneris Cloud API v3 Test ===\n";
echo "Endpoint: $endpoint\n";
echo "Store: $storeId | Terminal: $terminalId\n";
echo "Order ID: $orderId\n";
echo "Amount: \$0.01 (1 cent)\n";
echo "\n--- REQUEST ---\n";
echo $json . "\n";

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $json,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$result   = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);
curl_close($ch);

echo "\n--- RESPONSE (HTTP $httpCode) ---\n";

if ($result === false) {
    echo "CURL ERROR: $error\n";
    exit(1);
}

$decoded = json_decode($result, true);
if ($decoded) {
    echo json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
} else {
    echo "RAW: $result\n";
}

// Interpret common status codes
$statusCode = $decoded['data']['response'][0]['statusCode']
    ?? $decoded['receipt']['data']['response'][0]['statusCode']
    ?? $decoded['statusCode'] ?? $decoded['StatusCode'] ?? null;

echo "\n--- INTERPRETATION ---\n";
if ($statusCode === null) {
    echo "Could not extract statusCode from response.\n";
} else {
    $codes = [
        '5207' => 'APPROVED',
        '5206' => 'ACCEPTED (pending)',
        '5209' => 'ACCEPTED (pending)',
        '5904' => 'TERMINAL BUSY',
        '5901' => 'TERMINAL NOT FOUND / OFFLINE',
        '5900' => 'GENERAL ERROR',
    ];
    $meaning = $codes[$statusCode] ?? 'UNKNOWN';
    echo "Status Code: $statusCode — $meaning\n";
}

// Check if polling is needed
$receiptUrl = $decoded['data']['response'][0]['receiptUrl']
    ?? $decoded['receipt']['data']['response'][0]['receiptUrl']
    ?? null;
$completed = $decoded['data']['response'][0]['completed']
    ?? $decoded['receipt']['data']['response'][0]['completed']
    ?? null;

if ($receiptUrl && strtolower($completed ?? '') !== 'true') {
    echo "\nPolling needed — receiptUrl: $receiptUrl\n";
    echo "Polling every 3 seconds (max 60s)...\n\n";

    $start = time();
    while ((time() - $start) < 60) {
        sleep(3);
        $pch = curl_init($receiptUrl);
        curl_setopt_array($pch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $pollResult = curl_exec($pch);
        curl_close($pch);

        $pollDecoded = json_decode($pollResult, true);
        if (!$pollDecoded) {
            echo "Poll: no valid response, retrying...\n";
            continue;
        }

        $pollCompleted = $pollDecoded['receipt']['data']['response'][0]['completed']
            ?? $pollDecoded['data']['response'][0]['completed']
            ?? 'false';

        if (strtolower($pollCompleted) === 'true') {
            echo "--- FINAL RESULT ---\n";
            echo json_encode($pollDecoded, JSON_PRETTY_PRINT) . "\n";

            $finalStatus = $pollDecoded['receipt']['data']['response'][0]['statusCode']
                ?? $pollDecoded['data']['response'][0]['statusCode'] ?? '?';
            echo "\nFinal Status: $finalStatus — " . ($finalStatus === '5207' ? 'APPROVED' : 'NOT APPROVED') . "\n";
            exit(0);
        }
        echo "Poll: not yet completed, waiting...\n";
    }
    echo "TIMEOUT: Terminal did not respond within 60 seconds.\n";
}
