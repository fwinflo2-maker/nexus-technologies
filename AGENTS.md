# AGENTS.md — Nexus Technologies API

PHP 8.1+ fintech payment platform with multi-provider routing (Cashramp, Stripe, Western Union, MoneyGram, etc.).

## Commands

```bash
# Run PHPUnit test suite
php api-app/vendor/bin/phpunit

# Run health probe (diagnostic script)
php api-app/scripts/probe.php

# Set up test database (requires sql/ directory with schema)
php api-app/scripts/setup_test_db.php

# Hostinger deployment sync
bash scripts/hostinger-sync-from-github.sh
```

## Project Structure

```
api-app/
  config/     # env.php, app.php, constants.php, database.php
  src/        # Nexus\ namespace (PSR-4), providers, services, execution
  tests/      # PHPUnit tests, bootstrap.php
  scripts/    # probe.php, setup_test_db.php
  migrations/ # SQL migrations
  vendor/     # Composer dependencies
```

## Environment

- `APP_ENV=production`: fail-closed, requires secrets from .env
- `APP_ENV=development`: uses XAMPP defaults (root, no password, nexus DB)
- DB credentials read from `.env` via `config/env.php`
- Provider credentials via `PROVIDER_{SLUG}_{ENV}_{FIELD}` env vars

## MCP / Hostinger

Config files:
- `.agents/mcp_config.json` — project-local MCP server config
- `~/.gemini/config/mcp_config.json` — global Gemini IDE config

Both reference `hostinger-api-mcp@latest` with bin names:
- `hostinger-hosting-mcp` (58 tools)
- `hostinger-domains-mcp` (40 tools)
- `hostinger-dns-mcp` (8 tools)

API token: `HOSTINGER_API_TOKEN` env var

## Notes

- Windows/Powershell: empty env vars (`set X=`) return `false` from `getenv()` — use `!== false` checks (see `tests/bootstrap.php:23`)
- Local MySQL: XAMPP root with no password, `u199940923_nexus` database
- `probe.php` requires `config/constants.php`, `config/database.php`, `vendor/autoload.php`
