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
- Per-event enable/disable toggles in admin UI.
- Two independent message templates (client-facing / admin-facing).
- Optional Sentry integration via lightweight envelope client (no Composer required).
- Local Docker test stack (osTicket v1.18.3 + MariaDB).
- Generic SSH + rsync deploy script (`scripts/deploy.sh`) driven by env vars.
- English + Spanish READMEs and architecture/configuration/Sentry docs.

[Unreleased]: https://github.com/RenatoAscencio/osticket-evolution-api/commits/main
