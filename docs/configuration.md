# Configuration reference

Every field shown in the **Manage → Plugins → Evolution API Notifications → Configure** screen.

## Evolution API — Credentials

| Field | Type | Required | Notes |
| ----- | ---- | -------- | ----- |
| **Base URL** | string | yes | Full URL with scheme. No trailing slash (the plugin strips it anyway). |
| **Instance name** | string | yes | The Evolution API instance that holds the WhatsApp session. |
| **API key** | string | yes | Sent as the `apikey` header on every request. |
| **Verify SSL certificate** | bool | — | Leave **on** in production. Turn **off** only when using self-signed certs in local dev. |
| **HTTP timeout (seconds)** | int | — | Per-request total timeout. Default 15s. Minimum effective value is 3s. |

## Phone numbers

| Field | Type | Required | Notes |
| ----- | ---- | -------- | ----- |
| **Default country code** | digits | yes | Used when a user's phone has no country code. Example: `52` for Mexico, `1` for US/Canada, `54` for Argentina. |
| **Check WhatsApp existence before sending to clients** | bool | — | On by default. Calls `/chat/whatsappNumbers/{instance}` first and skips when the number is not on WhatsApp. |
| **Cache TTL for confirmed WhatsApp numbers (s)** | int | — | Default `604800` (7d). |
| **Cache TTL for not-on-WhatsApp numbers (s)** | int | — | Default `86400` (1d). Shorter than the hit TTL so users who later sign up for WhatsApp eventually get notifications. |

## Recipients

| Field | Type | Required | Notes |
| ----- | ---- | -------- | ----- |
| **Notify clients (end users)** | bool | — | When off, no message is ever sent to the ticket owner. |
| **Notify admins** | bool | — | When off, the admin list below is ignored. |
| **Admin WhatsApp numbers** | text | — | One number per line, with country code, no `+`. Example: `5215555555555`. |

## Events to notify on

Independent toggles. Default settings shown:

| Event | Default | osTicket signal |
| ----- | ------- | --------------- |
| Ticket created | **on** | `ticket.created` |
| Client/user reply on ticket | **on** | `threadentry.created` (filtered by poster type) |
| Staff reply on ticket | **on** | `threadentry.created` (filtered by poster type) |
| Ticket status changed | **on** | `model.updated` on `Ticket` with `status_id` dirty |
| Ticket assignment changed | **off** | `model.updated` on `Ticket` with `staff_id`/`team_id` dirty |

## Message templates

Each template supports the following placeholders:

| Placeholder | Available in | Description |
| ----------- | ------------ | ----------- |
| `{{ticket_number}}` | all | The user-visible ticket number, e.g. `1234`. |
| `{{subject}}` | all | Ticket subject line. |
| `{{name}}` | all | Name of the ticket owner (or current poster, for reply events). |
| `{{email}}` | all | Ticket owner email. |
| `{{department}}` | all | Department name. |
| `{{priority}}` | all | Priority description. |
| `{{status}}` | all | Current status name (`Open`, `Closed`, etc.). |
| `{{assignee}}` | all | Current assignee name. Empty when unassigned. |
| `{{poster_type}}` | reply events | `Staff`, `User`, `Collaborator`, `System`. |
| `{{message}}` | created + reply events | Message body, converted from HTML to WhatsApp-compatible plain text, truncated to 2500 chars. |
| `{{ticket_link}}` | all | Full URL to the staff-side ticket view (requires *osTicket base URL* to be set). |

Default values are pre-filled. WhatsApp supports basic formatting:

- `*bold*`
- `_italic_`
- `~strikethrough~`
- `` `monospace` ``
- ``` ```code blocks``` ```

There is **no HTML** in WhatsApp — the renderer converts `<strong>`/`<b>` to `*…*`, `<em>`/`<i>` to `_…_`, and strips everything else.

### Fallbacks in templates

Use `{{var|fallback}}` to supply a value when the variable is empty. Example:

```
Assignee: {{assignee|unassigned}}
```

## Misc

| Field | Type | Notes |
| ----- | ---- | ----- |
| **osTicket base URL** | string | Required if you use `{{ticket_link}}` in a template. |
| **Delay between messages (ms)** | int | Pacing between consecutive admin sends to dodge Evolution API rate limits. `0` disables. |

## Sentry (optional)

| Field | Notes |
| ----- | ----- |
| **Sentry DSN** | `https://<key>@<host>/<project_id>`. Leave empty to disable. |
| **Sentry environment** | Free-form. Default `production`. |
| **Capture global PHP errors** | When on, registers a global error/exception handler that reports to Sentry. Useful for catching osTicket-wide issues, not just plugin ones. **Warning:** enabling this in shared hosting where other apps run can be noisy — start with off and turn on if needed. |

## Debug

| Field | Notes |
| ----- | ----- |
| **Verbose logging** | Adds `debug` and `info` lines to PHP's error log. `error` and `warning` lines are always logged regardless of this toggle. |
