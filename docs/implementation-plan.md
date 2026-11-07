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
  - [ ] Add owner-scoped AMD assumption editing through the API and budget page; PostgreSQL and full browser verification remain pending.
- [x] Default the internal planning horizon to 30 days while allowing an explicit positive horizon.
- [ ] Implement budget and conservative planning-horizon calculations.
- [ ] Implement separately labelled manual income expectations; automated forecasts are deferred.
  - [x] Add an owner-scoped internal service to save, list, and manually complete assumptions without changing the ledger or budget; API and UI remain pending.
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
- [x] Add notification preferences and delivery deduplication tests.

## Phase 6 — AI assistance

- [x] Add optional categorization and natural-language query assistance behind deterministic services.
- [x] Require confidence/review for suggestions; never silently modify confirmed records.
- [x] Test prompt/data boundaries and ensure no arbitrary database query execution.

## Phase 7 — Web dashboard

- [x] Build responsive accessible dashboard and secondary pages.
- [x] Integrate versioned API data; never ship unlabeled production demo figures.
- [x] Add UI tests for key financial labels, empty states, and mobile layouts.

## Phase 8 — Production readiness

- [ ] Complete scoped security review, performance checks, backups/restore test, monitoring, and deployment documentation.
  - [x] Document backup/restore verification procedures, monitoring endpoints, and scoped security controls in `docs/security.md`.
- [x] Verify authorization, webhook protections, sensitive-data handling, and dependency checks.
- [x] Report remaining provider, deployment, or operational limitations explicitly.

## Status

The workspace began empty. Phase 1 foundations are in place, and the financial core subset of Phase 2 now has tested Money, account/category/transaction models, an atomic canonical import processor, ledger-derived balance summaries, and Yerevan day boundaries. SQLite verification passes. The user ran migrations and the complete test suite against a dedicated local PostgreSQL test database, including the PostgreSQL integrity and concurrent-import tests. Phase 2 is not complete: owner authentication, bank connections, transfer review, audit logging, and provider imports remain unimplemented. Docker Compose syntax is valid, but the Docker daemon was unavailable at initial setup, so service startup has not been exercised.
