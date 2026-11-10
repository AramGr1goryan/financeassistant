# Personal Finance Assistant

A private, owner-only finance management system. The backend is the source of truth for transaction records and deterministic calculations; the dashboard and Telegram bot are clients of the same application services.

## Project status

The manually operated AMD workflow is implemented and tested locally; production deployment is not complete. See [the implementation checklist](docs/implementation-plan.md) for current status. No bank adapter or financial calculation is considered implemented until it has working code and tests.

## Local setup

1. Copy the root `.env.example` to `.env`; use a local-only database password.
2. Start PostgreSQL and Redis with `docker compose up -d`.
3. In `backend`, copy `.env.example` to `.env`, then run `composer install`, `php artisan key:generate`, and `php artisan migrate`.
4. In `frontend`, run `npm install` and `npm run dev`.

The backend status check is available at `/api/v1/status`. The UI shows an empty setup state until the authenticated owner adds real financial data; it does not display demo figures.

Never put production credentials or bank data in this file or in source control.

## Production setup

The Next.js frontend and Laravel API are configured as Vercel Services in one project. Vercel routes `/api/*` to a stateless FrankenPHP container and all other paths to Next.js. Attach Prisma Postgres from the project’s Storage tab in the `fra1` region and enable it for Production. The integration supplies `DATABASE_URL` for migrations and `DATABASE_URL_POOLED` for application traffic; Laravel uses the pooled URL by default and accepts `DB_URL` as an explicit override for migration commands. The database is external and is not stored in the container.

Set these values in the Vercel project’s Production environment. Generate `APP_KEY` once with `php artisan key:generate --show` from `backend`, then enter it directly in Vercel; do not paste it into chat or commit it. Keep the same key across deployments. Never expose secrets as `NEXT_PUBLIC_*` variables or use them in client-side code:

- `APP_KEY` generated for the production environment.
- `APP_URL` set to the public app origin after its Vercel domain is assigned.
- `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, and the private-chat owner’s numeric `TELEGRAM_OWNER_ID` if Telegram is enabled.

The container defaults to database-backed sessions and cache, synchronous queues, PostgreSQL, secure cookies, and stderr logging. Before the first deployment, run `php artisan migrate --force` with the direct `DATABASE_URL` supplied as `DB_URL`; then run `php artisan db:seed --force` with `APP_ENV=production` and create the initial owner interactively with `php artisan finance:create-user`. When `TELEGRAM_OWNER_ID` is configured, that command links the owner to the matching private Telegram ID. Configure the Telegram bot webhook to `https://<app-domain>/api/v1/telegram/webhook` and use the same webhook secret in Telegram and the Laravel environment. Do not send credentials or financial data through chat or commit them to source control.

The Vercel Services project has Prisma Postgres attached, and Production entries exist for `APP_KEY` and the Telegram settings. The webhook secret is currently reported by Vercel as readable; change its visibility to Sensitive before using the bot. The project still has no deployment or domain, and `APP_URL`, migrations, initial owner setup, final PostgreSQL validation, and end-to-end checks remain outstanding. See [the implementation checklist](docs/implementation-plan.md).

## Product and data rules

- Primary currency: AMD, stored as whole drams; timestamps are stored in UTC and grouped/displayed in `Asia/Yerevan`.
- Cash tracking is disabled. Credit limits and predicted income are not available spending money.
- Financial values use integer minor units; authoritative calculations are deterministic.
- Banking integrations are read-only and may only implement officially documented capabilities.

See [architecture](docs/architecture.md), [database design](docs/database.md), and [security baseline](docs/security.md).
