import React from "react";
import type {
  AccountItemDto,
  BudgetSummaryDto,
  DashboardWorkspacePayload,
  IncomeExpectationDto,
  TransactionItemDto,
  WorkspaceConnectionState,
} from "../lib/api";

export function EmptyWorkspaceBanner({
  connectionState = "empty",
}: {
  connectionState?: WorkspaceConnectionState;
}) {
  const heading =
    connectionState === "unauthenticated"
      ? "Owner authentication required"
      : "Financial data is not connected yet";

  const description =
    connectionState === "unauthenticated"
      ? "Sign in as the workspace owner to view your private ledger. No sample balances or estimates are shown here."
      : "Account balances, budgets, manual income expectations, and transactions will appear after their backend API endpoints and owner access controls are active. No sample balances or estimates are shown here.";

  return (
    <section
      aria-labelledby="workspace-status"
      className="max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-8"
    >
      <div className="flex items-start gap-4">
        <span
          aria-hidden="true"
          className="mt-1 size-2.5 shrink-0 rounded-full bg-amber-500"
        />
        <div>
          <h2
            id="workspace-status"
            className="text-base font-semibold text-slate-900"
          >
            {heading}
          </h2>
          <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
        </div>
      </div>
    </section>
  );
}

export function FinancialBoundaryCards({
  payload,
}: {
  payload: DashboardWorkspacePayload | null;
}) {
  const balance = payload?.balance ?? null;
  const budget = payload?.budget ?? null;

  return (
    <section aria-label="Financial summary boundaries">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <span className="inline-flex rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800">
            Posted Ledger Data
          </span>
          <h2 className="mt-3 text-sm font-semibold text-slate-900">
            Posted balance (owned funds)
          </h2>
          <p className="mt-2 text-xl font-semibold tracking-tight text-slate-950">
            {balance ? balance.postedOwnedFundsFormatted : "Not connected"}
          </p>
          <p className="mt-2 text-xs leading-5 text-slate-600">
            Confirmed asset-account funds only. Excludes pending transactions,
            credit limits, and manual income assumptions.
          </p>
        </article>

        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <span className="inline-flex rounded-md bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">
            Pending Lifecycle
          </span>
          <h2 className="mt-3 text-sm font-semibold text-slate-900">
            Pending outflows (unsettled)
          </h2>
          <p className="mt-2 text-xl font-semibold tracking-tight text-slate-950">
            {balance
              ? balance.pendingOwnedFundsOutflowsFormatted
              : "Not connected"}
          </p>
          <p className="mt-2 text-xs leading-5 text-slate-600">
            Deducted conservatively from available budget without being offset
            by pending inflows.
          </p>
        </article>

        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <span className="inline-flex rounded-md bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-800">
            Separate Liability
          </span>
          <h2 className="mt-3 text-sm font-semibold text-slate-900">
            Credit liabilities
          </h2>
          <p className="mt-2 text-xl font-semibold tracking-tight text-slate-950">
            {balance ? balance.creditLiabilitiesFormatted : "Not connected"}
          </p>
          <p className="mt-2 text-xs leading-5 text-slate-600">
            Tracked separately from owned asset funds. Credit limits are never
            counted as available money.
          </p>
        </article>

        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <span className="inline-flex rounded-md bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-800">
            Conservative Horizon
          </span>
          <h2 className="mt-3 text-sm font-semibold text-slate-900">
            Recommended daily limit
          </h2>
          <p className="mt-2 text-xl font-semibold tracking-tight text-slate-950">
            {budget ? budget.dailyLimitFormatted : "No saved assumptions"}
          </p>
          <p className="mt-2 text-xs leading-5 text-slate-600">
            {budget
              ? `Available funds (${budget.availableFundsFormatted}) over ${budget.planningDays} days.`
              : "Requires explicit owner-saved mandatory payment, reserve, and savings assumptions."}
          </p>
        </article>
      </div>
    </section>
  );
}

export function AccountsSection({ accounts }: { accounts: AccountItemDto[] }) {
  return (
    <section
      aria-labelledby="accounts-heading"
      className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    >
      <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2
            id="accounts-heading"
            className="text-base font-semibold text-slate-900"
          >
            Accounts & Credit Liabilities
          </h2>
          <p className="text-xs text-slate-600 sm:text-sm">
            Asset accounts hold owned funds; credit accounts track outstanding
            liabilities separately. Cash accounts are disabled.
          </p>
        </div>
      </div>

      {accounts.length === 0 ? (
        <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
          No authorized accounts are loaded from <code>/api/v1</code>.
        </p>
      ) : (
        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
          {accounts.map((account) => (
            <div
              key={account.id}
              className="rounded-xl border border-slate-200 p-4"
            >
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-slate-900">
                  {account.name}
                </h3>
                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                  {account.type}
                </span>
              </div>
              <dl className="mt-3 space-y-1 text-xs text-slate-600">
                <div className="flex justify-between">
                  <dt>Posted balance:</dt>
                  <dd className="font-medium text-slate-900">
                    {account.postedBalanceFormatted}
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt>Pending change:</dt>
                  <dd className="font-medium text-slate-900">
                    {account.pendingChangeFormatted}
                  </dd>
                </div>
                <div className="flex justify-between">
                  <dt>Credit liability:</dt>
                  <dd className="font-medium text-slate-900">
                    {account.creditLiabilityFormatted}
                  </dd>
                </div>
              </dl>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

export function TransactionsSection({
  transactions,
}: {
  transactions: TransactionItemDto[];
}) {
  return (
    <section
      aria-labelledby="transactions-heading"
      className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    >
      <h2
        id="transactions-heading"
        className="text-base font-semibold text-slate-900"
      >
        Transaction Ledger Lifecycle
      </h2>
      <p className="mt-1 text-xs text-slate-600 sm:text-sm">
        Posted, pending, reversed, and declined records remain strictly
        distinguishable. Paired internal transfers are neither income nor
        expenses.
      </p>

      {transactions.length === 0 ? (
        <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
          No ledger transactions are loaded. Pending, reversed, and declined
          records are never mixed with posted balances.
        </p>
      ) : (
        <div className="mt-4 overflow-x-auto">
          <table className="min-w-full divide-y divide-slate-200 text-left text-xs sm:text-sm">
            <thead>
              <tr className="text-slate-500">
                <th className="py-2 pr-4 font-medium">Date (Asia/Yerevan)</th>
                <th className="py-2 pr-4 font-medium">Description</th>
                <th className="py-2 pr-4 font-medium">Type</th>
                <th className="py-2 pr-4 font-medium">Status</th>
                <th className="py-2 font-medium">Amount</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {transactions.map((tx) => (
                <tr key={tx.id}>
                  <td className="py-2.5 pr-4 text-slate-600">
                    {tx.yerevanDate}
                  </td>
                  <td className="py-2.5 pr-4 font-medium text-slate-900">
                    {tx.description}
                  </td>
                  <td className="py-2.5 pr-4 text-slate-700">{tx.type}</td>
                  <td className="py-2.5 pr-4">
                    <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-800">
                      {tx.status}
                    </span>
                  </td>
                  <td className="py-2.5 font-semibold text-slate-900">
                    {tx.amountFormatted}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

export function BudgetSection({ budget }: { budget: BudgetSummaryDto | null }) {
  return (
    <section
      aria-labelledby="budget-heading"
      className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    >
      <h2
        id="budget-heading"
        className="text-base font-semibold text-slate-900"
      >
        Conservative Budget & Planning Horizon
      </h2>
      <p className="mt-1 text-xs text-slate-600 sm:text-sm">
        Deducts pending asset-account outflows, mandatory obligations, protected
        reserves, and allocated savings across the configured planning horizon
        (30-day default).
      </p>

      {!budget ? (
        <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
          No budget assumptions are saved yet. The system never invents a
          zero-valued budget without explicit owner assumptions.
        </p>
      ) : (
        <dl className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="rounded-xl bg-slate-50 p-4">
            <dt className="text-xs text-slate-500">
              Available spending funds ({budget.planningDays} days)
            </dt>
            <dd className="mt-1 text-lg font-semibold text-slate-950">
              {budget.availableFundsFormatted}
            </dd>
          </div>
          <div className="rounded-xl bg-slate-50 p-4">
            <dt className="text-xs text-slate-500">Recommended daily limit</dt>
            <dd className="mt-1 text-lg font-semibold text-slate-950">
              {budget.dailyLimitFormatted}
            </dd>
          </div>
          <div className="rounded-xl bg-slate-50 p-4">
            <dt className="text-xs text-slate-500">
              Protected reserve & mandatory deductions
            </dt>
            <dd className="mt-1 text-xs font-medium text-slate-800">
              Mandatory: {budget.mandatoryPaymentsFormatted} · Reserve:{" "}
              {budget.protectedReserveFormatted} · Savings:{" "}
              {budget.allocatedSavingsFormatted}
            </dd>
          </div>
        </dl>
      )}
    </section>
  );
}

export function IncomeExpectationsSection({
  expectations,
}: {
  expectations: IncomeExpectationDto[];
}) {
  return (
    <section
      aria-labelledby="expectations-heading"
      className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2
          id="expectations-heading"
          className="text-base font-semibold text-slate-900"
        >
          Manual Income Expectations (Assumptions Only)
        </h2>
        <span className="rounded-md border border-purple-200 bg-purple-50 px-2.5 py-0.5 text-xs font-semibold text-purple-900">
          Excluded from actual balance & budget
        </span>
      </div>
      <p className="mt-1 text-xs text-slate-600 sm:text-sm">
        User-assumed future or past expected income dates in Asia/Yerevan.
        Marking an expectation completed never creates or modifies a ledger
        transaction.
      </p>

      {expectations.length === 0 ? (
        <p className="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
          No manual income expectations are loaded from <code>/api/v1</code>.
        </p>
      ) : (
        <ul className="mt-4 divide-y divide-slate-100 text-xs sm:text-sm">
          {expectations.map((item) => (
            <li
              key={item.id}
              className="flex flex-col justify-between gap-1 py-3 sm:flex-row sm:items-center"
            >
              <div>
                <span className="font-medium text-slate-900">
                  {item.description}
                </span>
                <span className="ml-2 text-slate-500">
                  Expected date: {item.expectedDate}
                </span>
              </div>
              <div className="flex items-center gap-3">
                <span className="font-semibold text-slate-900">
                  {item.amountFormatted}
                </span>
                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">
                  {item.status}
                </span>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
