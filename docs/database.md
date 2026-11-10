# Database design

## Storage rules

PostgreSQL is the source of truth. Use UUID primary keys, UTC `timestamptz` columns, foreign keys, explicit unique constraints, and indexes for owner-scoped time-range queries. Store monetary values as signed integer minor units (`BIGINT`) with an ISO 4217 currency code and recorded currency exponent; do not use floating-point types. Preserve source amounts and the exchange-rate evidence used to calculate AMD amounts.

Use soft deletion only for user-managed configuration where retention is appropriate. Financial records and audit history should be retained and corrected through explicit reversal/adjustment records, not deleted. Use database transactions for imports and ledger-affecting updates.

## Initial entities

| Entity | Important fields and constraints |
|---|---|
| `users` | Existing Laravel scaffold currently uses a numeric primary key; the MVP has one authorized owner. A future owner-identity migration must account for already-applied scaffold migrations before changing this key. |
| `bank_connections` | UUID, provider, explicit connection status, encrypted token material when supported, last sync, failure metadata. Unique provider identity per owner where available. |
| `accounts` | UUID, owner, name, asset/credit classification, currency/exponent, opening balance (owned funds for assets; outstanding liability for credit accounts), optional credit limit, and active state. Cash accounts are not a supported account type. Never count credit limits as funds. |
| `transactions` | UUID, account, provider transaction ID, idempotency key, UTC transaction/posting timestamps, status, type, integer amount/currency/exponent, category, merchant, optional refund reference, transfer group/direction/shared transfer reference/generated-counterpart marker, and sanitized provider metadata. Amount is a positive magnitude except signed adjustments. Unique `(account_id, idempotency_key)`, `(account_id, external_bank_transaction_id)`, and `(account_id, transfer_reference)` constraints protect imports and paired-side matching. |
| `transaction_transfers` | UUID, source and destination transaction/account references, matching method/confidence, review state. A matched internal transfer is excluded from income and expense totals. |
| `transaction_categories` | UUID, optional parent, name, system/custom marker, active state, owner scope. Seed the documented default hierarchy. |
| `income_sources` | UUID, owner, source name, currency, optional reliability metadata. |
| `income_events` | UUID, source, optional transaction, observed/expected date, actual or predicted status, amount/currency, confidence, and user correction metadata. Predictions are not ledger entries. |
| `income_expectations` | UUID, numeric owner reference, description, positive integer amount, currency/exponent, expected local financial date, and `PLANNED` or `COMPLETED` status. This is a manual assumption, separate from actual income and never a ledger entry. |
| `budget_settings` | UUID, owner, currency/exponent, positive planning horizon, nonnegative reserve/mandatory-payment/savings assumptions, and owner/currency-scoped revision. New revisions preserve earlier settings. The current ledger is recalculated rather than stored as a snapshot; scheduled effective dates are not implemented. |
| `recurring_payments` | UUID, owner/account/category, amount/currency, schedule, next expected date, mandatory flag, and active state. |
| `savings_goals` | UUID, owner, target amount/currency, target date, allocated amount, and active state. |
| `financial_reserves` | UUID, owner, amount/currency, purpose, effective dates, and active state. |
| `income_predictions` | UUID, source, predicted date/range, estimated amount/currency, confidence status, method version, and input-window metadata. Never include in actual balance. |
| `daily_snapshots` | UUID, owner, as-of UTC date, calculated integer amounts, and calculation version. Derived and reproducible; do not use as a replacement for the ledger. |
| `notifications` | UUID, owner, channel, category, event/deduplication key, sanitized payload, delivery state (`PENDING`, `SENT`, `SUPPRESSED`, `FAILED`), and timestamps. Unique `(owner_id, deduplication_key)` prevents repeated alerts. |
| `notification_preferences` | UUID, owner, channel, category, enabled flag, and timestamps. Unique `(owner_id, channel, category)` scopes alert settings to the owner. |
| `telegram_owner_links` | UUID, owner, pre-authorized Telegram user ID and private chat ID, active flag, and verification timestamp. Unique `owner_id`, `telegram_user_id`, and `telegram_chat_id`. |
| `telegram_webhook_updates` | UUID, owner, unique `update_id`, Telegram user/chat IDs, parsed command, response text, and processing timestamp for replay-safe webhook deduplication. |
| `ai_category_suggestions` | UUID, owner, transaction reference, suggested category reference, integer confidence (`1..100`), review state (`PENDING_REVIEW`, `APPROVED`, `REJECTED`), rationale, and review timestamp. Never mutates confirmed records. |
| `sync_logs` | UUID, connection, run status, counts, sanitized error details, and start/end timestamps. Never store credentials or raw sensitive provider payloads. |
| `audit_logs` | UUID, actor, action, resource reference, minimal before/after metadata, request correlation ID, and UTC timestamp. Exclude secrets and full bank payloads. |

## Transaction semantics

Transaction status is one of `PENDING`, `POSTED`, `REVERSED`, or `DECLINED`; type is one of `INCOME`, `EXPENSE`, `TRANSFER`, `REFUND`, `FEE`, or `ADJUSTMENT`. These values and account types are constrained by database enums. AMD uses exponent 0 and is stored in whole drams. Transaction amounts are positive magnitudes except signed adjustments. The account type, transaction type, and transfer direction determine whether an amount increases owned funds or credit liabilities. Refunds may reference their original expense or fee. Provider IDs are preserved verbatim and deduplicated within the account; a stable idempotency key is mandatory.

Each import is normalized to the provider-independent input shape, validated, deduplicated, and persisted atomically. Re-imports update an existing transaction's allowed lifecycle status without silently changing immutable financial fields. An explicit owned counterpart and stable reference shared by both account records creates a balanced pair of transfer rows atomically; importing the other side later updates that generated row instead of creating a duplicate. Status transitions are validated and synchronized across both transfer rows in the same transaction. Uncertain cross-account matches are not inferred; future adapters should retain such candidates for review rather than silently discarding them. Rejected imports are logged with a sanitized stage and error code, not the raw bank payload.

The PostgreSQL integrity migration adds checks for supported currency exponents, whole-dram AMD, supported signed amounts, and nonnegative credit limits. It also uses a composite foreign key to prevent a transaction's currency/exponent from differing from its account. These PostgreSQL-specific constraints are not created by SQLite migrations; application validation remains active on both drivers.

## Indexing

The implemented schema indexes owner/currency/active account queries, account plus transaction timestamp/status, and transaction refund references. It uniquely indexes account plus idempotency key/provider ID/transfer reference and transfer group plus account. Add indexes for future connection, income, notification, and audit queries when those tables are implemented and query plans justify them.

## Migrations and retention

Create schema changes through Laravel migrations with explicit foreign-key behavior and rollback paths. Seed categories and a development-only owner fixture without production financial data. Keep audit and transaction history immutable; document backup, restore, and retention procedures before production use.

Accounts, categories, transactions, budget settings, manual income expectations, Telegram owner links/webhook updates, notification preferences/notifications, and AI category suggestions currently have migrations and Eloquent models. The existing user table remains the Laravel scaffold's numeric-key schema. Bank connections, transfer-review records, audit logs, other income entities, and provider snapshot handling remain future work; this design is not a claim that those tables exist. Rolling back the income expectations migration deletes its stored assumptions.

## PostgreSQL validation

SQLite tests do not validate PostgreSQL migrations, locking, or timestamp binding. Before production use, run the complete backend suite and the PostgreSQL concurrency integration test against a dedicated disposable PostgreSQL database with `DB_CONNECTION=pgsql`, `DB_DATABASE` and `PGSQL_TEST_DATABASE` set to the same explicitly named local test database ending in `_test` or `_testing`, and valid local test credentials in `DB_PASSWORD`. The integration tests refuse to run migrations if the app is not in testing mode, the database is not explicitly designated, or the host is not local. The migration test trait recreates and rolls back schema, so never point it at a shared or production database. The application timezone remains UTC, while PostgreSQL uses its default schema and timezone settings: Laravel must not issue session-level `SET TIME ZONE` or `SET search_path` when connecting through Prisma's transaction-pooled runtime endpoint.

After adding manual income expectations, the user reported running the complete suite against the confirmed local disposable `financeassistant_test` database: 63 tests passed with 232 assertions, including the income constraint and concurrency tests.

On 2026-11-08, the user ran migrations and the full backend suite on a dedicated local PostgreSQL test database. The reported results were 2 PostgreSQL-specific tests passed with 43 assertions and 49 total tests passed with 164 assertions. The concurrent-import test starts workers together, but does not deterministically prove overlap at a particular database lock or replace production load testing.

After the `budget_settings` migration was added, the user reran the budget and PostgreSQL-specific tests on the dedicated local test database: 9 passed with 75 assertions. The full PostgreSQL suite then reported 58 passed with 200 assertions.
