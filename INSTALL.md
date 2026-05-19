# Installation guide

This document covers three install scenarios:

1. **Local Docker** — fastest path to play with the plugin.
2. **Existing osTicket install** — drop the plugin into a real osTicket.
3. **Production deploy via SSH + rsync** — the supported script-based path.

---

## 1. Local Docker test stack

Requires Docker and Docker Compose v2.

```bash
git clone https://github.com/RenatoAscencio/osticket-evolution-api.git
cd osticket-evolution-api/docker
docker compose up -d
```

Then browse to `http://localhost:8081` and complete the osTicket web installer:

| Field | Value |
| ----- | ----- |
| Database host | `db` |
| Database name | `osticket` |
| Database user | `osticket` |
| Database password | `osticket` |
| Table prefix | `ost_` |

After the installer finishes, delete the `setup/` folder as instructed (the stack mounts the osTicket source from a volume — see `docker/README.md`).

Then in the admin panel **Manage → Plugins → Add New Plugin**, the *Evolution API Notifications (WhatsApp)* plugin should appear (the `plugin/` directory is bind-mounted into `include/plugins/evolution-api`). Install it, click into it, and fill in your Evolution API credentials.

---

## 2. Drop into an existing osTicket install

```bash
# From the repo root:
rsync -av --exclude '.git' plugin/ /path/to/osticket/include/plugins/evolution-api/
chown -R <web-user>:<web-group> /path/to/osticket/include/plugins/evolution-api
```

In osTicket: **Manage → Plugins → Add New Plugin → Install** → click into the plugin → fill credentials → **Enable**.

---

## 3. Production deploy via SSH + rsync

The repo includes a generic deploy script driven by environment variables:

```bash
cp scripts/.env.example scripts/.env
$EDITOR scripts/.env                   # Fill in REMOTE, REMOTE_PLUGIN_DIR, etc.
source scripts/.env && ./scripts/deploy.sh --dry-run
source scripts/.env && ./scripts/deploy.sh
```

It will, in order:

1. Take a DB backup on the remote (if `REMOTE_DB` is set).
2. `rsync` `plugin/` into `REMOTE_PLUGIN_DIR/`.
3. `chown` the deployed files to `REMOTE_USER_GROUP` (if set).
4. Print the next manual step (enabling the plugin in osTicket admin).

`scripts/.env` is gitignored — server-specific values stay on your machine only.

See [docs/deploy-production.md](./docs/deploy-production.md) for the detailed step-by-step including how to roll back.

---

## Verifying the install

After installing & enabling the plugin in the admin UI:

1. **Connection check.** From the server, manually hit the Evolution API instance:
   ```bash
   curl -sH "apikey: <YOUR_KEY>" \
        "https://<YOUR_EVO_HOST>/instance/connectionState/<INSTANCE>"
   ```
   It should return JSON with `"state": "open"`.

2. **Plugin smoke test.** Create a ticket using a phone number you know has WhatsApp. You (the admin) should receive a notification within seconds.

3. **Logs.** Tail PHP error log to see plugin output (with `Verbose logging` enabled):
   ```bash
   ssh "$REMOTE" 'tail -F <path-to-php-error-log> | grep EvolutionApiNotifications'
   ```
   Typical paths: LiteSpeed `/usr/local/lsws/logs/error.log`, Apache `/var/log/apache2/error.log`, RHEL/CentOS `/var/log/httpd/error_log`, PHP-FPM `/var/log/php*-fpm.log`.

4. **Sentry.** If you set a DSN, the next plugin error should appear in your Sentry project's Issues view. To force one, set a bad API key and create a ticket — you'll see `Evolution API sendText failed: HTTP 401`.
