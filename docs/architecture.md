# Architecture

## Goals

1. **Idempotent and safe.** Never crash osTicket. If Evolution API is down, log + report to Sentry, never throw to the request thread.
2. **Drop-in deployable.** No Composer required. Files copy into `include/plugins/evolution-api/` and the plugin manifest is auto-discovered.
3. **Testable in isolation.** Pure logic (normalizer, template renderer, DSN parser) is in `plugin/lib/` and exercised by `tests/` without needing osTicket loaded.
4. **Forward-compatible with upstream PR.** Conventions match `osTicket/osTicket-plugins`: `plugin.php` returns metadata, `config.php` defines `getOptions()`, main class extends `Plugin`.

## High-level flow

```
                ┌──────────────────────────────────────────────────────────────┐
                │  osTicket runtime                                            │
                │                                                              │
                │   ticket.created ───┐                                        │
                │   threadentry.created ──┐                                    │
                │   model.updated (Ticket) ──┐                                 │
                │                            ▼                                 │
                │           EvolutionApiNotificationsPlugin                    │
                │                ▲                       │                    │
                │                │                       ▼                    │
                │     getConfig()│              ┌──────────────────┐           │
                │                │              │  ticketVars()    │           │
                │                │              │  (subject, name, │           │
                │                │              │   priority, etc) │           │
                │                │              └─────────┬────────┘           │
                │                │                        │                   │
                │                │                        ▼                   │
                │                │              ┌──────────────────┐          │
                │                │              │ EvoTemplate-     │          │
                │                │              │ Renderer.render()│          │
                │                │              └─────────┬────────┘          │
                │                │                        │                   │
                │                │      ┌─────────────────┼──────────────┐    │
                │                │      ▼                                ▼    │
                │                │  sendToClient(...)              sendToAdmins(...)
                │                │      │                                │    │
                │                │      ▼                                ▼    │
                │                │  PhoneNumberNormalizer ◄──────────────┘    │
                │                │      │                                     │
                │                │      ▼                                     │
                │                │  WhatsAppNumberCache (DB-backed)           │
                │                │      │                                     │
                │                │      ▼                                     │
                │                │  EvolutionApiClient.sendText() ───────────► Evolution API
                │                │      │                                     │
                │                │      ▼                                     │
                │                └─► EvoSentryReporter (on error)             │
                │                                                              │
                └──────────────────────────────────────────────────────────────┘
```

## Module responsibilities

| File | Responsibility |
| ---- | -------------- |
| `evolution.php` | Wires signals, orchestrates the flow, builds template variables, decides who to notify. |
| `config.php` | UI fields + validation. No business logic. |
| `lib/PhoneNumberNormalizer.php` | Pure: any input string → digits-only E.164 (no "+") or `null`. |
| `lib/TemplateRenderer.php` | Pure: `{{var}}`/`{{var\|fallback}}` substitution; HTML → WhatsApp-compatible plain text; UTF-8 safe truncation. |
| `lib/EvolutionApiClient.php` | All HTTP to Evolution API. Returns uniform `{ok, status, body, error}`. No state across calls. |
| `lib/WhatsAppNumberCache.php` | DB persistence for `isOnWhatsApp` results with separate hit/miss TTL. Auto-creates its table on first use. |
| `lib/SentryReporter.php` | Optional. Parses DSN, POSTs events to `/store/`. No-op when DSN is empty. |

## Concurrency & ordering

The osTicket request thread is single-threaded PHP-per-request. Signals fire synchronously inside the request. A few consequences:

- A slow Evolution API will slow the customer's POST/reply request. **Mitigation:** the client has a 15-second default timeout (configurable down to 3s), and the `whatsappNumbers` lookup is cached, so the common case avoids that round trip.
- `model.updated` can fire multiple times for the same Ticket within one request (osTicket sometimes saves twice). The plugin de-duplicates per request via `statusHandled[ticketId]`.

If sending becomes a bottleneck, the right next step is to push sends onto an async queue (osTicket's `Cron` or an external worker reading the DB). That's out of scope for v0.1.

## Failure modes

| Scenario | Behavior |
| -------- | -------- |
| Evolution API unreachable | `EvolutionApiClient::call()` returns `{ok:false}`; dispatcher logs at `error`; Sentry message dispatched if DSN set. The osTicket request itself does not fail. |
| `whatsappNumbers` returns unknown | `isOnWhatsApp()` returns `null`; dispatcher **fails open** and attempts the send anyway (the user's request to be notified is more important than saving one API call). |
| Invalid phone on user record | `PhoneNumberNormalizer::normalize()` returns `null`; client send is skipped, debug log records the raw value. Admin sends are unaffected. |
| Sentry DSN invalid | `EvoSentryReporter::parseDsn()` returns `null`; reporter silently no-ops. |
| Cache table creation failure (e.g. DB permissions) | The first `db_query` returns false; subsequent gets/puts return null/skip. Plugin continues without cache (slower but functional). |

## Why not the official Sentry SDK?

The official `sentry/sentry` SDK is great but pulls Composer into the install path. To keep this plugin truly drop-in (a single `rsync` and done), `SentryReporter` is a ~150-line standalone implementation of the bare essentials: parse DSN, build envelope-shaped event, POST to `/store/`. For richer Sentry features (performance, breadcrumbs, release tracking with commits, scopes) install the official SDK separately and modify `SentryReporter` to delegate.
