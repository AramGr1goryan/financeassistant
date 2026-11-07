# Development conventions

- Keep the application a modular monolith: Laravel owns the domain and versioned REST API; Next.js is a presentation client.
- Keep financial rules in typed, deterministic domain services. HTTP controllers, Telegram handlers, and AI integrations must not calculate authoritative balances or budgets.
- Store money as integer minor units with an explicit ISO currency code and currency exponent. Never use floating-point arithmetic or overwrite original transaction amounts.
- Keep posted, pending, reversed, declined, predicted, and user-assumed data distinguishable. Predicted income is never part of current balances.
- Treat bank adapters as read-only. Implement only documented provider capabilities; otherwise expose an explicit unavailable or manual-import-only state.
- Do not collect banking passwords, PINs, CVVs, or full card credentials. Encrypt access tokens and keep all secrets in environment configuration.
- Use UUID primary keys, UTC timestamps, database constraints, idempotent imports, and transactional writes for ledger changes.
- Add focused unit and feature tests for each module before marking it complete. Never use live financial credentials or production bank data in tests.
- Keep owner-only authorization enforced server-side for every API, webhook, and bot entry point.
- Document incomplete external integrations and operational prerequisites; do not claim a feature is complete without working code and tests.

## Project layout

- `backend/`: Laravel API and domain modules.
- `frontend/`: Next.js dashboard.
- `docs/`: architecture, database, security, and implementation records.
- `docker-compose.yml`: local PostgreSQL and Redis services.

## Change workflow

1. Read the relevant domain documentation and existing code before editing.
2. Make one coherent module change at a time.
3. Add or update tests alongside the change and run the narrowest applicable checks.
4. Update the implementation checklist and relevant documentation when behavior or status changes.
