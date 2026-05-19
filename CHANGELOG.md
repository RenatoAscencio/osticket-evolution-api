# Changelog

All notable changes to **osTicket Evolution API Notifications** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **`model.updated` deduplication was too aggressive.** The previous logic blocked the entire handler after the first invocation per ticket per request, meaning a status change followed by an assignment change in the same request would only fire the first one. Dedup is now per (ticket, change-kind), so distinct changes each fire exactly once.
- **Admin send pacing actually paces.** The "Delay between admin sends" setting used to be passed as the Evolution API `delay` field in the payload, which only delays delivery on Evolution's side — it did NOT slow our outbound HTTPS calls. The setting now controls a local `usleep()` between consecutive admin sends, which is what avoids burst-hitting Evolution's rate limits.
- **Staff-reply admin notification used the customer-reply template.** Added a separate `tpl_admin_staff_reply` template; the dispatcher falls back to `tpl_admin_user_reply` for installs that haven't been resaved since the upgrade.

### Added
- **HTTP retries** in the Evolution API client. Network errors, 429s, and 5xx are now retried with exponential backoff (1s · 2s · 4s capped at 4s). `Retry-After` (delta-seconds or HTTP-date) is honored when present. Max attempts is configurable via the new "Max HTTP attempts" config field (default 3, set to 1 to disable retries).
- **Configurable customer phone field.** New "Custom phone-field variable name" config field. When set, the plugin reads the customer's phone from that custom field on the Contact Information form rather than the built-in `getPhoneNumber()`/`getPhone()` lookup. Useful when osTicket installs store phone in a separate custom field (e.g. for E.164 normalization upstream).
- **`EvoLogRedactor` extracted as its own library class.** Same redaction behavior as before (phones → last-4-digits, message bodies → `[N chars] preview…`, secrets → `[REDACTED]`) but now independently testable without booting osTicket. Recognizes a new secret key: `token`.

### Tests
- New `tests/SentryReporterTest.php` — 9 assertions covering DSN parsing edge cases and disabled-state safety.
- New `tests/LogRedactorTest.php` — 26 assertions covering phone masking, text preview, secret redaction, recursive contexts, case-insensitive keys, and a realistic end-to-end log scenario.
- Total assertions: 59 across 4 test files (was 24 across 2).
- CI workflow updated to run the new tests on PHP 7.4 / 8.1 / 8.3.

### Added
- Initial Evolution API client (`sendText`, `whatsappNumbers`, `connectionState`).
- Phone number normalization to E.164 with configurable default country code.
- WhatsApp number cache (DB-backed, TTL per result, separate hit/miss TTL).
- Plugin signal handlers for `ticket.created`, `threadentry.created`, and `model.updated` (status/assignee changes).
- Dual-target notifications: client (their WhatsApp) + admin group/numbers.
- **Per-event-per-audience matrix.** Independent toggle for each event × audience combination (client / admin), with master kill-switches for each audience.
- **Customer opt-in field** (`whatsapp_opt_in`, configurable). When the admin adds a checkbox field with the configured variable name to the Contact Information form, the plugin reads it per-ticket and skips customer sends when explicitly opted out. Cross-version-compatible field lookup. Documented in `docs/user-opt-in.md`.
- Two independent message templates per audience per event.
- Optional Sentry integration via lightweight envelope client (no Composer required).
- Local Docker test stack (osTicket v1.18.3 + MariaDB).
- Generic SSH + rsync deploy script (`scripts/deploy.sh`) driven by env vars.
- English + Spanish READMEs and architecture/configuration/Sentry/opt-in docs.

### Security
- `api_key` and `sentry_dsn` are `PasswordField` (masked in admin UI).
- All log levels go through `redactContext()` — phones masked to last-4-digits, message bodies truncated with length prefix, `apikey`/`api_key`/`authorization` keys replaced with `[REDACTED]`.
- New `SECURITY.md` documents threat model, trust boundaries, security controls, accepted risks, and the responsible-disclosure channel.

### UI
- Scoped CSS injected via `FreeTextField` to fix the zero-padding default osTicket ships for plugin admin pages.
- Plain-text textareas on all message templates — WhatsApp doesn't accept HTML.
- Emoji-prefixed section headers and language-stable labels.

[Unreleased]: https://github.com/RenatoAscencio/osticket-evolution-api/commits/main
