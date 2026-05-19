<?php
/**
 * Main plugin class: hooks osTicket signals and dispatches WhatsApp
 * notifications via Evolution API.
 *
 * @license GPL-2.0-or-later
 */

require_once INCLUDE_DIR . 'class.signal.php';
require_once INCLUDE_DIR . 'class.plugin.php';
require_once 'config.php';
require_once dirname(__FILE__) . '/lib/PhoneNumberNormalizer.php';
require_once dirname(__FILE__) . '/lib/TemplateRenderer.php';
require_once dirname(__FILE__) . '/lib/EvolutionApiClient.php';
require_once dirname(__FILE__) . '/lib/WhatsAppNumberCache.php';
require_once dirname(__FILE__) . '/lib/SentryReporter.php';
require_once dirname(__FILE__) . '/lib/LogRedactor.php';

class EvolutionApiNotificationsPlugin extends Plugin {

    var $config_class = 'EvolutionApiNotificationsPluginConfig';

    /** @var EvolutionApiClient */
    private $api;
    /** @var WhatsAppNumberCache */
    private $waCache;
    /** @var EvoSentryReporter */
    private $sentry;
    /**
     * Per-request de-dupe for model.updated. Keyed by "<ticketId>:<changeType>"
     * where changeType is "status" or "assignment". osTicket can emit
     * `model.updated` multiple times for the same save(); we only want one
     * notification per actual change kind, while still allowing different
     * change kinds in the same request to each fire.
     *
     * @var array<string,bool>
     */
    private $sentInRequest = array();

    function bootstrap() {
        $cfg = $this->getConfig();

        $this->sentry = new EvoSentryReporter($cfg->get('sentry_dsn'));
        $this->sentry->setEnvironment($cfg->get('sentry_environment') ?: 'production');
        $this->sentry->addTag('plugin', 'evolution-api-notifications');

        if ($cfg->get('sentry_capture_global') && $this->sentry->isEnabled()) {
            $this->installGlobalSentryHandlers();
        }

        if ($this->anyOn('evt_ticket_created__client', 'evt_ticket_created__admin')) {
            Signal::connect('ticket.created',     array($this, 'onTicketCreated'));
        }
        if ($this->anyOn('evt_user_reply__admin', 'evt_staff_reply__client', 'evt_staff_reply__admin')) {
            Signal::connect('threadentry.created', array($this, 'onThreadEntryCreated'));
        }
        if ($this->anyOn('evt_status_changed__client', 'evt_status_changed__admin', 'evt_assignment_changed__admin')) {
            // osTicket emits model.updated on Ticket; handler inspects what changed.
            Signal::connect('model.updated', array($this, 'onModelUpdated'));
        }
    }

    /** Helper: returns true if any of the listed config flags is truthy. */
    private function anyOn(/* ...keys */) {
        $cfg = $this->getConfig();
        foreach (func_get_args() as $k) {
            if ($cfg->get($k)) { return true; }
        }
        return false;
    }

    /** True if both the master switch AND the per-event flag are on. */
    private function clientShouldFire($eventKey) {
        $cfg = $this->getConfig();
        return $cfg->get('notify_clients') && $cfg->get($eventKey . '__client');
    }

    /** True if both the master switch AND the per-event flag are on. */
    private function adminShouldFire($eventKey) {
        $cfg = $this->getConfig();
        return $cfg->get('notify_admins') && $cfg->get($eventKey . '__admin');
    }

    // ─── Lazy dependencies ───────────────────────────────────────────────────

    private function api() {
        if ($this->api !== null) {
            return $this->api;
        }
        $cfg = $this->getConfig();
        $client = new EvolutionApiClient(
            $cfg->get('api_base_url'),
            $cfg->get('api_instance'),
            $cfg->get('api_key')
        );
        $client->setVerifySsl((bool) $cfg->get('api_verify_ssl'));
        $client->setTimeout(max(3, (int) $cfg->get('api_timeout')));
        $client->setMaxAttempts(max(1, (int) ($cfg->get('http_max_attempts') ?: 3)));
        $self = $this;
        $client->setLogger(function ($lvl, $msg, $ctx) use ($self) {
            $self->log($lvl, '[api] ' . $msg, $ctx);
        });
        $this->api = $client;
        return $this->api;
    }

    private function waCache() {
        if ($this->waCache === null) {
            $cfg = $this->getConfig();
            $this->waCache = new WhatsAppNumberCache(
                max(60, (int) $cfg->get('cache_hit_ttl')),
                max(60, (int) $cfg->get('cache_miss_ttl'))
            );
        }
        return $this->waCache;
    }

    // ─── Signal handlers ─────────────────────────────────────────────────────

    function onTicketCreated($ticket) {
        $cfg = $this->getConfig();
        try {
            $vars = $this->ticketVars($ticket);
            $vars['message'] = $this->firstMessage($ticket);

            if ($this->clientShouldFire('evt_ticket_created')) {
                $this->sendToClient($ticket, $cfg->get('tpl_client_created'), $vars);
            }
            if ($this->adminShouldFire('evt_ticket_created')) {
                $this->sendToAdmins($cfg->get('tpl_admin_created'), $vars);
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'ticket.created'));
        }
    }

    function onThreadEntryCreated($entry) {
        $cfg = $this->getConfig();
        try {
            $thread = method_exists($entry, 'getThread') ? $entry->getThread() : null;
            if (!$thread) { return; }
            $ticket = $thread->getObject();
            if (!$ticket || !($ticket instanceof Ticket)) { return; }

            $posterType = $this->posterType($entry);
            $isStaff = $posterType === 'staff';
            $isUser  = $posterType === 'user' || $posterType === 'collaborator';

            $vars = $this->ticketVars($ticket);
            $vars['poster_type'] = ucfirst($posterType);
            $vars['name']        = $this->posterName($entry, $vars['name']);
            $vars['message']     = EvoTemplateRenderer::truncate(
                EvoTemplateRenderer::htmlToWhatsappText($entry->getBody()), 2500
            );

            if ($isStaff) {
                if ($this->clientShouldFire('evt_staff_reply')) {
                    $this->sendToClient($ticket, $cfg->get('tpl_client_staff_reply'), $vars);
                }
                if ($this->adminShouldFire('evt_staff_reply')) {
                    $tpl = $cfg->get('tpl_admin_staff_reply');
                    // Backwards compat: fall back to the old combined template
                    // if an existing install hasn't been resaved since this
                    // template was added.
                    if ($tpl === null || $tpl === '') {
                        $tpl = $cfg->get('tpl_admin_user_reply');
                    }
                    $this->sendToAdmins($tpl, $vars);
                }
            }
            if ($isUser) {
                if ($this->adminShouldFire('evt_user_reply')) {
                    $this->sendToAdmins($cfg->get('tpl_admin_user_reply'), $vars);
                }
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'threadentry.created'));
        }
    }

    function onModelUpdated($model) {
        if (!($model instanceof Ticket)) {
            return;
        }
        $cfg = $this->getConfig();
        $tid = $model->getId();

        try {
            $dirty = method_exists($model, 'dirty') ? $model->dirty : array();
            if (!is_array($dirty)) { $dirty = array(); }

            $statusChanged   = isset($dirty['status_id'])
                && !$this->markOnce($tid, 'status');
            $assigneeChanged = (isset($dirty['staff_id']) || isset($dirty['team_id']))
                && !$this->markOnce($tid, 'assignment');

            if (!$statusChanged && !$assigneeChanged) {
                return;
            }

            $vars = $this->ticketVars($model);

            if ($statusChanged) {
                if ($this->clientShouldFire('evt_status_changed')) {
                    $this->sendToClient($model, $cfg->get('tpl_client_status'), $vars);
                }
                if ($this->adminShouldFire('evt_status_changed')) {
                    $this->sendToAdmins($cfg->get('tpl_admin_status'), $vars);
                }
            }
            if ($assigneeChanged) {
                if ($this->adminShouldFire('evt_assignment_changed')) {
                    $this->sendToAdmins($cfg->get('tpl_admin_assignment'), $vars);
                }
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'model.updated'));
        }
    }

    /**
     * Returns true if we already handled this (ticket, changeKind) tuple
     * in the current request. Otherwise marks it and returns false.
     */
    private function markOnce($ticketId, $changeKind) {
        $key = $ticketId . ':' . $changeKind;
        if (isset($this->sentInRequest[$key])) {
            return true;
        }
        $this->sentInRequest[$key] = true;
        return false;
    }

    // ─── Senders ─────────────────────────────────────────────────────────────

    private function sendToClient($ticket, $template, array $vars) {
        $cfg = $this->getConfig();

        // Honor the customer's opt-in preference (if the feature is enabled).
        if ($cfg->get('respect_user_opt_in')) {
            $optIn = $this->userOptedIn($ticket);
            if ($optIn === false) {
                $this->log('info', 'Customer opted out of WhatsApp notifications — skipping ticket #' . $vars['ticket_number']);
                return;
            }
            // $optIn === null means the field is absent; fall back to default.
        }

        $rawPhone = $this->getUserPhone($ticket);
        if (!$rawPhone) {
            $this->log('debug', 'No phone for ticket #' . $vars['ticket_number']);
            return;
        }
        $phone = EvoPhoneNumberNormalizer::normalize($rawPhone, $cfg->get('default_country_code'));
        if (!$phone) {
            $this->log('debug', 'Could not normalize phone "' . $rawPhone . '"');
            return;
        }

        if ($cfg->get('verify_whatsapp_before_send')) {
            $exists = $this->isOnWhatsApp($phone);
            if ($exists === false) {
                $this->log('info', 'Phone ' . $phone . ' is NOT on WhatsApp — skipping client notification');
                return;
            }
            // exists === null → unknown (API failure). Fail open: try to send anyway.
        }

        $text = EvoTemplateRenderer::render($template, $vars);
        $text = EvoTemplateRenderer::truncate($text, 3500);
        $this->dispatchSend($phone, $text);
    }

    private function sendToAdmins($template, array $vars) {
        $cfg = $this->getConfig();
        $raw = (string) $cfg->get('admin_numbers');
        $list = array();
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') { $list[] = $line; }
        }
        if (!$list) {
            $this->log('debug', 'notify_admins is on but no admin numbers configured');
            return;
        }

        $cc = $cfg->get('default_country_code');
        $list = EvoPhoneNumberNormalizer::normalizeMany($list, $cc);
        if (!$list) { return; }

        $text = EvoTemplateRenderer::render($template, $vars);
        $text = EvoTemplateRenderer::truncate($text, 3500);

        $delayMs = max(0, (int) $cfg->get('send_delay_ms'));
        foreach ($list as $i => $phone) {
            if ($i > 0 && $delayMs > 0) {
                // usleep takes microseconds. Pace local outbound HTTPS calls
                // so we don't burst Evolution's rate limit when fanning out to
                // many admin numbers.
                usleep($delayMs * 1000);
            }
            $this->dispatchSend($phone, $text);
        }
    }

    private function dispatchSend($phone, $text, array $opts = array()) {
        $res = $this->api()->sendText($phone, $text, $opts);
        if (!$res['ok']) {
            $this->log('error', 'sendText failed', array(
                'phone' => $phone, 'status' => $res['status'], 'error' => $res['error'],
            ));
            $this->sentry->captureMessage(
                'Evolution API sendText failed: ' . $res['error'],
                'error',
                array('tags' => array('endpoint' => 'sendText', 'status' => (string) $res['status']))
            );
        } else {
            $this->log('info', 'sendText ok', array('phone' => $phone, 'status' => $res['status']));
        }
    }

    private function isOnWhatsApp($phone) {
        $cache = $this->waCache();
        $hit = $cache->get($phone);
        if ($hit !== null) {
            $this->log('debug', 'wa-cache ' . ($hit ? 'HIT' : 'MISS-CACHED') . ' for ' . $phone);
            return $hit;
        }
        $res = $this->api()->isOnWhatsApp($phone);
        if ($res === null) {
            $this->log('warning', 'whatsappNumbers lookup failed for ' . $phone . ' — failing open');
            return null;
        }
        $cache->put($phone, $res);
        return $res;
    }

    // ─── Data extraction helpers ─────────────────────────────────────────────

    private function ticketVars(Ticket $ticket) {
        $deptName = '—';
        try { $d = $ticket->getDept(); if ($d) { $deptName = $d->getName(); } } catch (Exception $e) {}

        $priName = 'Normal';
        try { $p = $ticket->getPriority(); if ($p) { $priName = $p->getDesc(); } } catch (Exception $e) {}

        $statusName = '—';
        try {
            if (method_exists($ticket, 'getStatus')) {
                $s = $ticket->getStatus();
                if (is_object($s) && method_exists($s, 'getName')) {
                    $statusName = $s->getName();
                } elseif ($s) {
                    $statusName = (string) $s;
                }
            }
        } catch (Exception $e) {}

        $assignee = '';
        try {
            if (method_exists($ticket, 'getAssignee')) {
                $a = $ticket->getAssignee();
                if (is_object($a) && method_exists($a, 'getName')) {
                    $assignee = (string) $a->getName();
                } elseif (is_string($a)) {
                    $assignee = $a;
                }
            }
        } catch (Exception $e) {}

        $email = '';
        try { if (method_exists($ticket, 'getEmail')) { $email = (string) $ticket->getEmail(); } } catch (Exception $e) {}

        $name = '';
        try { if (method_exists($ticket, 'getName')) { $name = (string) $ticket->getName(); } } catch (Exception $e) {}

        return array(
            'ticket_number' => $ticket->getNumber(),
            'subject'       => method_exists($ticket, 'getSubject') ? (string) $ticket->getSubject() : '',
            'name'          => $name ?: '—',
            'email'         => $email,
            'department'    => $deptName,
            'priority'      => $priName,
            'status'        => $statusName,
            'assignee'      => $assignee,
            'ticket_link'   => $this->ticketLink($ticket),
        );
    }

    private function ticketLink(Ticket $ticket) {
        $base = trim((string) $this->getConfig()->get('base_url'));
        if ($base === '') { return ''; }
        $base = rtrim($base, '/');
        return $base . '/scp/tickets.php?id=' . (int) $ticket->getId();
    }

    private function firstMessage(Ticket $ticket) {
        try {
            $thread = $ticket->getThread();
            if (!$thread) { return ''; }
            $entries = $thread->getEntries();
            if (!$entries) { return ''; }
            $first = $entries[0];
            return EvoTemplateRenderer::truncate(
                EvoTemplateRenderer::htmlToWhatsappText($first->getBody()), 2500
            );
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Read the customer's opt-in preference from a custom field on their
     * osTicket user profile. The admin defines a checkbox field with a
     * configurable variable name (default `whatsapp_opt_in`) on the
     * Contact Information form.
     *
     * Returns:
     *   true  → customer explicitly opted in
     *   false → customer explicitly opted out (skip the send)
     *   null  → no preference set (fall back to opt_in_default_when_absent)
     */
    private function userOptedIn(Ticket $ticket) {
        $cfg = $this->getConfig();
        $variable = trim((string) $cfg->get('opt_in_field_variable'));
        if ($variable === '') { $variable = 'whatsapp_opt_in'; }

        $defaultWhenAbsent = (bool) $cfg->get('opt_in_default_when_absent');

        try {
            $owner = method_exists($ticket, 'getOwner') ? $ticket->getOwner() : null;
            if (!$owner) {
                return $defaultWhenAbsent ? null : false;
            }
            $value = $this->readUserCustomField($owner, $variable);
            if ($value === null) {
                // Field not present on user profile.
                return $defaultWhenAbsent ? null : false;
            }
            return $this->coerceBool($value);
        } catch (Exception $e) {
            $this->log('warning', 'Failed reading opt-in field "' . $variable . '" — failing open', array('exception' => $e->getMessage()));
            return null;
        }
    }

    /**
     * Walk a user's dynamic form entries and return the raw value of the
     * field with the given variable name. Returns null when not found.
     */
    private function readUserCustomField($user, $variable) {
        // Different osTicket versions expose this differently. Try the most
        // common APIs in order of preference.

        // (1) Newer API: User::getForms() returns an iterable of DynamicFormEntry.
        if (method_exists($user, 'getForms')) {
            try {
                $forms = $user->getForms();
                if ($forms) {
                    foreach ($forms as $entry) {
                        $v = $this->extractFieldFromEntry($entry, $variable);
                        if ($v !== null) { return $v; }
                    }
                }
            } catch (Exception $e) { /* fall through to next strategy */ }
        }

        // (2) Older API: User::getDynamicData() returns an array of entries.
        if (method_exists($user, 'getDynamicData')) {
            try {
                $entries = $user->getDynamicData();
                if ($entries) {
                    foreach ($entries as $entry) {
                        $v = $this->extractFieldFromEntry($entry, $variable);
                        if ($v !== null) { return $v; }
                    }
                }
            } catch (Exception $e) { /* fall through */ }
        }

        // (3) Some plugins/skins attach a getInfo() helper.
        if (method_exists($user, 'getInfo')) {
            try {
                $info = $user->getInfo();
                if (is_array($info) && array_key_exists($variable, $info)) {
                    return $info[$variable];
                }
            } catch (Exception $e) { /* fall through */ }
        }

        return null;
    }

    private function extractFieldFromEntry($entry, $variable) {
        if (!is_object($entry)) { return null; }

        // FormEntry typically exposes getField($variable_name).
        if (method_exists($entry, 'getField')) {
            try {
                $field = $entry->getField($variable);
                if ($field && method_exists($field, 'getClean')) {
                    return $field->getClean();
                }
                if ($field && method_exists($field, 'getValue')) {
                    return $field->getValue();
                }
            } catch (Exception $e) { /* fall through */ }
        }

        // Some entries expose getAnswers() → iterable of {field, value}.
        if (method_exists($entry, 'getAnswers')) {
            try {
                foreach ($entry->getAnswers() as $answer) {
                    if (!is_object($answer)) { continue; }
                    $field = method_exists($answer, 'getField') ? $answer->getField() : null;
                    $name = $field && method_exists($field, 'get') ? $field->get('name') : null;
                    if ($name === $variable) {
                        if (method_exists($answer, 'getValue')) {
                            return $answer->getValue();
                        }
                    }
                }
            } catch (Exception $e) { /* fall through */ }
        }

        return null;
    }

    private function coerceBool($value) {
        if (is_bool($value)) { return $value; }
        if (is_numeric($value)) { return ((int) $value) !== 0; }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if ($v === '' || $v === '0' || $v === 'false' || $v === 'no' || $v === 'off') { return false; }
            return true;
        }
        if (is_array($value)) {
            // Checkbox fields in osTicket return [1] when checked, [] when unchecked.
            return !empty($value);
        }
        return (bool) $value;
    }

    private function getUserPhone(Ticket $ticket) {
        try {
            $owner = method_exists($ticket, 'getOwner') ? $ticket->getOwner() : null;

            // Strategy 1: custom user-form field if configured.
            $customVar = trim((string) $this->getConfig()->get('phone_field_variable'));
            if ($customVar !== '' && $owner) {
                $v = $this->readUserCustomField($owner, $customVar);
                if ($v !== null && $v !== '' && !is_array($v)) {
                    return (string) $v;
                }
            }

            // Strategy 2: built-in getters on User.
            if ($owner) {
                if (method_exists($owner, 'getPhoneNumber')) {
                    $p = $owner->getPhoneNumber();
                    if ($p) { return (string) $p; }
                }
                if (method_exists($owner, 'getPhone')) {
                    $p = $owner->getPhone();
                    if ($p) { return (string) $p; }
                }
            }

            // Strategy 3: ticket-level phone (legacy/embedded form data).
            if (method_exists($ticket, 'getPhoneNumber')) {
                $p = $ticket->getPhoneNumber();
                if ($p) { return (string) $p; }
            }
        } catch (Exception $e) {}
        return null;
    }

    private function posterType($entry) {
        try {
            $p = $entry->getPoster();
            if ($p instanceof Staff)        { return 'staff'; }
            if ($p instanceof User)         { return 'user'; }
            if ($p instanceof Collaborator) { return 'collaborator'; }
        } catch (Exception $e) {}
        return 'system';
    }

    private function posterName($entry, $fallback) {
        try {
            $p = $entry->getPoster();
            if (is_object($p) && method_exists($p, 'getName')) {
                $n = (string) $p->getName();
                if ($n !== '') { return $n; }
            }
            if (method_exists($entry, 'getName')) {
                $n = (string) $entry->getName();
                if ($n !== '') { return $n; }
            }
        } catch (Exception $e) {}
        return $fallback ?: '—';
    }

    // ─── Sentry + logging plumbing ───────────────────────────────────────────

    private function installGlobalSentryHandlers() {
        $sentry = $this->sentry;
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($sentry) {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            $sentry->captureMessage(
                sprintf('PHP %d: %s at %s:%d', $errno, $errstr, $errfile, $errline),
                $errno === E_NOTICE || $errno === E_USER_NOTICE ? 'info' : 'error'
            );
            return false; // Don't suppress osTicket's own handling.
        });
        set_exception_handler(function ($e) use ($sentry) {
            $sentry->captureException($e);
            throw $e;
        });
        register_shutdown_function(function () use ($sentry) {
            $err = error_get_last();
            if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
                $sentry->captureMessage(
                    sprintf('FATAL: %s at %s:%d', $err['message'], $err['file'], $err['line']),
                    'fatal'
                );
            }
        });
    }

    public function report($throwable, array $ctx = array()) {
        $this->log('error', $throwable->getMessage(), $ctx + array('class' => get_class($throwable)));
        if ($this->sentry) {
            $this->sentry->captureException($throwable, array('tags' => $ctx));
        }
    }

    public function log($level, $msg, $ctx = array()) {
        $cfg = $this->getConfig();
        $debug = (bool) $cfg->get('debug_mode');
        if (!$debug && !in_array($level, array('error', 'warning'), true)) {
            return;
        }
        $line = '[EvolutionApiNotifications][' . strtoupper($level) . '] ' . $msg;
        if (!empty($ctx)) {
            $line .= ' ' . json_encode(EvoLogRedactor::context($ctx));
        }
        error_log($line);
    }
}
