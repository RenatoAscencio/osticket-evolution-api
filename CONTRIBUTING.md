# Contributing

Thanks for considering a contribution!

## Quick guidelines

- **License:** All contributions are accepted under GPL-2.0-or-later (same as osTicket).
- **Code style:** Mirror the conventions in `osTicket/osTicket-plugins`. Concretely:
  - PHP 7.4+ compatible (Evolution API requires curl & json — both core).
  - Tabs or 4 spaces consistently — match surrounding file.
  - Short, focused classes in `plugin/lib/`.
  - No Composer dependencies in the runtime plugin (keep it drop-in deployable).
- **No secrets in commits.** Never commit DSNs, API keys, real phone numbers, or `.env` files. See `.gitignore`.
- **Tests:** If you add a class in `plugin/lib/` with non-trivial logic, add a matching test under `tests/`. Tests should not require a running osTicket (mock anything you need from the global scope).
- **Docs:** When you add/rename a user-visible config option, update **both** `README.md` and `README.es.md`. Add longer prose to `docs/`.
- **Commits:** Conventional commits are appreciated (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`).

## Local development

```bash
git clone https://github.com/RenatoAscencio/osticket-evolution-api.git
cd osticket-evolution-api/docker
docker compose up -d
# Edit files in plugin/ — the docker-compose mounts them live into osTicket.
```

## PR upstream to osTicket/osTicket-plugins

Once the plugin is stable and we have at least one real production deployment running cleanly, the goal is to PR a copy of `plugin/` into [`osTicket/osTicket-plugins`](https://github.com/osTicket/osTicket-plugins) as a new top-level folder (e.g. `evolution-api/`). Before doing that:

1. Run `php -l` on every file in `plugin/` — must be clean.
2. All unit tests in `tests/` must pass.
3. The Docker stack must boot, install the plugin, and successfully send to a known WhatsApp number.
4. Docs in `docs/` are up to date.

See `docs/upstream-pr.md` for the planned process.
