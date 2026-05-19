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

class EvolutionApiNotificationsPlugin extends Plugin {

    var $config_class = 'EvolutionApiNotificationsPluginConfig';

    /** @var EvolutionApiClient */
    private $api;
    /** @var WhatsAppNumberCache */
    private $waCache;
    /** @var EvoSentryReporter */
    private $sentry;
    /** @var array<string,bool> de-dupe per request for status change handler */
    private $statusHandled = array();

    function bootstrap() {
        $cfg = $this->getConfig();

        $this->sentry = new EvoSentryReporter($cfg->get('sentry_dsn'));
        $this->sentry->setEnvironment($cfg->get('sentry_environment') ?: 'production');
        $this->sentry->addTag('plugin', 'evolution-api-notifications');

        if ($cfg->get('sentry_capture_global') && $this->sentry->isEnabled()) {
            $this->installGlobalSentryHandlers();
        }

        if ($cfg->get('evt_ticket_created')) {
            Signal::connect('ticket.created',     array($this, 'onTicketCreated'));
        }
        if ($cfg->get('evt_user_reply') || $cfg->get('evt_staff_reply')) {
            Signal::connect('threadentry.created', array($this, 'onThreadEntryCreated'));
        }
        if ($cfg->get('evt_status_changed') || $cfg->get('evt_assignment_changed')) {
            // osTicket emits model.updated on Ticket; handler inspects what changed.
            Signal::connect('model.updated', array($this, 'onModelUpdated'));
        }
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
        if (!$cfg->get('evt_ticket_created')) { return; }
        try {
            $vars = $this->ticketVars($ticket);
            $vars['message'] = $this->firstMessage($ticket);

            if ($cfg->get('notify_clients')) {
                $this->sendToClient($ticket, $cfg->get('tpl_client_created'), $vars);
            }
            if ($cfg->get('notify_admins')) {
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

            if ($isStaff && $cfg->get('evt_staff_reply')) {
                if ($cfg->get('notify_clients')) {
                    $this->sendToClient($ticket, $cfg->get('tpl_client_staff_reply'), $vars);
                }
            }
            if ($isUser && $cfg->get('evt_user_reply')) {
                if ($cfg->get('notify_admins')) {
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
        if (isset($this->statusHandled[$tid])) {
            return;
        }
        $this->statusHandled[$tid] = true;

        try {
            $dirty = method_exists($model, 'dirty') ? $model->dirty : array();
            if (!is_array($dirty)) { $dirty = array(); }

            $statusChanged = isset($dirty['status_id']);
            $assigneeChanged = isset($dirty['staff_id']) || isset($dirty['team_id']);

            $vars = $this->ticketVars($model);

            if ($statusChanged && $cfg->get('evt_status_changed')) {
                if ($cfg->get('notify_clients')) {
                    $this->sendToClient($model, $cfg->get('tpl_client_status'), $vars);
                }
                if ($cfg->get('notify_admins')) {
                    $this->sendToAdmins($cfg->get('tpl_admin_status'), $vars);
                }
            }
            if ($assigneeChanged && $cfg->get('evt_assignment_changed')) {
                if ($cfg->get('notify_admins')) {
                    $this->sendToAdmins($cfg->get('tpl_admin_assignment'), $vars);
                }
            }
        } catch (Exception $e) {
            $this->report($e, array('event' => 'model.updated'));
        }
    }

    // ─── Senders ─────────────────────────────────────────────────────────────

    private function sendToClient($ticket, $template, array $vars) {
        $cfg = $this->getConfig();
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

        $delay = max(0, (int) $cfg->get('send_delay_ms'));
        foreach ($list as $i => $phone) {
            $this->dispatchSend($phone, $text, $i > 0 && $delay > 0 ? array('delay' => $delay) : array());
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

    private function getUserPhone(Ticket $ticket) {
        try {
            if (method_exists($ticket, 'getOwner')) {
                $owner = $ticket->getOwner();
                if ($owner && method_exists($owner, 'getPhoneNumber')) {
                    $p = $owner->getPhoneNumber();
                    if ($p) { return (string) $p; }
                }
                if ($owner && method_exists($owner, 'getPhone')) {
                    $p = $owner->getPhone();
                    if ($p) { return (string) $p; }
                }
            }
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
            $line .= ' ' . json_encode($ctx);
        }
        error_log($line);
    }
}
