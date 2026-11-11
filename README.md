# Personal Finance Assistant

A private, owner-only finance management system. The backend is the source of truth for transaction records and deterministic calculations; the dashboard and Telegram bot are clients of the same application services.

## Project status

The manually operated AMD workflow is implemented and tested locally. Production is live at https://financeassistant-kargt-eam.vercel.app. The frontend and Laravel health/status endpoints pass smoke checks. Telegram bot support includes complete chat commands, interactive Bot API 10.4 widgets in private chat, and an embedded Telegram Mini App with HMAC-SHA256 signature verification. Safe owner linking and password rotation tools are built into the web UI and CLI. Bank integrations and automatic income forecasting are intentionally deferred.

## Telegram Bot & Mini App

The bot provides two complementary interaction modes:
1. **Interactive HTML Widgets (Telegram Bot API 10.4):**
   - Command: `/widget`
   - Compact native widget inside chat bubbles for quick Balance, Budget, and Accounts checks.
   - Fully sandboxed (QuickJS, no external network access, theme-aware, 1024-byte query responses).
2. **Telegram Mini App:**
   - Command: `/app`
   - Opens the responsive Web App inside Telegram with cryptographic `initData` verification (HMAC-SHA256, 24h replay window) for automatic owner sign-in.
   - Complete manual finance operations: Add Transactions (Expense, Income, Transfer), Manage Accounts, Budget Settings, Income Expectations, and Security Settings.
3. **Chat Commands:**
   - `/balance` — Current balances (posted vs pending vs credit liabilities).
   - `/budget` — Authoritative budget limits and available funds.
   - `/accounts` — List active accounts and balances.
   - `/create_account <name> <asset|credit> [opening_balance]` — Add account.
   - `/transactions [limit]` — View recent transactions.
   - `/expense <account> <amount> [description]` — Record posted expense.
   - `/income <account> <amount> [description]` — Record posted income.
   - `/transfer <from> <to> <amount> [description]` — Internal transfer.
   - `/post_tx <id>` — Post a pending transaction.
   - `/reverse_tx <id>` — Reverse a posted transaction.
   - `/set_budget <mandatory> <reserve> <savings> [days]` — Budget planning.
   - `/expected_income`, `/expect_income`, `/complete_expected_income` — Income expectations.
   - `/notifications` — Configure alert preferences.
   - `/help` — Command directory.

## Local setup

1. Copy the root `.env.example` to `.env`; use a local-only database password.
2. Start PostgreSQL and Redis with `docker compose up -d`.
3. In `backend`, copy `.env.example` to `.env`, then run `composer install`, `php artisan key:generate`, and `php artisan migrate`.
4. In `frontend`, run `npm install` and `npm run dev`.

The backend status check is available at `/api/v1/status`. The UI shows an empty setup state until the authenticated owner adds real financial data; it does not display demo figures.

Never put production credentials or bank data in this file or in source control.

## Production setup

The Next.js frontend and Laravel API are configured as Vercel Services in one project. Vercel routes `/api/*` to a stateless FrankenPHP container and all other paths to Next.js. The Production deployment is available at https://financeassistant-kargt-eam.vercel.app. Prisma Postgres is attached in the `fra1` region. The integration supplies `DATABASE_URL` for migrations and `DATABASE_URL_POOLED` for application traffic; Laravel uses the pooled URL by default and accepts `DB_URL` as an explicit override for migration commands. The database is external and is not stored in the container.

Set these values in the Vercel project’s Production environment. Generate `APP_KEY` once with `php artisan key:generate --show` from `backend`, then enter it directly in Vercel; do not paste it into chat or commit it. Keep the same key across deployments. Never expose secrets as `NEXT_PUBLIC_*` variables or use them in client-side code:

- `APP_KEY` generated for the production environment.
- `APP_URL` set to the public app origin after its Vercel domain is assigned.
- `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, and the private-chat owner’s numeric `TELEGRAM_OWNER_ID` if Telegram is enabled.

The container defaults to database-backed sessions and cache, synchronous queues, PostgreSQL, secure cookies, and stderr logging. Before the first deployment, run `php artisan migrate --force` with the direct `DATABASE_URL` supplied as `DB_URL`; then run `php artisan db:seed --force` with `APP_ENV=production` and create the initial owner interactively with `php artisan finance:create-user`. When `TELEGRAM_OWNER_ID` is configured, that command links the owner to the matching private Telegram ID. Configure the Telegram bot webhook to `https://<app-domain>/api/v1/telegram/webhook` and use the same webhook secret in Telegram and the Laravel environment. Do not send credentials or financial data through chat or commit them to source control.

Production values are configured for `APP_KEY`, `APP_URL`, and Telegram. `TELEGRAM_WEBHOOK_SECRET` is marked Sensitive. The user confirmed that Production migrations, seeding, and initial owner provisioning were completed. The deployment and public smoke checks are verified, but production PostgreSQL behavior after the latest changes, signed-in owner workflows, Telegram webhook registration/reply delivery, backup restoration, and monitoring are not yet verified. Register the Telegram webhook at `https://financeassistant-kargt-eam.vercel.app/api/v1/telegram/webhook` using the same secret configured in Vercel; do not put the bot token or webhook secret in source control or chat. See [the implementation checklist](docs/implementation-plan.md).

## Product and data rules

- Primary currency: AMD, stored as whole drams; timestamps are stored in UTC and grouped/displayed in `Asia/Yerevan`.
- Cash tracking is disabled. Credit limits and predicted income are not available spending money.
- Financial values use integer minor units; authoritative calculations are deterministic.
- Banking integrations are read-only and may only implement officially documented capabilities.

See [architecture](docs/architecture.md), [database design](docs/database.md), and [security baseline](docs/security.md).
