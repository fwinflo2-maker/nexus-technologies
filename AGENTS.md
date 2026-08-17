# AGENTS.md — Nexus Technologies

Session learnings not recoverable from code or docs. Terse, project-specific.

## Runtime & servers
- Dev runs WITHOUT `.env`: `config/env.php` defaults (XAMPP root/empty password, APP_ENV=development) are enough for API and PHPUnit. Only `.env.example` is committed. Outside development, missing `DB_USER`/`DB_PASS`/`JWT_SECRET` is fail-closed (API refuses to start).
- Ports :8080 (php -S) and :5173 (vite) are the user's permanent dev servers, started from the PRIMARY checkout — they serve its dirty working tree, so E2E/browser checks against them exercise uncommitted WIP, not clean HEAD. Use throwaway ports (e.g. 8098) for disposable servers.
- Dev DB (XAMPP MySQL `nexus`): `fx_rates_cache` is EMPTY, so every FX surface honestly reports FX_UNAVAILABLE — that is the fail-closed design, don't seed fake rates without being asked. Accounts from `scripts/seed_dev_data.php` (`password123`) do NOT exist in the live dev DB (current users `personnel@example.com`/`business@example.com` have unknown passwords); for browser checks register a fresh account via `POST /api/register` (personal accounts require `birth_date`) which returns a JWT directly.

## Backend / ledger semantics
- Hold accounting is deliberate: `createHold` and `releaseHold` write NO ledger entry (reversible reservation available↔hold); `captureHold` writes the single definitive debit (`LedgerService::recordHoldCapture`). A send shows exactly 2 EUR ledger entries: welcome credit + capture debit. Do not "fix" the missing hold entry.
- Invariant: last `ledger_entries.balance_after` of a wallet == `wallets.available_balance` at steady state (balances are projections; ledger is the source of truth).
- Production is fail-closed: no `fx_rates_cache` rate for a pair → 503 on quotes; no sanctions source configured → transfers declined in prod (sandbox passes with REVIEW_REQUIRED). `Currency::RATE_TO_EUR/XAF` hardcoded tables still feed aggregates/policy limits — flagged as next audit in docs/NEXUS-AUDIT-BOUCLE-16.md.

## GitHub & remote (no gh CLI)
- `gh` is NOT installed. GitHub API works via the OAuth token cached in Git Credential Manager: `git credential fill` (input `protocol=https\nhost=github.com`) returns it non-interactively; use as `Authorization: Bearer`. Never echo it.
- GitHub rejects PR creation with 422 "No commits between <base> and <head>" when a branch has no unique commits — an empty branch can be pushed but never PR'd. Remote default branch is `main`; repo `fwinflo2-maker/nexus-technologies`.

## Repo state & hygiene
- The PRIMARY checkout carries uncommitted WIP (~39 modified files, `vite.config.ts` staged) plus untracked scripts that must never be committed or deployed: `nexus-api/reset_superadmin.php` (hardcoded `admin123` + root PDO — credential-reset backdoor), `test_hash.php`, `encrypt_credentials.php` (uses dev APP_KEY), `AdminLoginPage.new.tsx`. Verify no account uses `admin123`. Preserve this WIP untouched when working across worktrees.

## Tooling quirks (Windows)
- File tools: `write_file` with `/tmp/x` writes `C:\tmp\x`, but Git Bash `/tmp` is `%LOCALAPPDATA%\Temp` — the edit tools cannot reach `C:\tmp`; use `write_file`'s `/tmp/...` mapping or `sed` via terminal for temp files.
- `php -S` started in a backgrounded subshell keeps the terminal command waiting past its timeout even though the server responds — redirect output to a file and kill by PID inside one command, or reuse the user's :8080 server.

## Freebuff preview & tooling
- To preview THIS worktree's backend (not the primary checkout's dirty tree): `nexus-frontend/vite.config.ts` hardcodes the `/api` proxy to :8080, so run the worktree API on a throwaway port (e.g. `php -S 127.0.0.1:8098 -t public`) plus a gitignored `nexus-frontend/vite.preview.config.ts` (import `./vite.config.ts` with explicit `.ts` extension, port 5174, proxy → 8098). Full procedure in `.freebuff/run.md`.
- `register_preview` with `replace: true` STOPS the thread's previously registered dev server — replacing the preview killed the user's :5173 vite. If 5173 goes silent, restart it detached from the PRIMARY checkout's `nexus-frontend` (Start-Process `npm.cmd run dev`, logs in `.freebuff/preview-<thread>.log(.err)`, `-WorkingDirectory` required).
- The Start-Process detach recipe (`-RedirectStandardOutput` + `-PassThru`) makes the terminal command TIME OUT but the server survives — verify via `netstat -ano | grep :<port>` / curl, and register the preview with the LISTENING node/php pid, not the cmd/npm wrapper pid. Launching the server via `powershell … &` inside a SYNC command gets it killed when the command ends; use the bare Start-Process command and let it time out.
- Preview webview quirks: IntersectionObserver may never fire on scroll, leaving scroll-reveal content at `opacity:0` — always add a timed force-reveal fallback (+ `<noscript>` guard). `preview_screenshot` can fail with "produced no frames" until the webview composites — verify with `preview_snapshot` / `preview_evaluate` instead of retrying blindly.
