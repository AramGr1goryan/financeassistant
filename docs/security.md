# Security baseline

## Trust boundaries

The application serves one owner. Treat all API requests, Telegram updates, imported statements, bank responses, and AI output as untrusted until validated and authorized. Enforce owner access at the backend for every protected resource and use deny-by-default behavior.

## Credentials and financial data

- Never request, store, or log internet banking passwords, PINs, CVVs, or full card credentials.
- Keep application and Telegram secrets in environment-managed configuration; do not commit secret values.
- Encrypt supported bank access tokens at rest using framework-managed encryption and restrict decryption to the adapter boundary.
- Do not send raw credentials, tokens, or unnecessary transaction details to the frontend, Telegram, logs, or AI provider.
- Sanitize provider errors and sync logs. Audit sensitive configuration changes without recording secret values.
- Use TLS in production and encrypted, access-controlled backups; document restore testing.

## Web and API

- Require authentication and owner authorization for all financial API routes.
- Validate inputs with request validators and use parameterized ORM/query-builder operations.
- Apply CSRF protection to cookie-authenticated state-changing requests, secure session cookies, rate limits, and secure HTTP response headers.
- Prevent IDOR by scoping every record lookup to the owner rather than trusting a supplied UUID.
- Return minimal DTOs; do not expose credentials, internal error traces, or unnecessary bank identifiers.

## Telegram and integrations

- Validate Telegram webhook secret tokens using constant-time comparison.
- Bind the configured owner Telegram identity and reject all others before command execution.
- Rate-limit updates, deduplicate/replay-protect update IDs, validate payload shape, and make processing idempotent.
- Deliver replies through Telegram's Bot API after command processing; delivery failures return a retryable response without logging the bot token or raw payload.
- Bank integrations are read-only. Implement only officially documented capabilities with required user consent; never bypass bank authentication using browser automation.
- AI output is untrusted and may not execute SQL, authorize actions, or mutate posted financial records.

## Data integrity and verification

- Use integer minor units and deterministic domain services for calculations.
- Use database uniqueness, transactions, and idempotency controls for imports, webhooks, and notifications.
- Keep pending, posted, reversed, declined, assumption, and forecast data separate.
- Test unauthorized API and Telegram access, IDOR, duplicate events, malformed imports, replay attempts, and credential non-disclosure.
- Use test credentials and sanitized fixtures only. Do not use real bank credentials or production data in automated tests.

## Operational controls

Before production, define key rotation, secret revocation, backup retention and restore drills, least-privilege database access, dependency scanning, structured redacted logging, monitoring, incident response, and deployment rollback procedures. Security controls are not considered complete until implemented and exercised.

### Backup, restore, and monitoring procedure

1. **Encrypted PostgreSQL backups:** Run `pg_dump --format=custom --no-owner --no-privileges "$DB_DATABASE"` and encrypt the archive at rest before copying to off-host storage.
2. **Restore verification drill:** Restore backups into a disposable verification database (`pg_restore --clean --if-exists --no-owner --dbname="$VERIFY_DATABASE"`) and verify row counts and constraints on `accounts`, `transactions`, `budget_settings`, `income_expectations`, `telegram_owner_links`, `telegram_webhook_updates`, `notifications`, and `ai_category_suggestions`.
3. **Health and monitoring:** Monitor `/up` and `/api/v1/status`, queue failure counts, rate-limiter rejections (`429`), and webhook authorization rejections (`403`) without logging secret headers, tokens, or raw provider payloads.

### Current operational limitations

- Session-backed owner-scoped endpoints for balance, budget, accounts, transactions, and manual income expectations, plus `/api/v1/session` sign-in/sign-out, are implemented. PostgreSQL verification and end-to-end verification through the deployed same-origin frontend proxy are still required before production use.
- Production still requires a public Laravel host, PostgreSQL, secure environment configuration, and a Telegram bot token, webhook secret, and pre-authorized owner ID. The interactive `finance:create-user` command creates the initial owner and links the configured private Telegram ID when provided.
- Ameriabank and IDBank personal-account API capabilities and consent requirements have not been verified in Phase 4; no live bank adapter is enabled.
- Live AI provider credentials are not configured; `NullAiAssistantProvider` fails closed by default unless an approved provider implementation is bound.
- Docker daemon startup and live production backup/restore drills have not been executed in this workspace.
