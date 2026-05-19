# Security Policy

## Supported versions

This project is pre-1.0 and not yet stable. Security fixes are applied to
the `main` branch only. Once a `1.0.0` is tagged, security backports will
be considered for the latest minor release.

## Reporting a vulnerability

**Do not** open a public GitHub issue for security problems.

Email a description to: `5641902+RenatoAscencio@users.noreply.github.com`
(GitHub will route to the project owner). Include:

- A description of the issue and its potential impact.
- Steps to reproduce, or a minimal proof-of-concept.
- Your contact info if you'd like to be credited in the fix.

Expect acknowledgement within 5 business days and a triage decision within
10. Critical issues will be patched and released as soon as a fix is
verified; non-critical issues batch with the next regular release.

## Threat model

The plugin runs **inside an osTicket admin context** and makes **outbound
HTTPS** calls to:

1. **Evolution API** — for sending WhatsApp messages and verifying whether
   a phone number is on WhatsApp.
2. **Sentry** (optional, only if a DSN is configured) — for error reports.

It also **reads from and writes to** the osTicket database:

- **Reads:** the ticket and user the signal fired on, the plugin's own
  configuration rows, and the WhatsApp cache table.
- **Writes:** the WhatsApp cache table (`<prefix>evolution_wa_cache`).

It does **not**:

- Open inbound network listeners.
- Modify osTicket core tables.
- Serve HTTP routes itself.
- Process untrusted input from the public ticket form except via osTicket's
  own validated fields (phone number, user name, ticket subject/body).

## Trust boundaries

| Boundary | Trust assumption |
| -------- | ---------------- |
| osTicket admin user | **Fully trusted.** Can change all plugin config including Base URL, API key, admin numbers, Sentry DSN. SSRF against internal hosts via Base URL is in-scope-but-accepted — admin equivalent to "able to install plugins" already implies code execution on the host. |
| osTicket end users (ticket creators) | Untrusted. Their input flows through phone normalization (digits-only), template rendering (no string interpolation into HTTP headers or shell), and never executes. |
| Evolution API | Trusted to the extent the admin trusts the WhatsApp gateway operator. Responses are parsed as JSON only; non-JSON ignored. |
| Sentry endpoint | Trusted ingest. We POST JSON envelopes and ignore the response body. |
| Other tenants on the same host | Untrusted. Web error log on shared hosting can be readable — see "PII handling" below. |

## Security controls in place

### Authentication / authorization

- Plugin config is gated by osTicket admin auth and CSRF (osTicket-provided).
- API key for Evolution API is stored as `PasswordField` (masked in UI).
- Sentry DSN is stored as `PasswordField` (masked in UI).

### Input validation

- Phone numbers are normalized to digits-only via `EvoPhoneNumberNormalizer`
  before being sent to Evolution API. Numbers shorter than 8 or longer than
  15 digits are rejected.
- Admin number list is validated on save (length 8–15 digits per line).
- Base URL must start with `http://` or `https://`.
- Sentry DSN is regex-validated on save.
- Country code is normalized to 1–4 digits.

### SQL injection

- `WhatsAppNumberCache` constructs SQL with concatenated values. All
  user-provided values pass through osTicket's `db_real_escape()` before
  interpolation. Numeric values are cast to `int`.
- All other DB access goes through osTicket's ORM/helpers.

### XSS

- All field labels, hints, and defaults are static strings controlled by
  this plugin. osTicket escapes user-entered values when rendering.
- The CSS injection in `config.php` uses `FreeTextField` with `content`
  (renders raw HTML by design) and only includes a static `<style>` block
  scoped to the plugin config form selectors.

### Outbound HTTP

- All outbound calls use cURL with SSL verification **on by default**.
  Admins can disable verification (for self-signed certs) but the UI warns
  against it for production.
- Connect timeout 10s, total timeout configurable (3–N seconds).
- API key is sent only via the `apikey` HTTP header, never in URLs or
  query strings.
- Sentry events are sent only when a valid DSN is configured.

### PII handling in logs

- Debug logs **redact phone numbers** (only the last 4 digits are kept) and
  **truncate message bodies** (first 40 characters + length marker). API
  keys / authorization headers are replaced with `[REDACTED]`.
- Error and warning logs follow the same redaction rules.
- Sentry events include only metadata tags (event name, exception class,
  HTTP status). Ticket bodies and phone numbers are **never** sent to Sentry.

### Caching

- The WhatsApp cache table contains phone numbers and a boolean
  (on/off WhatsApp). It does **not** store message bodies. Hit/miss TTLs
  are configurable.
- Cache writes use `REPLACE INTO`, which is atomic — no race conditions
  between concurrent ticket requests.

### Error handling

- All signal handlers wrap their body in `try/catch`. An exception in this
  plugin never bubbles up to the osTicket request thread.
- When Sentry capture-global is on, a global exception handler reports
  to Sentry and re-throws — it does not swallow.

## Known limitations (accepted risks)

| # | Risk | Why accepted |
|---|------|--------------|
| 1 | An admin can point Base URL at an internal address (SSRF) | Admin trust already implies host code execution. |
| 2 | An admin can set a Sentry DSN that exfiltrates structured event data to a host they control | Same trust assumption as (1). |
| 3 | If `Verify SSL certificate` is off, MITM is possible between the osTicket host and Evolution API | Opt-in dev convenience; clearly warned in the UI. |
| 4 | Web error log can be readable by other tenants on shared hosting | Mitigated by PII redaction; truly sensitive content (full message body, full phone, API key) is never written. |
| 5 | Templates can contain arbitrary text the admin defined | The recipient (customer/admin) is already in a trust relationship with the admin. |

## Hardening recommendations for operators

- Set a strong Sentry DSN (long random key, project-specific).
- Disable `Capture global PHP errors` unless you know you want osTicket-wide
  error reporting.
- Restrict outbound network access from the osTicket host to only the
  Evolution API and Sentry hosts (egress firewall).
- Rotate the Evolution API key periodically.
- Audit the `<prefix>evolution_wa_cache` table size; the plugin auto-prunes
  expired rows when `WhatsAppNumberCache::pruneExpired()` is called, but it
  is not called on a schedule by default.

## Out of scope

- Vulnerabilities in osTicket core (report to https://github.com/osTicket/osTicket).
- Vulnerabilities in Evolution API (report to https://github.com/EvolutionAPI/evolution-api).
- Vulnerabilities in Sentry SDK / ingest (we use a minimal client; report
  upstream for the official SDK).
