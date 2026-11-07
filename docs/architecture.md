# Architecture

## Product constraints

The target system supports multiple users, each owning an isolated private financial workspace. The current application is not yet ready for multi-user access: server-side authentication and complete tenant isolation must be implemented and tested before exposing financial interfaces. AMD is the primary display currency and is represented in whole drams (currency exponent 0). The application and persisted timestamp timezone is UTC; financial dates are displayed and grouped in `Asia/Yerevan`. Cash tracking is disabled. Income can be variable and irregular, so any future forecasts must be conservative and explicitly uncertain.

The application must keep actual posted data, pending transactions, assumptions, and predicted income distinct. Predicted income is never included in an actual balance or available-spending calculation. All authoritative financial calculations are deterministic application code; AI may assist with explanations and suggestions but cannot calculate or mutate ledger values.

## System shape

Use a modular monolith:

- **Backend:** PHP 8.3+ and Laravel, PostgreSQL, Redis, Laravel queues and scheduler, and a `/api/v1` REST API.
- **Frontend:** Next.js, TypeScript, and Tailwind CSS. It renders backend data and does not duplicate financial rules.
- **Telegram:** Telegram Bot API webhook integration, using the same application services as the REST API.
- **Operations:** Docker Compose for local PostgreSQL and Redis; production deployment adds an application runtime, Nginx/reverse proxy, TLS, backups, and monitoring.

No separate microservices are planned. External providers are reached through narrow adapters.

## Domain boundaries

The codebase is organized around Accounts, Banking Integrations, Transactions, Categorization, Income, Expenses, Transfers, Budget, Forecasting, Recurring Payments, Savings Goals, Analytics, Notifications, Telegram, AI Assistant, and Audit Logging.

Cross-cutting rules:

- Controllers and Telegram handlers validate/authorize requests, invoke application actions, and shape responses. They do not contain business calculations.
- Domain services own deterministic financial rules and should be independently testable.
- DTOs define validated boundaries for imports, provider responses, and API responses.
- Jobs handle synchronization, imports, and scheduled evaluation; events/listeners connect committed domain changes to downstream work.
- Use repository abstractions only where they clarify an external boundary or materially improve testability.
- Persist financial changes atomically. Use database constraints and idempotency keys as the final guard against duplicate imports and webhook deliveries.

## Transaction processing

`Import -> Normalize -> Validate -> Deduplicate -> Detect explicitly identified internal transfers -> Persist atomically -> Recalculate balances`

Provider adapters convert their payloads to the canonical import shape before calling the transaction processor. Provider transaction identifiers and idempotency keys are unique within an account. Re-imports update the existing transaction's allowed lifecycle status without creating another ledger row. Internal transfers are paired atomically only when the importer supplies both the owned counterpart account and a stable reference shared by both account records; uncertain matches are not inferred. Pending, reversed, and declined transactions are never treated as posted transactions. Transfers between owned accounts are neither income nor expenses.

Re-imports cannot silently change a stored amount, currency, type, transaction date, or other historical source fields. Pending records may advance to posted, reversed, or declined; posted records may be reversed; reversed and declined records are terminal. Corrections outside that lifecycle require an explicit adjustment or reversal workflow.

## Banking boundary

Implement a provider-independent `BankIntegrationInterface` for connection status, account discovery, balance retrieval, transaction synchronization, refresh, and revocation where a provider officially supports those operations. Before enabling Ameriabank or IDBank access, verify official documentation and authorization requirements. No endpoint, authentication flow, or capability may be guessed. Unsupported connections report `UNAVAILABLE` or `MANUAL_IMPORT_ONLY`; CSV/XLSX/XML imports use the same normalized validation and deduplication pipeline.

Adapters are read-only and cannot initiate payments or transfers. Never use browser automation to bypass bank authentication or collect banking passwords, PINs, CVVs, or full card credentials.

## Financial calculation boundaries

- Use integer minor units, explicit currency codes, and recorded currency exponents; no floating-point arithmetic. Transaction amounts are positive magnitudes except signed adjustments; transaction type and transfer direction determine the balance effect.
- Preserve original transaction amount/currency and the exchange-rate evidence used for AMD conversion. Historical amounts are immutable when rates change.
- Recalculate balances from the transaction ledger and opening balances; keep pending effects separate, exclude reversed/declined data, credit limits, and cash, and report credit liabilities separately.
- Subtract mandatory obligations, protected reserves, and allocated savings from eligible own funds. Clamp the recommended daily limit to zero when remaining funds are negative.
- Divide available funds by the configured conservative planning horizon; expose the horizon and assumptions. When no horizon is supplied, use 30 days; an explicitly supplied horizon must be positive.
- The budget calculator deducts each pending asset-account outflow without offsetting it by pending inflows, then deducts explicitly supplied mandatory payments, reserves, and allocated savings. Avoid entering the same pending outflow again as a mandatory payment. The daily limit rounds down to whole minor units and never falls below zero. Credit liabilities are reported separately and reduce the budget only through explicitly supplied mandatory payments.
- Budget settings are stored as successive owner- and currency-scoped revisions. The latest revision is used with the current ledger; no balance is cached or recorded as a historical balance. Assumptions are totals for the supplied planning horizon, not automatically recurring monthly charges. The service requires explicit saved assumptions and does not invent a zero-valued budget when none exists. The session-authenticated, CSRF-protected `POST /api/v1/budget/settings` accepts explicitly entered whole AMD amounts as decimal strings and a positive planning horizon, then creates a revision for the authenticated owner. The dashboard can edit those assumptions; it never calculates the budget.
- Manual income expectations are owner-scoped assumptions with a positive amount and an expected Yerevan calendar date, stored separately from ledger transactions. Past and future dates are accepted without interpreting them as actual income. Users can mark an expectation completed independently of recording an actual transaction; completion is idempotent and does not create, link, or change a transaction. These expectations never alter current balance or budget. Automatic forecasts and confidence ratings are not implemented. Yerevan financial-day bounds are computed locally and converted to UTC before ledger queries.

## Access and interfaces

Each workspace is accessible only to its owner; another user must not be able to access it. Enforce authentication and owner-scoped authorization on the server, not merely in UI navigation. The requested Laravel sign-in has closed public registration. Telegram-based enrollment is restricted to pre-authorized Telegram IDs and requires a secure identity-linking design; neither enrollment nor account setup is implemented. Telegram requests require a configured webhook secret and a verified link to the owning user. Webhook processing is rate-limited and replay-safe. The dashboard consumes only the versioned API and displays real backend data; development fixtures, if added, must be visibly labeled.

The Laravel session-backed financial endpoints are mounted under `/api/v1` with the web middleware, including CSRF protection. `GET /session/csrf` initializes the session and CSRF cookie; `POST /session` authenticates an existing user and rotates the session; `DELETE /session` logs out. Sign-in is limited to 10 attempts per minute per IP, and public registration is not provided. `GET /balance` and `GET /budget` require a session and explicit `currency` and `currency_exponent` query parameters. They take ownership exclusively from the session, not a client-supplied owner ID. A missing budget setting returns 404. Amounts in responses are decimal strings of integer minor units with currency and exponent, preserving precision in JavaScript. Responses containing financial results use `Cache-Control: no-store`.

The Next.js overview and budget pages use a same-origin rewrite for `/api/v1` to the server-side `LARAVEL_ORIGIN` (defaults to `http://127.0.0.1:8000` for local development). The origin is a trusted HTTP(S) backend origin, not user input; configure it when building the frontend. The browser acquires the CSRF cookie and sends the token for session changes. The pages display only balance and budget data currently exposed by the API; account details, transaction lists and income expectations are not connected. An unavailable backend or failed budget request is not shown as a zero balance or an unset budget.

An operator with access to an interactive Laravel terminal can provision an existing user account using `php artisan finance:create-user`. The command prompts for name and email and requires hidden password input with confirmation; it refuses noninteractive execution and never resets an existing account. It is not a public registration interface. The operator must target the intended environment and database before running it. The deployment platform must support a secure interactive backend terminal for this workflow.

Use secure session cookies and HTTPS in production. A shared, atomic rate-limit store and correctly configured trusted proxies are production prerequisites. Account recovery, budget assumption editing, a complete dashboard integration, and production security validation remain outstanding; these endpoints alone do not complete workspace authentication or the user interface.

English is the initial locale. Keep user-facing text localizable for Armenian and Russian. Store UTC and render dates using `Asia/Yerevan`.

## Delivery order

See [implementation-plan.md](./implementation-plan.md). Bank access, AI, Telegram, and production deployment remain blocked until their prerequisites are verified and tested.
