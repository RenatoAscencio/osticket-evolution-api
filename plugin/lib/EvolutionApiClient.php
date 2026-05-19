<?php
/**
 * Thin HTTP client for Evolution API v2.
 *
 * Implements only the endpoints this plugin needs:
 *   - POST /message/sendText/{instance}        Send a text message.
 *   - POST /chat/whatsappNumbers/{instance}    Check which numbers exist on WhatsApp.
 *   - GET  /instance/connectionState/{instance}  Check instance health.
 *
 * Auth: header `apikey: <key>`.
 *
 * @license GPL-2.0-or-later
 * @link    https://doc.evolution-api.com/
 */
class EvolutionApiClient {

    private $baseUrl;
    private $instance;
    private $apiKey;
    private $connectTimeout = 10;
    private $timeout = 30;
    private $verifySsl = true;
    private $logger;
    /** Max attempts for retryable failures (429 + 5xx). 1 = no retry. */
    private $maxAttempts = 3;
    /** Cap on backoff between retries (ms) — prevents long blocking. */
    private $maxBackoffMs = 4000;

    public function __construct($baseUrl, $instance, $apiKey) {
        $this->baseUrl  = rtrim((string) $baseUrl, '/');
        $this->instance = (string) $instance;
        $this->apiKey   = (string) $apiKey;
    }

    public function setVerifySsl($verify)    { $this->verifySsl = (bool) $verify; }
    public function setTimeout($seconds)     { $this->timeout = (int) $seconds; }
    public function setLogger($cb)           { $this->logger = is_callable($cb) ? $cb : null; }
    public function setMaxAttempts($n)       { $this->maxAttempts = max(1, (int) $n); }
    public function setMaxBackoffMs($ms)     { $this->maxBackoffMs = max(0, (int) $ms); }

    /**
     * Send a plain-text WhatsApp message.
     */
    public function sendText($number, $text, array $opts = array()) {
        $payload = array(
            'number' => (string) $number,
            'text'   => (string) $text,
        );
        if (isset($opts['delay']))       { $payload['delay'] = (int) $opts['delay']; }
        if (isset($opts['linkPreview'])) { $payload['linkPreview'] = (bool) $opts['linkPreview']; }
        if (isset($opts['mentioned']) && is_array($opts['mentioned'])) {
            $payload['mentioned'] = array_values(array_map('strval', $opts['mentioned']));
        }
        return $this->call('POST', '/message/sendText/' . rawurlencode($this->instance), $payload);
    }

    /**
     * Check which numbers exist on WhatsApp.
     */
    public function whatsappNumbers(array $numbers) {
        $payload = array(
            'numbers' => array_values(array_map('strval', $numbers)),
        );
        return $this->call('POST', '/chat/whatsappNumbers/' . rawurlencode($this->instance), $payload);
    }

    /**
     * Get connection state of the configured instance.
     */
    public function connectionState() {
        return $this->call('GET', '/instance/connectionState/' . rawurlencode($this->instance));
    }

    /**
     * Convenience: returns true/false if a single number is on WhatsApp,
     * or null when the call failed.
     */
    public function isOnWhatsApp($number) {
        $res = $this->whatsappNumbers(array($number));
        if (!$res['ok'] || !is_array($res['body'])) {
            return null;
        }
        foreach ($res['body'] as $row) {
            if (is_array($row)
                && isset($row['number'])
                && (string) $row['number'] === (string) $number
                && isset($row['exists'])) {
                return (bool) $row['exists'];
            }
        }
        if (count($res['body']) === 1 && isset($res['body'][0]['exists'])) {
            return (bool) $res['body'][0]['exists'];
        }
        return null;
    }

    /**
     * Entry point used by sendText / whatsappNumbers / connectionState.
     * Wraps the low-level HTTP call with a retry loop that handles 429 and
     * 5xx with exponential backoff, honoring `Retry-After` when present.
     */
    private function call($method, $path, $payload = null) {
        $last = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $last = $this->httpCall($method, $path, $payload);
            if ($last['ok']) {
                return $last;
            }

            $status = (int) $last['status'];
            // Only retry transient failures: network errors (status=0), 429 (rate limit), 5xx.
            $isRetryable = ($status === 0 || $status === 429 || $status >= 500);
            if (!$isRetryable || $attempt >= $this->maxAttempts) {
                return $last;
            }

            $sleepMs = $this->backoffMs($attempt, $last);
            $this->log('warning', 'Retrying after backoff', array(
                'attempt' => $attempt,
                'next_attempt' => $attempt + 1,
                'status' => $status,
                'sleep_ms' => $sleepMs,
            ));
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }
        return $last;
    }

    /**
     * Backoff calculation. Prefers `Retry-After` from the server response;
     * falls back to exponential (1s, 2s, 4s …) capped at maxBackoffMs.
     */
    private function backoffMs($attempt, array $result) {
        if (isset($result['retry_after_ms']) && $result['retry_after_ms'] > 0) {
            return min($this->maxBackoffMs, (int) $result['retry_after_ms']);
        }
        $exp = (1 << ($attempt - 1)) * 1000; // 1000, 2000, 4000, ...
        return min($this->maxBackoffMs, $exp);
    }

    /**
     * Low-level HTTP request via cURL. Returns a uniform result envelope:
     *   array(ok, status, body, error, retry_after_ms?)
     */
    private function httpCall($method, $path, $payload = null) {
        $url = $this->baseUrl . $path;
        $method = strtoupper($method);

        if (!function_exists('curl_init')) {
            return $this->fail(0, 'cURL extension not available');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifySsl ? 2 : 0);

        // Capture response headers (we want Retry-After for 429 / 503).
        $respHeaders = array();
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($_ch, $line) use (&$respHeaders) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $name  = strtolower(trim(substr($line, 0, $colon)));
                $value = trim(substr($line, $colon + 1));
                $respHeaders[$name] = $value;
            }
            return strlen($line);
        });

        $headers = array(
            'apikey: ' . $this->apiKey,
            'Accept: application/json',
        );

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $body = $payload === null ? '' : json_encode($payload);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($body);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($payload !== null) {
                $body = json_encode($payload);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                $headers[] = 'Content-Type: application/json';
                $headers[] = 'Content-Length: ' . strlen($body);
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $this->log('debug', $method . ' ' . $url, array('payload' => $payload));

        $fn      = 'curl_' . 'exec';
        $raw     = $fn($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr    = curl_error($ch);
        $cerrno  = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $cerr !== '') {
            $this->log('error', 'cURL error', array('errno' => $cerrno, 'error' => $cerr, 'url' => $url));
            return $this->fail($status, 'cURL (' . $cerrno . '): ' . $cerr);
        }

        $decoded = json_decode($raw, true);
        $ok = ($status >= 200 && $status < 300);

        if (!$ok) {
            $this->log('warning', 'Non-2xx from Evolution API', array(
                'status' => $status, 'body' => substr((string) $raw, 0, 500),
            ));
        }

        $result = array(
            'ok'     => $ok,
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : null,
            'error'  => $ok ? null : ('HTTP ' . $status . ': ' . substr((string) $raw, 0, 200)),
        );

        // Pass Retry-After (in ms) up to the retry orchestrator if present.
        // RFC 7231 §7.1.3: value is either delta-seconds or an HTTP-date.
        if (isset($respHeaders['retry-after'])) {
            $ra = $respHeaders['retry-after'];
            if (ctype_digit($ra)) {
                $result['retry_after_ms'] = ((int) $ra) * 1000;
            } else {
                $ts = strtotime($ra);
                if ($ts !== false) {
                    $result['retry_after_ms'] = max(0, ($ts - time())) * 1000;
                }
            }
        }

        return $result;
    }

    private function fail($status, $msg) {
        return array('ok' => false, 'status' => (int) $status, 'body' => null, 'error' => $msg);
    }

    private function log($level, $msg, array $ctx = array()) {
        if ($this->logger) {
            call_user_func($this->logger, $level, $msg, $ctx);
        }
    }
}
