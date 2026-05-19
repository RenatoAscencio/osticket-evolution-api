<?php
/**
 * Admin configuration UI for the Evolution API Notifications plugin.
 *
 * @license GPL-2.0-or-later
 */

require_once INCLUDE_DIR . 'class.plugin.php';
require_once INCLUDE_DIR . 'class.forms.php';

class EvolutionApiNotificationsPluginConfig extends PluginConfig {

    function getOptions() {
        return array(

            // ─── Section: Evolution API credentials ───────────────────────────
            'sec_api' => new SectionBreakField(array(
                'label' => __('Evolution API — Credentials'),
            )),

            'api_base_url' => new TextboxField(array(
                'label'    => __('Base URL'),
                'required' => true,
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'     => __('Full URL to your Evolution API instance (e.g. https://evo.example.com). No trailing slash.'),
            )),
            'api_instance' => new TextboxField(array(
                'label'    => __('Instance name'),
                'required' => true,
                'configuration' => array('size' => 40, 'length' => 100),
                'hint'     => __('Name of the Evolution API instance (the WhatsApp connection).'),
            )),
            'api_key' => new TextboxField(array(
                'label'    => __('API key'),
                'required' => true,
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'     => __('Sent as the `apikey` header.'),
            )),
            'api_verify_ssl' => new BooleanField(array(
                'label'   => __('Verify SSL certificate'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Disable ONLY for self-signed dev environments.'),
                ),
            )),
            'api_timeout' => new TextboxField(array(
                'label'    => __('HTTP timeout (seconds)'),
                'default'  => '15',
                'configuration' => array('size' => 6, 'length' => 4),
                'hint'     => __('Total time allowed per Evolution API request.'),
            )),

            // ─── Section: Phone / number handling ─────────────────────────────
            'sec_phone' => new SectionBreakField(array(
                'label' => __('Phone numbers'),
            )),

            'default_country_code' => new TextboxField(array(
                'label'    => __('Default country code'),
                'default'  => '52',
                'configuration' => array('size' => 6, 'length' => 4),
                'hint'     => __('Digits only (no "+"). Used when a user phone has no country code. Example: 52 for Mexico.'),
            )),
            'verify_whatsapp_before_send' => new BooleanField(array(
                'label'   => __('Check WhatsApp existence before sending to clients'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Calls /chat/whatsappNumbers/{instance} first and skips if the number is not on WhatsApp. Results are cached.'),
                ),
            )),
            'cache_hit_ttl' => new TextboxField(array(
                'label'   => __('Cache TTL for confirmed WhatsApp numbers (seconds)'),
                'default' => '604800',
                'configuration' => array('size' => 10, 'length' => 9),
                'hint'    => __('How long to remember a number IS on WhatsApp. Default 7 days.'),
            )),
            'cache_miss_ttl' => new TextboxField(array(
                'label'   => __('Cache TTL for not-on-WhatsApp numbers (seconds)'),
                'default' => '86400',
                'configuration' => array('size' => 10, 'length' => 9),
                'hint'    => __('How long to remember a number is NOT on WhatsApp before re-checking. Default 1 day.'),
            )),

            // ─── Section: Recipients ──────────────────────────────────────────
            'sec_recipients' => new SectionBreakField(array(
                'label' => __('Recipients'),
            )),

            'notify_clients' => new BooleanField(array(
                'label'   => __('Notify clients (end users)'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Send messages to the WhatsApp number associated with the ticket user.'),
                ),
            )),
            'notify_admins' => new BooleanField(array(
                'label'   => __('Notify admins'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Send messages to the admin number(s) below on every event.'),
                ),
            )),
            'admin_numbers' => new TextareaField(array(
                'label'    => __('Admin WhatsApp numbers'),
                'configuration' => array('rows' => 3, 'cols' => 60),
                'hint'     => __('One number per line (with country code, no "+"). Example: 5215555555555.'),
            )),

            // ─── Section: Per-event toggles ───────────────────────────────────
            'sec_events' => new SectionBreakField(array(
                'label' => __('Events to notify on'),
            )),

            'evt_ticket_created' => new BooleanField(array(
                'label'   => __('Ticket created'),
                'default' => true,
            )),
            'evt_user_reply' => new BooleanField(array(
                'label'   => __('Client/user reply on ticket'),
                'default' => true,
            )),
            'evt_staff_reply' => new BooleanField(array(
                'label'   => __('Staff reply on ticket'),
                'default' => true,
            )),
            'evt_status_changed' => new BooleanField(array(
                'label'   => __('Ticket status changed (open / closed / resolved)'),
                'default' => true,
            )),
            'evt_assignment_changed' => new BooleanField(array(
                'label'   => __('Ticket assignment changed'),
                'default' => false,
            )),

            // ─── Section: Templates ───────────────────────────────────────────
            'sec_templates' => new SectionBreakField(array(
                'label' => __('Message templates'),
                'hint'  => __('Variables: {{ticket_number}} {{subject}} {{name}} {{email}} {{department}} {{priority}} {{status}} {{assignee}} {{poster_type}} {{message}} {{ticket_link}}'),
            )),

            'tpl_client_created' => new TextareaField(array(
                'label'   => __('To client — ticket created'),
                'default' => "Hello {{name}}, we received your ticket *#{{ticket_number}}* — _{{subject}}_.\n\nOne of our agents will get back to you shortly.\n\nReference: {{ticket_link}}",
                'configuration' => array('rows' => 6, 'cols' => 60),
            )),
            'tpl_client_staff_reply' => new TextareaField(array(
                'label'   => __('To client — staff replied'),
                'default' => "Hello {{name}}, there is a new reply on your ticket *#{{ticket_number}}*.\n\n{{message}}\n\n{{ticket_link}}",
                'configuration' => array('rows' => 6, 'cols' => 60),
            )),
            'tpl_client_status' => new TextareaField(array(
                'label'   => __('To client — status changed'),
                'default' => "Ticket *#{{ticket_number}}* status changed to *{{status}}*.\n\n{{ticket_link}}",
                'configuration' => array('rows' => 4, 'cols' => 60),
            )),
            'tpl_admin_created' => new TextareaField(array(
                'label'   => __('To admin — ticket created'),
                'default' => "*New ticket #{{ticket_number}}*\n*Subject:* {{subject}}\n*From:* {{name}} <{{email}}>\n*Department:* {{department}}\n*Priority:* {{priority}}\n\n{{message}}\n\n{{ticket_link}}",
                'configuration' => array('rows' => 8, 'cols' => 60),
            )),
            'tpl_admin_user_reply' => new TextareaField(array(
                'label'   => __('To admin — user replied'),
                'default' => "*Reply on ticket #{{ticket_number}}*\n*From:* {{name}} ({{poster_type}})\n\n{{message}}\n\n{{ticket_link}}",
                'configuration' => array('rows' => 6, 'cols' => 60),
            )),
            'tpl_admin_status' => new TextareaField(array(
                'label'   => __('To admin — status changed'),
                'default' => "Ticket *#{{ticket_number}}* → *{{status}}* (assignee: {{assignee|—}})\n\n{{ticket_link}}",
                'configuration' => array('rows' => 4, 'cols' => 60),
            )),
            'tpl_admin_assignment' => new TextareaField(array(
                'label'   => __('To admin — assignment changed'),
                'default' => "Ticket *#{{ticket_number}}* assigned to *{{assignee}}*.\n\n{{ticket_link}}",
                'configuration' => array('rows' => 4, 'cols' => 60),
            )),

            // ─── Section: Misc ────────────────────────────────────────────────
            'sec_misc' => new SectionBreakField(array(
                'label' => __('Misc'),
            )),

            'base_url' => new TextboxField(array(
                'label'   => __('osTicket base URL'),
                'configuration' => array('size' => 60, 'length' => 200),
                'hint'    => __('Used to build clickable links inside messages (e.g. https://tickets.example.com).'),
            )),
            'send_delay_ms' => new TextboxField(array(
                'label'   => __('Delay between messages (ms)'),
                'default' => '0',
                'configuration' => array('size' => 8, 'length' => 6),
                'hint'    => __('Optional pacing between consecutive sends to avoid rate limits.'),
            )),

            // ─── Section: Sentry (optional) ────────────────────────────────────
            'sec_sentry' => new SectionBreakField(array(
                'label' => __('Sentry (optional)'),
            )),

            'sentry_dsn' => new TextboxField(array(
                'label'   => __('Sentry DSN'),
                'configuration' => array('size' => 80, 'length' => 300),
                'hint'    => __('If set, plugin errors and (optionally) global osTicket errors are reported to Sentry. Leave blank to disable.'),
            )),
            'sentry_environment' => new TextboxField(array(
                'label'   => __('Sentry environment'),
                'default' => 'production',
                'configuration' => array('size' => 20, 'length' => 40),
            )),
            'sentry_capture_global' => new BooleanField(array(
                'label'   => __('Capture global PHP errors'),
                'default' => false,
                'configuration' => array(
                    'desc' => __('When enabled, registers a global error/exception handler that reports to Sentry. Useful for catching osTicket-wide issues, not just plugin ones.'),
                ),
            )),

            // ─── Section: Debug ───────────────────────────────────────────────
            'sec_debug' => new SectionBreakField(array(
                'label' => __('Debug'),
            )),

            'debug_mode' => new BooleanField(array(
                'label'   => __('Verbose logging'),
                'default' => false,
                'configuration' => array(
                    'desc' => __('Write detailed log lines to the system PHP error log.'),
                ),
            )),
        );
    }

    function pre_save(&$config, &$errors) {
        if (isset($config['api_base_url'])) {
            $u = trim($config['api_base_url']);
            if ($u !== '' && !preg_match('#^https?://#i', $u)) {
                $errors['api_base_url'] = __('Base URL must start with http:// or https://.');
                return false;
            }
            $config['api_base_url'] = rtrim($u, '/');
        }

        if (isset($config['default_country_code'])) {
            $cc = preg_replace('/\D+/', '', (string) $config['default_country_code']);
            if ($cc === '' || strlen($cc) > 4) {
                $errors['default_country_code'] = __('Country code must be 1–4 digits.');
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
                    $errors['admin_numbers'] = sprintf(__('Invalid admin number "%s" — must be 8–15 digits.'), $line);
                    return false;
                }
                $clean[] = $digits;
            }
            $config['admin_numbers'] = implode("\n", $clean);
        }

        if (isset($config['sentry_dsn'])) {
            $dsn = trim($config['sentry_dsn']);
            if ($dsn !== '' && !preg_match('#^https?://[^@]+@[^/]+/\d+$#', $dsn)) {
                $errors['sentry_dsn'] = __('Sentry DSN format looks invalid. Expected: https://<key>@<host>/<project>.');
                return false;
            }
            $config['sentry_dsn'] = $dsn;
        }

        return true;
    }
}
