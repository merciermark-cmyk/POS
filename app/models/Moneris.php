<?php
/**
 * Moneris Go Cloud API v3 — server-side integration.
 *
 * Initiates card-present transactions (purchase/void/refund) via the Moneris Go
 * Cloud API, polls for the terminal response, and stores results in
 * pos_moneris_transactions. Credentials stay server-side; the browser gets
 * a single JSON response once the terminal interaction completes.
 */
class Moneris extends BaseModel {

    private const SANDBOX_URL    = 'https://ippostest.moneris.com/v3/Terminal/';
    private const PRODUCTION_URL = 'https://ippos.moneris.com/v3/Terminal/';

    private const POLL_INTERVAL  = 3;   // seconds between polls
    private const POLL_TIMEOUT   = 120; // max seconds to poll
    private const STATUS_APPROVED = '5207';
    private const STATUS_BUSY     = '5904';

    // ── Public API ──────────────────────────────────────────────────

    /**
     * Initiate a purchase and poll until the terminal responds.
     *
     * @param string $terminalId  Moneris terminal ID
     * @param float  $total       Total amount in dollars
     * @param float  $subtotal    Subtotal before tax
     * @param float  $gst         GST amount
     * @param float  $pst         PST amount
     * @param string $username    Operator name (for Moneris clerk field)
     * @return array  Result with keys: success, moneris_id, approved, status_code, auth_code, card_type, masked_pan, tender_type, form_factor, message, error
     */
    public function purchase(string $terminalId, float $total, float $subtotal, float $gst, float $pst, string $username): array {
        $settings = $this->getSettings();
        if (!$settings) {
            return ['success' => false, 'error' => 'Moneris not configured'];
        }

        $orderId        = $this->generateUuid();
        $idempotencyKey = $this->generateUuid();

        $payload = [
            'apiVersion'   => '3.0',
            'apiToken'     => $settings['apiToken'],
            'storeId'      => $settings['storeId'],
            'istConfigCode'=> $settings['istConfigCode'],
            'polling'      => 'true',
            'dataId'       => $orderId,
            'dataTimestamp' => date('Y-m-d H:i:s'),
            'data' => [
                'request' => [
                    [
                        'orderId'        => $orderId,
                        'idempotencyKey' => $idempotencyKey,
                        'terminalId'     => $terminalId,
                        'username'       => $username,
                        'action'         => 'purchase',
                        'totalAmount'    => (string)$this->dollarsToCents($total),
                        'subtotalAmount' => (string)$this->dollarsToCents($subtotal),
                        'taxes'          => [
                            ['taxAmount' => (string)$this->dollarsToCents($gst), 'taxName' => 'GST'],
                            ['taxAmount' => (string)$this->dollarsToCents($pst), 'taxName' => 'PST'],
                        ],
                    ],
                ],
            ],
        ];

        // Store the pending transaction
        $monerisId = $this->storeTransaction([
            'order_id'        => $orderId,
            'idempotency_key' => $idempotencyKey,
            'terminal_id'     => $terminalId,
            'action'          => 'purchase',
            'amount_cents'    => $this->dollarsToCents($total),
        ]);

        // Send initial request
        $response = $this->post($settings['baseUrl'], $payload);
        if (!$response) {
            $this->updateTransaction($monerisId, ['completed' => 1]);
            return ['success' => false, 'error' => 'Failed to connect to Moneris', 'moneris_id' => $monerisId];
        }

        // Parse initial response — may be wrapped in "receipt"
        $parsed = $this->parseResponse($response);

        // Check for immediate errors
        if ($parsed['error']) {
            $this->updateTransaction($monerisId, [
                'status_code'    => $parsed['statusCode'],
                'response_code'  => $parsed['responseCode'],
                'cloud_ticket'   => $parsed['cloudTicket'],
                'full_response'  => json_encode($response),
                'completed'      => 1,
            ]);
            return [
                'success'     => false,
                'error'       => $parsed['errorMessage'],
                'status_code' => $parsed['statusCode'],
                'moneris_id'  => $monerisId,
                'busy'        => $parsed['statusCode'] === self::STATUS_BUSY,
            ];
        }

        // If already completed (unlikely for purchase, but handle it)
        if ($parsed['completed']) {
            return $this->finalizeTransaction($monerisId, $parsed, $response);
        }

        // Poll for result
        if (!empty($parsed['receiptUrl'])) {
            return $this->pollForResult($monerisId, $parsed['receiptUrl'], $settings);
        }

        // No receiptUrl and not completed — unexpected
        $this->updateTransaction($monerisId, [
            'full_response' => json_encode($response),
            'completed'     => 1,
        ]);
        return ['success' => false, 'error' => 'Unexpected Moneris response (no receiptUrl)', 'moneris_id' => $monerisId];
    }

    /**
     * Void a previous purchase.
     */
    public function void(string $terminalId, string $originalOrderId, string $username): array {
        $settings = $this->getSettings();
        if (!$settings) {
            return ['success' => false, 'error' => 'Moneris not configured'];
        }

        // Look up the original transaction to get the amount
        $original = $this->findByOrderId($originalOrderId);
        if (!$original) {
            return ['success' => false, 'error' => 'Original Moneris transaction not found'];
        }

        $orderId        = $this->generateUuid();
        $idempotencyKey = $this->generateUuid();

        $payload = [
            'apiVersion'    => '3.0',
            'apiToken'      => $settings['apiToken'],
            'storeId'       => $settings['storeId'],
            'istConfigCode' => $settings['istConfigCode'],
            'polling'       => 'true',
            'dataId'        => $orderId,
            'dataTimestamp'  => date('Y-m-d H:i:s'),
            'data' => [
                'request' => [
                    [
                        'orderId'        => $orderId,
                        'idempotencyKey' => $idempotencyKey,
                        'terminalId'     => $terminalId,
                        'username'       => $username,
                        'action'         => 'void',
                        'totalAmount'    => (string)$original['amount_cents'],
                        'linkId'         => $originalOrderId,
                    ],
                ],
            ],
        ];

        $monerisId = $this->storeTransaction([
            'order_id'        => $orderId,
            'idempotency_key' => $idempotencyKey,
            'terminal_id'     => $terminalId,
            'action'          => 'void',
            'amount_cents'    => (int)$original['amount_cents'],
        ]);

        $response = $this->post($settings['baseUrl'], $payload);
        if (!$response) {
            $this->updateTransaction($monerisId, ['completed' => 1]);
            return ['success' => false, 'error' => 'Failed to connect to Moneris', 'moneris_id' => $monerisId];
        }

        $parsed = $this->parseResponse($response);

        if ($parsed['error']) {
            $this->updateTransaction($monerisId, [
                'status_code'   => $parsed['statusCode'],
                'response_code' => $parsed['responseCode'],
                'cloud_ticket'  => $parsed['cloudTicket'],
                'full_response' => json_encode($response),
                'completed'     => 1,
            ]);
            return [
                'success'     => false,
                'error'       => $parsed['errorMessage'],
                'status_code' => $parsed['statusCode'],
                'moneris_id'  => $monerisId,
                'busy'        => $parsed['statusCode'] === self::STATUS_BUSY,
            ];
        }

        if ($parsed['completed']) {
            return $this->finalizeTransaction($monerisId, $parsed, $response);
        }

        if (!empty($parsed['receiptUrl'])) {
            return $this->pollForResult($monerisId, $parsed['receiptUrl'], $settings);
        }

        $this->updateTransaction($monerisId, ['full_response' => json_encode($response), 'completed' => 1]);
        return ['success' => false, 'error' => 'Unexpected Moneris response', 'moneris_id' => $monerisId];
    }

    /**
     * Card-present refund.
     */
    public function cardPresentRefund(string $terminalId, float $amount, string $originalOrderId, string $username): array {
        $settings = $this->getSettings();
        if (!$settings) {
            return ['success' => false, 'error' => 'Moneris not configured'];
        }

        $orderId        = $this->generateUuid();
        $idempotencyKey = $this->generateUuid();

        $payload = [
            'apiVersion'    => '3.0',
            'apiToken'      => $settings['apiToken'],
            'storeId'       => $settings['storeId'],
            'istConfigCode' => $settings['istConfigCode'],
            'polling'       => 'true',
            'dataId'        => $orderId,
            'dataTimestamp'  => date('Y-m-d H:i:s'),
            'data' => [
                'request' => [
                    [
                        'orderId'        => $orderId,
                        'idempotencyKey' => $idempotencyKey,
                        'terminalId'     => $terminalId,
                        'username'       => $username,
                        'action'         => 'card present refund',
                        'totalAmount'    => (string)$this->dollarsToCents($amount),
                        'linkId'         => $originalOrderId,
                    ],
                ],
            ],
        ];

        $monerisId = $this->storeTransaction([
            'order_id'        => $orderId,
            'idempotency_key' => $idempotencyKey,
            'terminal_id'     => $terminalId,
            'action'          => 'refund',
            'amount_cents'    => $this->dollarsToCents($amount),
        ]);

        $response = $this->post($settings['baseUrl'], $payload);
        if (!$response) {
            $this->updateTransaction($monerisId, ['completed' => 1]);
            return ['success' => false, 'error' => 'Failed to connect to Moneris', 'moneris_id' => $monerisId];
        }

        $parsed = $this->parseResponse($response);

        if ($parsed['error']) {
            $this->updateTransaction($monerisId, [
                'status_code'   => $parsed['statusCode'],
                'response_code' => $parsed['responseCode'],
                'cloud_ticket'  => $parsed['cloudTicket'],
                'full_response' => json_encode($response),
                'completed'     => 1,
            ]);
            return [
                'success'     => false,
                'error'       => $parsed['errorMessage'],
                'status_code' => $parsed['statusCode'],
                'moneris_id'  => $monerisId,
                'busy'        => $parsed['statusCode'] === self::STATUS_BUSY,
            ];
        }

        if ($parsed['completed']) {
            return $this->finalizeTransaction($monerisId, $parsed, $response);
        }

        if (!empty($parsed['receiptUrl'])) {
            return $this->pollForResult($monerisId, $parsed['receiptUrl'], $settings);
        }

        $this->updateTransaction($monerisId, ['full_response' => json_encode($response), 'completed' => 1]);
        return ['success' => false, 'error' => 'Unexpected Moneris response', 'moneris_id' => $monerisId];
    }

    /**
     * Link a Moneris transaction to a POS transaction after the sale is created.
     */
    public function linkToTransaction(int $monerisId, int $posTransactionId): void {
        $this->execute(
            'UPDATE pos_moneris_transactions SET pos_transaction_id = ? WHERE id = ?',
            [$posTransactionId, $monerisId]
        );
    }

    /**
     * Find all Moneris transactions linked to a POS transaction.
     */
    public function findByPosTransaction(int $posTransactionId): array {
        return $this->findAll(
            'SELECT * FROM pos_moneris_transactions WHERE pos_transaction_id = ? ORDER BY id',
            [$posTransactionId]
        );
    }

    /**
     * Find a Moneris transaction by its ID.
     */
    public function findMonerisById(int $id): ?array {
        return $this->findOne(
            'SELECT * FROM pos_moneris_transactions WHERE id = ?',
            [$id]
        );
    }

    /**
     * Find a Moneris transaction by its order_id.
     */
    public function findByOrderId(string $orderId): ?array {
        return $this->findOne(
            'SELECT * FROM pos_moneris_transactions WHERE order_id = ?',
            [$orderId]
        );
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Poll the receiptUrl until completed or timeout.
     */
    private function pollForResult(int $monerisId, string $receiptUrl, array $settings): array {
        set_time_limit(150);

        $start = time();
        while ((time() - $start) < self::POLL_TIMEOUT) {
            sleep(self::POLL_INTERVAL);

            $response = $this->get($receiptUrl);
            if (!$response) {
                continue; // network blip — keep trying
            }

            $parsed = $this->parseResponse($response);

            if ($parsed['completed']) {
                return $this->finalizeTransaction($monerisId, $parsed, $response);
            }
        }

        // Timeout
        $this->updateTransaction($monerisId, ['completed' => 0]);
        return [
            'success'    => false,
            'error'      => 'Moneris terminal did not respond within ' . self::POLL_TIMEOUT . ' seconds',
            'moneris_id' => $monerisId,
            'timeout'    => true,
        ];
    }

    /**
     * Save final transaction result and return a result array.
     */
    private function finalizeTransaction(int $monerisId, array $parsed, array $rawResponse): array {
        $approved = $parsed['statusCode'] === self::STATUS_APPROVED;

        $this->updateTransaction($monerisId, [
            'status_code'          => $parsed['statusCode'],
            'response_code'        => $parsed['responseCode'],
            'auth_code'            => $parsed['authCode'],
            'card_type'            => $parsed['cardType'],
            'masked_pan'           => $parsed['maskedPan'],
            'tender_type'          => $parsed['tenderType'],
            'form_factor'          => $parsed['formFactor'],
            'moneris_txn_id'       => $parsed['transactionId'],
            'cloud_ticket'         => $parsed['cloudTicket'],
            'saf'                  => $parsed['saf'] ? 1 : 0,
            'approved_amount_cents'=> $parsed['approvedAmount'] !== null ? (int)$parsed['approvedAmount'] : null,
            'receipt_text'         => $parsed['receipt'],
            'full_response'        => json_encode($rawResponse),
            'completed'            => 1,
        ]);

        return [
            'success'      => $approved,
            'approved'     => $approved,
            'moneris_id'   => $monerisId,
            'status_code'  => $parsed['statusCode'],
            'status'       => $parsed['status'],
            'auth_code'    => $parsed['authCode'],
            'card_type'    => $parsed['cardType'],
            'masked_pan'   => $parsed['maskedPan'],
            'tender_type'  => $parsed['tenderType'],
            'form_factor'  => $parsed['formFactor'],
            'saf'          => $parsed['saf'],
            'message'      => $approved ? 'Approved' : ($parsed['status'] ?: 'Declined'),
        ];
    }

    /**
     * Parse a Moneris response (handles receipt wrapper and response array).
     */
    private function parseResponse(array $body): array {
        $result = [
            'completed'     => false,
            'error'         => false,
            'errorMessage'  => null,
            'statusCode'    => null,
            'responseCode'  => null,
            'status'        => null,
            'authCode'      => null,
            'cardType'      => null,
            'maskedPan'     => null,
            'tenderType'    => null,
            'formFactor'    => null,
            'transactionId' => null,
            'cloudTicket'   => null,
            'saf'           => false,
            'approvedAmount'=> null,
            'receipt'       => null,
            'receiptUrl'    => null,
        ];

        // Unwrap receipt envelope if present
        $data = $body;
        if (isset($body['receipt'])) {
            $data = $body['receipt'];
        }

        // Get the first response object
        $resp = $data['data']['response'][0] ?? null;
        if (!$resp) {
            // Check for top-level error
            $result['error'] = true;
            $result['statusCode'] = $data['statusCode'] ?? $data['StatusCode'] ?? null;
            $result['errorMessage'] = $data['status'] ?? $data['Status'] ?? 'Unknown error from Moneris';
            $result['completed'] = strtolower($data['Completed'] ?? $data['completed'] ?? 'true') === 'true';
            return $result;
        }

        $result['statusCode']    = $resp['statusCode'] ?? null;
        $result['responseCode']  = $resp['responseCode'] ?? null;
        $result['status']        = $resp['status'] ?? null;
        $result['completed']     = strtolower($resp['completed'] ?? 'false') === 'true';
        $result['cloudTicket']   = $resp['cloudTicket'] ?? $resp['CloudTicket'] ?? null;
        $result['receiptUrl']    = $resp['receiptUrl'] ?? null;

        // Check for errors
        if (!empty($resp['errorDetails'])) {
            $result['error'] = true;
            $errors = array_map(fn($e) => ($e['parameter'] ?? '') . ': ' . ($e['issue'] ?? 'error'), $resp['errorDetails']);
            $result['errorMessage'] = implode('; ', $errors);
            return $result;
        }

        // Terminal busy
        if ($result['statusCode'] === self::STATUS_BUSY) {
            $result['error'] = true;
            $result['errorMessage'] = $result['status'] ?: 'Terminal is busy with another transaction';
            return $result;
        }

        // Completed transaction fields
        if ($result['completed']) {
            $result['authCode']      = $resp['authCode'] ?? null;
            $result['cardType']      = $resp['cardType'] ?? null;
            $result['maskedPan']     = $resp['maskedPan'] ?? null;
            $result['tenderType']    = $resp['tenderType'] ?? null;
            $result['formFactor']    = $resp['formFactor'] ?? null;
            $result['transactionId'] = $resp['transactionId'] ?? null;
            $result['saf']           = strtolower($resp['saf'] ?? 'false') === 'true';
            $result['approvedAmount']= $resp['approvedAmount'] ?? null;
            $result['receipt']       = $resp['receipt'] ?? null;

            // Not approved and not an error we already caught
            if ($result['statusCode'] !== self::STATUS_APPROVED) {
                $result['error'] = false; // let caller check 'approved' field
            }
        }

        return $result;
    }

    /**
     * Store a new pending transaction record.
     */
    private function storeTransaction(array $data): int {
        return (int)$this->insert(
            'INSERT INTO pos_moneris_transactions (order_id, idempotency_key, terminal_id, action, amount_cents)
             VALUES (?, ?, ?, ?, ?)',
            [
                $data['order_id'],
                $data['idempotency_key'],
                $data['terminal_id'],
                $data['action'],
                $data['amount_cents'],
            ]
        );
    }

    /**
     * Update a transaction record with response data.
     */
    private function updateTransaction(int $id, array $fields): void {
        $sets = [];
        $params = [];
        foreach ($fields as $col => $val) {
            $sets[] = "$col = ?";
            $params[] = $val;
        }
        $params[] = $id;
        $this->execute(
            'UPDATE pos_moneris_transactions SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    /**
     * POST JSON to Moneris API.
     */
    private function post(string $url, array $payload): ?array {
        $json = json_encode($payload);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            error_log("Moneris POST failed: $error");
            return null;
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            error_log("Moneris POST non-JSON response (HTTP $httpCode): $result");
            return null;
        }

        return $decoded;
    }

    /**
     * GET a Moneris receipt URL (for polling).
     */
    private function get(string $url): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            error_log("Moneris GET poll failed: $error");
            return null;
        }

        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get Moneris settings, or null if not enabled/configured.
     */
    private function getSettings(): ?array {
        $s = (new PosSetting())->getAll();

        if (($s['moneris_enabled'] ?? '0') !== '1') {
            return null;
        }

        $sandbox = ($s['moneris_sandbox'] ?? '1') === '1';

        return [
            'apiToken'      => $s['moneris_api_token'] ?? '',
            'storeId'       => $s['moneris_store_id'] ?? '',
            'istConfigCode' => $s['moneris_ist_config_code'] ?? '',
            'baseUrl'       => $sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL,
            'sandbox'       => $sandbox,
        ];
    }

    private function dollarsToCents(float $dollars): int {
        return (int)round($dollars * 100);
    }

    private function generateUuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
