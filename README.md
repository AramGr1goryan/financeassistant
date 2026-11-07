# Personal Finance Assistant

A private, owner-only finance management system. The backend is the source of truth for transaction records and deterministic calculations; the dashboard and Telegram bot are clients of the same application services.

## Project status

This project is at the foundation stage. See [the implementation checklist](docs/implementation-plan.md) for what is complete and what remains. No bank adapter or financial calculation is considered implemented until it has working code and tests.

## Local setup

1. Copy the root `.env.example` to `.env`; use a local-only database password.
2. Start PostgreSQL and Redis with `docker compose up -d`.
3. In `backend`, copy `.env.example` to `.env`, then run `composer install`, `php artisan key:generate`, and `php artisan migrate`.
4. In `frontend`, run `npm install` and `npm run dev`.

The backend status check is available at `/api/v1/status`. The UI intentionally shows an empty setup state until real, authorized financial data is implemented.

Never put production credentials or bank data in this file or in source control.

## Product and data rules

- Primary currency: AMD, stored as whole drams; timestamps are stored in UTC and grouped/displayed in `Asia/Yerevan`.
- Cash tracking is disabled. Credit limits and predicted income are not available spending money.
- Financial values use integer minor units; authoritative calculations are deterministic.
- Banking integrations are read-only and may only implement officially documented capabilities.

See [architecture](docs/architecture.md), [database design](docs/database.md), and [security baseline](docs/security.md).
