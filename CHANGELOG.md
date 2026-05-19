# Changelog

All notable changes to **osTicket Evolution API Notifications** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
