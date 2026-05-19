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

    public function __construct($baseUrl, $instance, $apiKey) {
        $this->baseUrl  = rtrim((string) $baseUrl, '/');
        $this->instance = (string) $instance;
        $this->apiKey   = (string) $apiKey;
    }

    public function setVerifySsl($verify) { $this->verifySsl = (bool) $verify; }
    public function setTimeout($seconds)  { $this->timeout = (int) $seconds; }
    public function setLogger($cb)        { $this->logger = is_callable($cb) ? $cb : null; }

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
     * Low-level HTTP request via cURL. Returns a uniform result envelope:
     *   array(ok, status, body, error)
     */
    private function call($method, $path, $payload = null) {
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

        return array(
            'ok'     => $ok,
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : null,
            'error'  => $ok ? null : ('HTTP ' . $status . ': ' . substr((string) $raw, 0, 200)),
        );
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
