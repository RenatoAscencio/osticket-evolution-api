# osTicket Evolution API Notifications

[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt)
[![osTicket](https://img.shields.io/badge/osTicket-%E2%89%A5%201.17-orange.svg)](https://github.com/osTicket/osTicket)
[![Evolution API](https://img.shields.io/badge/Evolution_API-v2-green.svg)](https://doc.evolution-api.com/)

> *Read this in [Spanish / Español](./README.es.md).*

osTicket plugin that sends **WhatsApp** notifications via [Evolution API](https://doc.evolution-api.com/) to both **end users** and **administrators** on the ticket lifecycle events you choose, verifying first that the client's phone actually has WhatsApp.

---

## Features

- **Send to clients and admins independently.** End users get messages on their WhatsApp; admins receive them on a configurable list of numbers (one or many).
- **Pre-flight WhatsApp check.** Before sending to a client, the plugin asks Evolution API whether the number exists on WhatsApp — and caches the answer. No more wasted sends or weird delivery errors.
- **Per-event-per-audience matrix.** Independent toggle for each combination, e.g. "Ticket created → notify customer ON, → notify admins ON" vs "Status changed → notify customer ON, → notify admins OFF":
  - Ticket created → customer / admin
  - Customer reply → admin
  - Staff reply → customer / admin
  - Status changed → customer / admin
  - Assignment changed → admin
- **Per-customer opt-in (privacy).** Optional: add a checkbox to the user form so each customer can toggle WhatsApp notifications themselves from their osTicket profile. See [docs/user-opt-in.md](./docs/user-opt-in.md).
- **Dual templates.** Each event has separate message templates for client and admin audiences, so the tone and content can differ.
- **Phone normalization.** Accepts any format users enter (spaces, hyphens, `+`, parentheses, leading zeros, national trunk prefixes) and normalizes to E.164 digits-only form.
- **Credentials masked.** API key and Sentry DSN are `PasswordField`s — never visible in the admin UI after saving.
- **PII redaction in logs.** Phone numbers and message bodies are masked/truncated in the PHP error log even with verbose logging on. Safe for shared hosting.
- **Optional Sentry integration.** Plugin errors — and optionally every PHP error in osTicket — can be reported to your Sentry project for triage. No Composer required (uses a minimal envelope client).
- **Security-reviewed.** See [SECURITY.md](./SECURITY.md) for the threat model, trust boundaries, and accepted risks.
- **Designed for upstream PR.** Code style and license (GPL-2.0) match the official `osTicket/osTicket-plugins` repo so it can be contributed back.

---

## Quick start

### 1. Install Evolution API

If you don't already have a running Evolution API instance, follow the [official docs](https://doc.evolution-api.com/). You will need:

- Base URL (e.g. `https://evo.example.com`)
- Instance name (e.g. `support`)
- API key

### 2. Drop the plugin into osTicket

```bash
# From the repo root:
rsync -av plugin/ /path/to/osticket/include/plugins/evolution-api/
```

Then in the osTicket admin panel: **Manage → Plugins → Add New Plugin**, find *Evolution API Notifications (WhatsApp)*, install it, and click into it to configure.

For a guided walk-through (Docker test, production deploy, screenshots) see [INSTALL.md](./INSTALL.md).

### 3. Configure

Required:

| Section | Field | Notes |
| ------- | ----- | ----- |
| Evolution API | Base URL, Instance, API key | All three are required. |
| Phone numbers | Default country code | Digits only, no `+`. Used when a user phone has no country code. |
| Recipients | Admin WhatsApp numbers | One per line, with country code, no `+`. |
| Misc | osTicket base URL | Used to render `{{ticket_link}}` inside messages. |

Optional but recommended:

- **Verify WhatsApp existence before sending to clients** (default on)
- **Sentry DSN** to catch plugin errors in production
- **Verbose logging** while you're verifying it works, off in production

---

## How it works

```
osTicket signal (ticket.created / threadentry.created / model.updated)
         │
         ▼
  EvolutionApiNotificationsPlugin            ◄── per-event toggles + dual templates
         │
   ┌─────┴─────┐
   ▼           ▼
 Client      Admins
 (1 phone)   (N phones)
   │           │
   ▼           ▼
 PhoneNumberNormalizer  ─► WhatsAppNumberCache  ─► EvolutionApiClient
                                                       │
                                                       ▼
                                                Evolution API (HTTP)
                                                       │
                                                       ▼
                                                 WhatsApp Cloud
```

Errors surface to PHP's error log (always) and to Sentry (when a DSN is configured).

More detail: [docs/architecture.md](./docs/architecture.md).

---

## Project layout

```
osticket-evolution-api/
├── plugin/                       The actual osTicket plugin (copied as-is into include/plugins/evolution-api/)
│   ├── plugin.php                Plugin manifest
│   ├── config.php                Admin UI fields, validation
│   ├── evolution.php             Main class — signal handlers, dispatcher
│   └── lib/
│       ├── PhoneNumberNormalizer.php
│       ├── EvolutionApiClient.php
│       ├── WhatsAppNumberCache.php
│       ├── TemplateRenderer.php
│       └── SentryReporter.php
├── docs/                         Architecture, configuration, Sentry, deploy
├── docker/                       Local osTicket + MariaDB test stack
├── scripts/                      Deploy + build helpers
├── tests/                        Unit tests (no osTicket required)
└── .github/                      CI + issue templates
```

---

## Local testing

The `docker/` folder ships a one-command stack with osTicket 1.18.3 + MariaDB and the plugin bind-mounted in:

```bash
cd docker
docker compose up -d
# osTicket: http://localhost:8081
# Admin:    http://localhost:8081/scp/
```

See [docker/README.md](./docker/README.md) for first-run install instructions and how to point it at Evolution API.

---

## Production deploy

The repository includes a generic deploy script driven by environment variables:

```bash
cp scripts/.env.example scripts/.env       # gitignored
$EDITOR scripts/.env                       # fill in REMOTE, REMOTE_PLUGIN_DIR, etc.
source scripts/.env && ./scripts/deploy.sh --dry-run
source scripts/.env && ./scripts/deploy.sh
```

Step-by-step in [docs/deploy-production.md](./docs/deploy-production.md).

---

## Sentry integration

Two scopes of Sentry capture, both opt-in:

1. **Plugin-scoped** (always available when a DSN is set): the plugin reports its own exceptions and Evolution API failures to Sentry, with helpful tags (`event`, `endpoint`, `status`).
2. **osTicket-wide** (`Capture global PHP errors` toggle): registers a `set_error_handler` + `set_exception_handler` + shutdown handler so Sentry sees PHP errors from anywhere in osTicket, not just from this plugin.

The Sentry client in this plugin is intentionally minimal (a single envelope POST). For richer features — performance, breadcrumbs, sessions — install the official `sentry/sentry` SDK via Composer and rewire `SentryReporter` to delegate to it.

See [docs/sentry-integration.md](./docs/sentry-integration.md).

---

## Roadmap

- [ ] Send via Evolution API "send" queue with optional `presence` typing indicator.
- [ ] Inbound message handling (route incoming WhatsApp replies into the ticket thread).
- [ ] Per-department recipient overrides.
- [ ] Per-staff WhatsApp notifications (DM the assignee).
- [ ] Media (image / audio / file) message support.
- [ ] PHPUnit suite that exercises the dispatcher with mocked HTTP.
- [ ] PR to `osTicket/osTicket-plugins` once feature-stable.

---

## Contributing

PRs welcome — see [CONTRIBUTING.md](./CONTRIBUTING.md). The short version: keep style consistent with the existing osTicket-plugins idioms, write a test if you touch `lib/`, and update both READMEs (en + es) if you add a user-visible option.

---

## License

[GPL-2.0-or-later](./LICENSE). Compatible with [osTicket](https://github.com/osTicket/osTicket) and the official [osTicket/osTicket-plugins](https://github.com/osTicket/osTicket-plugins) repository.
