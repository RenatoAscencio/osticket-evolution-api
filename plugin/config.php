<?php
/**
 * Admin configuration UI for the Evolution API Notifications plugin.
 *
 * Field labels use specific phrasings (e.g. "WhatsApp recipients") to avoid
 * accidental auto-translation by osTicket's built-in dictionary, which would
 * otherwise mix languages on a single page.
 *
 * @license GPL-2.0-or-later
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class EvolutionApiNotificationsPluginConfig extends PluginConfig {

    /**
     * Plain-text textarea config (no rich-text/Redactor wrapper).
     * WhatsApp does not support HTML, so we must keep templates as raw text.
     */
    private static function plainTextarea($rows = 6, $cols = 60) {
        return array(
            'html' => false,
            'rows' => $rows,
            'cols' => $cols,
        );
    }

    /**
     * Injects scoped CSS into the plugin config page. osTicket renders
     * FreeTextField's `content` configuration as raw HTML, which lets us
     * fix the default zero-padding layout that osTicket ships with for
     * plugin admin pages.
     *
     * The selectors target the plugin config form by descending from the
     * plugin instance config wrapper, so this CSS never leaks into other
     * admin pages.
     */
    private static function styleInjection() {
        return '<style>'
            . '#pluginInstanceForm,'
            . '.plugin-config form,'
            . 'form.plugin-config {'
            . '  padding: 20px 28px 24px 28px;'
            . '}'
            . '#pluginInstanceForm table.form_table th,'
            . '.plugin-config form table.form_table th,'
            . 'form.plugin-config table.form_table th {'
            . '  padding: 18px 0 8px 0;'
            . '}'
            . '#pluginInstanceForm table.form_table td.label,'
            . '.plugin-config form table.form_table td.label,'
            . 'form.plugin-config table.form_table td.label {'
            . '  padding: 8px 16px 8px 4px;'
            . '  vertical-align: top;'
            . '}'
            . '#pluginInstanceForm table.form_table td:not(.label),'
            . '.plugin-config form table.form_table td:not(.label),'
            . 'form.plugin-config table.form_table td:not(.label) {'
            . '  padding: 8px 4px;'
            . '}'
            . '#pluginInstanceForm .section-break,'
            . '.plugin-config .section-break {'
            . '  margin-top: 18px;'
            . '  border-top: 1px solid #e3e3e3;'
            . '  padding-top: 12px;'
            . '}'
            . '#pluginInstanceForm .section-break:first-of-type,'
            . '.plugin-config .section-break:first-of-type {'
            . '  border-top: 0;'
            . '  margin-top: 0;'
            . '}'
            . '#pluginInstanceForm .section-break h3,'
            . '.plugin-config .section-break h3 {'
            . '  margin: 0 0 6px 0;'
            . '  font-size: 1.05em;'
            . '}'
            . '#pluginInstanceForm .section-break em,'
            . '.plugin-config .section-break em {'
            . '  display: block;'
            . '  color: #666;'
            . '  font-style: italic;'
            . '  margin-bottom: 8px;'
            . '  max-width: 880px;'
            . '}'
            . '#pluginInstanceForm .error,'
            . '.plugin-config .error {'
            . '  color: #b94a48;'
            . '  margin-top: 4px;'
            . '}'
            . '</style>';
    }

    function getOptions() {
        return array(

            // ─── CSS injection (renders as raw HTML at top of page) ─────────
            '_styles' => new FreeTextField(array(
                'configuration' => array(
                    'content' => self::styleInjection(),
                ),
            )),

            // ─── Section: Evolution API credentials ─────────────────────────
            'sec_api' => new SectionBreakField(array(
                'label' => '🔌  Evolution API — Connection',
                'hint'  => 'Credentials for your Evolution API instance. After saving, run a connection check from the docs (see plugin README).',
            )),

            'api_base_url' => new TextboxField(array(
                'label'    => 'Base URL',
                'required' => true,
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'     => 'Full URL to your Evolution API instance, no trailing slash. Example: https://evo.example.com',
            )),
            'api_instance' => new TextboxField(array(
                'label'    => 'Instance name',
                'required' => true,
                'configuration' => array('size' => 40, 'length' => 100),
                'hint'     => 'Name of the Evolution API instance that holds the WhatsApp session.',
            )),
            'api_key' => new PasswordField(array(
                'label'    => 'API key',
                'required' => true,
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'     => 'Sent in every request as the `apikey` header. Masked in the UI after saving — leave blank when editing other fields to preserve the existing value.',
            )),
            'api_verify_ssl' => new BooleanField(array(
                'label'   => 'Verify SSL certificate',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Recommended ON in production. Disable only when Evolution API uses a self-signed certificate in a dev environment.',
                ),
            )),
            'api_timeout' => new TextboxField(array(
                'label'    => 'HTTP timeout (seconds)',
                'default'  => '15',
                'configuration' => array('size' => 6, 'length' => 4),
                'hint'     => 'Total time allowed per Evolution API request. Default 15. Minimum effective value 3.',
            )),

            // ─── Section: Phone numbers ─────────────────────────────────────
            'sec_phone' => new SectionBreakField(array(
                'label' => '📞  Phone numbers',
                'hint'  => 'How to normalize and validate the customer phone numbers stored in osTicket before sending.',
            )),

            'default_country_code' => new TextboxField(array(
                'label'    => 'Default country code',
                'default'  => '52',
                'configuration' => array('size' => 6, 'length' => 4),
                'hint'     => 'Digits only, no plus sign. Used when a customer phone has no country code. Examples: 52 Mexico · 1 USA/Canada · 54 Argentina · 57 Colombia.',
            )),
            'verify_whatsapp_before_send' => new BooleanField(array(
                'label'   => 'Check WhatsApp before sending to clients',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Recommended. Calls /chat/whatsappNumbers/{instance} first and skips the send when the number is not on WhatsApp. Results are cached locally to keep performance fast.',
                ),
            )),
            'cache_hit_ttl' => new TextboxField(array(
                'label'   => 'Cache TTL — confirmed numbers (s)',
                'default' => '604800',
                'configuration' => array('size' => 10, 'length' => 9),
                'hint'    => 'How long to remember a number IS on WhatsApp. Default 604800 = 7 days. Longer = fewer API calls; shorter = faster recovery if a number is later removed from WhatsApp.',
            )),
            'cache_miss_ttl' => new TextboxField(array(
                'label'   => 'Cache TTL — non-WhatsApp numbers (s)',
                'default' => '86400',
                'configuration' => array('size' => 10, 'length' => 9),
                'hint'    => 'How long to remember a number is NOT on WhatsApp before re-checking. Default 86400 = 1 day. Shorter so customers who later install WhatsApp eventually get notifications.',
            )),

            // ─── Section: Master recipient toggles ──────────────────────────
            'sec_recipients' => new SectionBreakField(array(
                'label' => '👥  Recipients — master switches',
                'hint'  => 'These are kill-switches that apply to every event. To turn off all customer notifications globally, uncheck "Notify customers". The per-event toggles below only apply when the corresponding master switch is on.',
            )),

            'notify_clients' => new BooleanField(array(
                'label'   => 'Notify customers (end users)',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Master switch for ALL customer notifications. When off, customers never get a WhatsApp message regardless of per-event settings.',
                ),
            )),
            'notify_admins' => new BooleanField(array(
                'label'   => 'Notify staff/admins',
                'default' => true,
                'configuration' => array(
                    'desc' => 'Master switch for ALL admin notifications. When off, the admin numbers below never receive a message.',
                ),
            )),
            'admin_numbers' => new TextareaField(array(
                'label'    => 'Admin WhatsApp numbers',
                'configuration' => self::plainTextarea(3, 60),
                'hint'     => 'One number per line. International format with country code, no plus sign. Example for Mexico: 5215555555555',
            )),

            // ─── Section: User opt-in (privacy) ─────────────────────────────
            'sec_optin' => new SectionBreakField(array(
                'label' => '🙋  User opt-in (per-customer preference)',
                'hint'  => 'Optional. Lets each customer decide whether to receive WhatsApp notifications by toggling a field on their own osTicket profile. To enable this, an admin must add a checkbox field with the variable name below to the "Contact Information" form (Admin Panel → Manage → Forms → Contact Information → Add new field). See docs/user-opt-in.md in the plugin repository for step-by-step instructions.',
            )),

            'respect_user_opt_in' => new BooleanField(array(
                'label'   => 'Respect customer opt-in preference',
                'default' => true,
                'configuration' => array(
                    'desc' => 'When on, look up the custom field below on the customer\'s profile before sending. Skip the send if the customer has explicitly opted out.',
                ),
            )),
            'opt_in_field_variable' => new TextboxField(array(
                'label'    => 'Opt-in field variable name',
                'default'  => 'whatsapp_opt_in',
                'configuration' => array('size' => 40, 'length' => 80),
                'hint'     => 'The "Variable Name" you set on the checkbox field in the user form. Default: whatsapp_opt_in',
            )),
            'opt_in_default_when_absent' => new BooleanField(array(
                'label'   => 'Default to opt-IN when field is absent',
                'default' => true,
                'configuration' => array(
                    'desc' => 'When the customer\'s profile does not contain the opt-in field (e.g. existing customers, admin has not added the field yet, or the customer never edited their profile), what should the plugin assume? Default: opt-IN (send the notification).',
                ),
            )),

            // ─── Section: Per-event notifications matrix ────────────────────
            'sec_events' => new SectionBreakField(array(
                'label' => '🔔  Per-event notification matrix',
                'hint'  => 'Independent toggle per audience per event. Each event sends to customer, admin, both, or neither. Greyed-out combinations (e.g. notifying the customer of their own reply) are not exposed.',
            )),

            // Event: Ticket created
            'evt_ticket_created__client' => new BooleanField(array(
                'label'   => 'Ticket created → notify customer',
                'default' => true,
                'configuration' => array('desc' => 'Customer gets a WhatsApp confirmation that their ticket was received.'),
            )),
            'evt_ticket_created__admin' => new BooleanField(array(
                'label'   => 'Ticket created → notify admins',
                'default' => true,
                'configuration' => array('desc' => 'Admins receive a heads-up for every new ticket.'),
            )),

            // Event: Customer reply (no client notification — the client is the poster)
            'evt_user_reply__admin' => new BooleanField(array(
                'label'   => 'Customer reply → notify admins',
                'default' => true,
                'configuration' => array('desc' => 'Admins are pinged when the customer adds a new message to an existing ticket.'),
            )),

            // Event: Staff reply
            'evt_staff_reply__client' => new BooleanField(array(
                'label'   => 'Staff reply → notify customer',
                'default' => true,
                'configuration' => array('desc' => 'Customer gets a WhatsApp ping when staff replies.'),
            )),
            'evt_staff_reply__admin' => new BooleanField(array(
                'label'   => 'Staff reply → notify admins',
                'default' => false,
                'configuration' => array('desc' => 'Rare. Off by default — staff who replied already knows. Turn on only for ops teams that want a complete audit trail to a shared group.'),
            )),

            // Event: Status changed
            'evt_status_changed__client' => new BooleanField(array(
                'label'   => 'Status changed → notify customer',
                'default' => true,
                'configuration' => array('desc' => 'Customer gets a ping when the ticket moves between statuses (Open / Resolved / Closed / etc.).'),
            )),
            'evt_status_changed__admin' => new BooleanField(array(
                'label'   => 'Status changed → notify admins',
                'default' => false,
                'configuration' => array('desc' => 'Off by default — usually only useful for ops dashboards.'),
            )),

            // Event: Assignment changed (no client side — internal ops)
            'evt_assignment_changed__admin' => new BooleanField(array(
                'label'   => 'Assignment changed → notify admins',
                'default' => false,
                'configuration' => array('desc' => 'Admins are pinged when a ticket is assigned or reassigned to a staff member or team. Off by default.'),
            )),

            // ─── Section: Templates ─────────────────────────────────────────
            'sec_templates' => new SectionBreakField(array(
                'label' => '✉️  Message templates',
                'hint'  => 'Plain text only — WhatsApp does not support HTML. WhatsApp formatting: *bold*, _italic_, ~strikethrough~, `monospace`. Placeholders: {{ticket_number}} {{subject}} {{name}} {{email}} {{department}} {{priority}} {{status}} {{assignee}} {{poster_type}} {{message}} {{ticket_link}}. Use {{var|fallback}} for default values.',
            )),

            'tpl_client_created' => new TextareaField(array(
                'label'   => 'To customer — ticket created',
                'default' => "Hello {{name}}, we received your ticket *#{{ticket_number}}* — _{{subject}}_.\n\nOne of our agents will get back to you shortly.\n\nReference: {{ticket_link}}",
                'configuration' => self::plainTextarea(6, 60),
            )),
            'tpl_client_staff_reply' => new TextareaField(array(
                'label'   => 'To customer — staff replied',
                'default' => "Hello {{name}}, there is a new reply on your ticket *#{{ticket_number}}*.\n\n{{message}}\n\n{{ticket_link}}",
                'configuration' => self::plainTextarea(6, 60),
            )),
            'tpl_client_status' => new TextareaField(array(
                'label'   => 'To customer — status changed',
                'default' => "Ticket *#{{ticket_number}}* status changed to *{{status}}*.\n\n{{ticket_link}}",
                'configuration' => self::plainTextarea(4, 60),
            )),
            'tpl_admin_created' => new TextareaField(array(
                'label'   => 'To admin — ticket created',
                'default' => "*New ticket #{{ticket_number}}*\n*Subject:* {{subject}}\n*From:* {{name}} <{{email}}>\n*Department:* {{department}}\n*Priority:* {{priority}}\n\n{{message}}\n\n{{ticket_link}}",
                'configuration' => self::plainTextarea(8, 60),
            )),
            'tpl_admin_user_reply' => new TextareaField(array(
                'label'   => 'To admin — customer replied',
                'default' => "*Reply on ticket #{{ticket_number}}*\n*From:* {{name}} ({{poster_type}})\n\n{{message}}\n\n{{ticket_link}}",
                'configuration' => self::plainTextarea(6, 60),
            )),
            'tpl_admin_status' => new TextareaField(array(
                'label'   => 'To admin — status changed',
                'default' => "Ticket *#{{ticket_number}}* → *{{status}}* (assignee: {{assignee|—}})\n\n{{ticket_link}}",
                'configuration' => self::plainTextarea(4, 60),
            )),
            'tpl_admin_assignment' => new TextareaField(array(
                'label'   => 'To admin — assignment changed',
                'default' => "Ticket *#{{ticket_number}}* assigned to *{{assignee}}*.\n\n{{ticket_link}}",
                'configuration' => self::plainTextarea(4, 60),
            )),

            // ─── Section: Misc ──────────────────────────────────────────────
            'sec_misc' => new SectionBreakField(array(
                'label' => '🌐  Links & pacing',
            )),

            'base_url' => new TextboxField(array(
                'label'   => 'osTicket base URL',
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'    => 'Required if any template uses {{ticket_link}}. Example: https://tickets.example.com — no trailing slash.',
            )),
            'send_delay_ms' => new TextboxField(array(
                'label'   => 'Delay between admin sends (ms)',
                'default' => '0',
                'configuration' => array('size' => 8, 'length' => 6),
                'hint'    => 'Pacing between consecutive admin sends to avoid Evolution API rate limits. 0 disables pacing. 500–1000 is a safe value when you have many admin numbers.',
            )),

            // ─── Section: Sentry (optional) ─────────────────────────────────
            'sec_sentry' => new SectionBreakField(array(
                'label' => '🛡️  Sentry — error reporting (optional)',
                'hint'  => 'Leave the DSN blank to disable Sentry entirely. With a DSN set, plugin errors and Evolution API failures are reported automatically.',
            )),

            'sentry_dsn' => new PasswordField(array(
                'label'   => 'Sentry DSN',
                'configuration' => array('size' => 80, 'length' => 300),
                'hint'    => 'Format: https://<key>@<host>/<project_id> (e.g. https://abc123…@o0.ingest.sentry.io/12345). Masked in the UI — contains a secret key.',
            )),
            'sentry_environment' => new TextboxField(array(
                'label'   => 'Sentry environment',
                'default' => 'production',
                'configuration' => array('size' => 20, 'length' => 40),
                'hint'    => 'Free-form tag. Common values: production, staging, dev.',
            )),
            'sentry_capture_global' => new BooleanField(array(
                'label'   => 'Also capture global PHP errors',
                'default' => false,
                'configuration' => array(
                    'desc' => 'Register a global error/exception handler so Sentry sees PHP errors from anywhere in osTicket (not just this plugin). Off by default to avoid noise on shared hosting.',
                ),
            )),

            // ─── Section: Debug ─────────────────────────────────────────────
            'sec_debug' => new SectionBreakField(array(
                'label' => '🐛  Debug',
            )),

            'debug_mode' => new BooleanField(array(
                'label'   => 'Verbose logging',
                'default' => false,
                'configuration' => array(
                    'desc' => 'Add detailed debug/info lines to the PHP error log. Errors and warnings are always logged regardless of this toggle.',
                ),
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        if (isset($config['api_base_url'])) {
            $u = trim($config['api_base_url']);
            if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                $errors['api_base_url'] = 'Base URL must start with http:// or https://.';
                return false;
            }
            $config['api_base_url'] = rtrim($u, '/');
        }

        if (isset($config['default_country_code'])) {
            $cc = preg_replace('/\D+/', '', (string) $config['default_country_code']);
            if ($cc === '' || strlen($cc) > 4) {
                $errors['default_country_code'] = 'Country code must be 1–4 digits.';
                return false;
            }
            $config['default_country_code'] = $cc;
        }

        if (isset($config['admin_numbers'])) {
            $raw   = preg_split('/\r?\n/', (string) $config['admin_numbers']);
            $clean = array();
            foreach ($raw as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $digits = preg_replace('/\D+/', '', $line);
                if (strlen($digits) < 8 || strlen($digits) > 15) {
                    $errors['admin_numbers'] = sprintf('Invalid admin number "%s" — must be 8–15 digits.', $line);
                    return false;
                }
                $clean[] = $digits;
            }
            $config['admin_numbers'] = implode("\n", $clean);
        }

        if (isset($config['sentry_dsn'])) {
            $dsn = trim($config['sentry_dsn']);
            if ($dsn !== '' && !preg_match('#^https?://[^@]+@[^/]+/\d+$#', $dsn)) {
                $errors['sentry_dsn'] = 'Sentry DSN format looks invalid. Expected: https://<key>@<host>/<project_id>.';
                return false;
            }
            $config['sentry_dsn'] = $dsn;
        }

        return true;
    }
}
