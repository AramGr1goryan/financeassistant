# Implementation plan

Work in verifiable phases. A phase is complete only when its code, focused tests, documentation, and operational status agree.

## Phase 1 — Project foundation

- [x] Inspect existing workspace and establish repository structure.
- [x] Record architecture, database, security, and implementation conventions.
- [x] Establish Laravel API and Next.js TypeScript application shells.
- [x] Add local PostgreSQL and Redis Docker Compose services and environment examples.
- [x] Verify framework test/build commands.

## Phase 2 — Database and core domain

- [x] Add account, category, and transaction migrations/models with UUID financial record keys and database uniqueness constraints.
- [x] Add integer-only Money operations, including explicit currency/exponent checks and AMD whole-dram rules.
- [x] Add canonical transaction normalization, validation, deduplication, status updates, and atomic internal-transfer pairing.
- [x] Add deterministic posted/pending balance calculation, separate credit liabilities, and Yerevan financial-day UTC bounds.
- [x] Test refund linkage, owned-account transfers, and idempotent imports against SQLite.
- [x] Run financial migrations and the PostgreSQL integrity and concurrent-import integration tests against a dedicated local test database.
- [ ] Add bank connection, transfer-review, audit, and owner-identity schema after planning safe migration of the existing numeric-key user table.

## Phase 3 — Financial engine

- [ ] Expose balance and available-funds services through authenticated, owner-authorized interfaces.
  - [x] Calculate ledger-derived balances and conservative available funds internally using integer minor units, with focused tests for transaction lifecycle, pending income, and credit repayment.
  - [x] Add session-authenticated, owner-scoped read-only balance and budget API endpoints with focused SQLite tests.
  - [x] Connect the overview and budget pages through the same-origin proxy; exercise sign-in, sign-out, two-user isolation, and an unset budget in a browser against isolated SQLite fixtures.
  - [ ] Validate production prerequisites and PostgreSQL against a disposable test database.
- [x] Add an internal deterministic budget calculation using posted funds, pending outflows, and explicit assumptions.
- [x] Persist owner-scoped budget assumption revisions and calculate against the current ledger.
  - [ ] Add owner-scoped AMD assumption editing through the API and budget page; PostgreSQL verification remains pending.
    - [x] Verify saving, recalculation, reload persistence, and two-owner isolation in a browser using an isolated SQLite database and synthetic users.
- [x] Default the internal planning horizon to 30 days while allowing an explicit positive horizon.
- [ ] Implement budget and conservative planning-horizon calculations.
- [ ] Implement separately labelled manual income expectations; automated forecasts are deferred (PostgreSQL verification remains pending).
  - [x] Add an owner-scoped API and live dashboard form to create, list, and complete assumptions without changing the ledger or budget; SQLite API/UI tests pass, PostgreSQL verification remains pending.
  - [x] Validate the new migration and constraints on the designated disposable PostgreSQL test database (user-reported complete suite: 63 passed, 232 assertions).
- [ ] Implement Laravel authentication, owner-scoped authorization and isolated user workspaces before exposing financial APIs.
  - [x] Add an interactive terminal command to provision users without public registration or password arguments; test sign-in and failure cases on SQLite.
  - [ ] Validate account provisioning in the chosen hosting environment and implement account recovery.
- [ ] Design and test secure Telegram identity linking and enrollment before allowing Telegram to create user or financial accounts.
- [ ] Add boundary-focused unit tests; prove predictions do not alter actual balances.

## Phase 4 — Imports and bank integrations

- [ ] Research and document official Ameriabank and IDBank personal-account capabilities and consent.
- [ ] Implement only verified provider operations; otherwise expose unavailable/manual-import-only status.
- [ ] Add statement import with format validation, idempotency, and review handling.
- [ ] Test adapters against sanitized mocked official responses only.

## Phase 5 — Telegram

- [x] Add owner-only, rate-limited, replay-safe webhook processing.
- [x] Implement commands by calling existing financial application services.
- [x] Deliver Telegram webhook command replies through the Bot API and retry failed deliveries without repeating command mutations.
- [x] Link the configured Telegram owner ID while provisioning the initial workspace user.
- [x] Add notification preferences and delivery deduplication tests.

## Phase 6 — AI assistance

- [x] Add optional categorization and natural-language query assistance behind deterministic services.
- [x] Require confidence/review for suggestions; never silently modify confirmed records.
- [x] Test prompt/data boundaries and ensure no arbitrary database query execution.

## Phase 7 — Web dashboard

- [ ] Enable manual AMD account creation and ledger-derived account listing; PostgreSQL verification remains pending.
  - [x] Verify asset and credit account creation and reload persistence in a browser using isolated SQLite and synthetic data.
- [x] Add owner-scoped manual transaction entry, ledger listing, and processor-validated lifecycle actions; SQLite API tests pass, and the entry/posting/balance flow was browser-verified against isolated fixtures.
- [x] Connect owner-scoped manual income expectations and completion to the dashboard; keep assumptions separate from actual balances and budgets.
  - [x] Verify creating and completing an expectation through the browser without changing the displayed ledger balance.
- [x] Build responsive accessible dashboard and secondary pages.
- [x] Integrate versioned API data; never ship unlabeled production demo figures.
- [x] Add UI tests for key financial labels, empty states, and mobile layouts.

## Phase 8 — Production readiness

- [ ] Complete scoped security review, performance checks, backups/restore test, monitoring, and deployment documentation.
  - [x] Document backup/restore verification procedures, monitoring endpoints, and scoped security controls in `docs/security.md`.
- [ ] Deploy the Laravel API with PostgreSQL and the Vercel frontend; verify sign-in, owner-scoped manual finance workflows, and Telegram reply delivery end-to-end.
  - [x] Deploy commit `57ecc6c` to Vercel Production; the frontend, `/up`, and `/api/v1/status` returned HTTP 200, and no error records were returned by the retained runtime-log query.
  - [ ] Verify production PostgreSQL behavior and the signed-in owner-scoped finance workflow.
  - [ ] Register the Telegram webhook and verify command reply delivery end-to-end.
- [x] Verify authorization, webhook protections, sensitive-data handling, and dependency checks.
- [x] Report remaining provider, deployment, or operational limitations explicitly.

## Status

The application supports a manually operated AMD workflow: owner-scoped asset/credit accounts, transaction entry and lifecycle changes through the canonical processor, budget assumptions, and separately labelled expected income. The backend suite (123 passed, 4 skipped), frontend tests/lint/build, and local container health checks pass. Production commit `57ecc6c` is live at https://financeassistant-kargt-eam.vercel.app; the frontend, `/up`, and `/api/v1/status` passed HTTP smoke checks. The user confirmed Production migrations, seeding, and initial owner provisioning. The latest manual account, transaction, expectation, authentication, and Telegram delivery changes still need production PostgreSQL and signed-in owner-workflow verification. The Telegram webhook has not yet been registered or tested end-to-end. Runtime log access on the Hobby plan only retained the queried 30-minute window and returned no error records. Account recovery, monitoring, and verified backups remain outstanding, so the overall production-readiness checklist is not complete. Bank integrations and automatic income forecasting are intentionally deferred.
