import assert from "node:assert/strict";
import test from "node:test";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { AccountsPageView } from "../src/app/accounts/page";
import { BudgetPageView } from "../src/app/budget/page";
import { ExpectationsPageView } from "../src/app/expectations/page";
import { DashboardView } from "../src/app/page";
import { TransactionsPageView } from "../src/app/transactions/page";
import { BudgetSettingsForm } from "../src/components/BudgetSettingsForm";
import { ManualAccountForm } from "../src/components/ManualAccountForm";
import { ManualIncomeExpectationForm } from "../src/components/ManualIncomeExpectationForm";
import { ManualTransactionForm } from "../src/components/ManualTransactionForm";
import {
  type DashboardWorkspacePayload,
  budgetRefreshOutcome,
  createManualAccount,
  createManualTransaction,
  createManualIncomeExpectation,
  completeManualIncomeExpectation,
  fetchAccounts,
  fetchIncomeExpectations,
  fetchTransactions,
  fetchWorkspaceOverview,
  formatMoneyResource,
  resolveApiBaseUrl,
  saveBudgetSettings,
  SessionError,
  signIn,
  signOut,
  updateManualTransactionStatus,
} from "../src/lib/api";

test("manual transactions retain amount strings and use authenticated CSRF requests", async () => {
  const calls: { url: string; init?: RequestInit }[] = [];
  const fetcher: typeof fetch = async (input, init) => {
    calls.push({ url: String(input), init });
    if (String(input).endsWith("/csrf")) return new Response(null, { status: 204 });
    if (init?.method === "POST") return Response.json({ data: { id: "tx-id" } }, { status: 201 });
    return Response.json({ data: [{
      id: "tx-id", account_id: "account-id", account_name: "Wallet",
      amount: { minor_units: "9007199254740993", currency: "AMD", currency_exponent: 0 },
      type: "INCOME", status: "POSTED", transaction_date: "2025-03-08",
      description: "Salary", merchant: null, transfer_direction: null, is_generated_counterpart: false,
    }] });
  };
  await createManualTransaction({
    idempotency_key: "idempotency-id", account_id: "account-id",
    amount_minor_units: "9007199254740993", type: "INCOME",
    status: "POSTED", transaction_date: "2025-03-08",
  }, fetcher, () => "XSRF-TOKEN=token");
  assert.equal((calls[1].init?.headers as Record<string, string>)["X-XSRF-TOKEN"], "token");
  assert.equal(JSON.parse(String(calls[1].init?.body)).amount_minor_units, "9007199254740993");
  const transactions = await fetchTransactions(fetcher);
  assert.equal(transactions[0].amountFormatted, "AMD 9,007,199,254,740,993");
  assert.equal(transactions[0].accountName, "Wallet");
  await assert.rejects(fetchTransactions(async () => new Response(null, { status: 500 })), /Transactions could not be loaded/);
});

test("manual transaction lifecycle actions use the protected status endpoint", async () => {
  const calls: { url: string; init?: RequestInit }[] = [];
  const fetcher: typeof fetch = async (input, init) => {
    calls.push({ url: String(input), init });
    return new Response(null, { status: 204 });
  };
  await updateManualTransactionStatus("transaction-id", "POSTED", fetcher, () => "XSRF-TOKEN=token");
  assert.equal(calls[1].url, "/api/v1/transactions/transaction-id/status");
  assert.equal(calls[1].init?.method, "POST");
  assert.equal(JSON.parse(String(calls[1].init?.body)).status, "POSTED");
});

test("manual income expectations preserve integer precision and use owner-scoped CSRF endpoints", async () => {
  const calls: { url: string; init?: RequestInit }[] = [];
  const fetcher: typeof fetch = async (input, init) => {
    calls.push({ url: String(input), init });
    if (String(input).endsWith("/csrf")) return new Response(null, { status: 204 });
    if (init?.method === "POST") return Response.json({ data: { id: "expectation-id" } }, { status: 201 });
    return Response.json({ data: [{
      id: "expectation-id", description: "Salary",
      amount: { minor_units: "9007199254740993", currency: "AMD", currency_exponent: 0 },
      expected_date: "2025-03-08", status: "PLANNED",
    }] });
  };
  await createManualIncomeExpectation({
    id: "expectation-id", description: "Salary",
    amount_minor_units: "9007199254740993", expected_date: "2025-03-08",
  }, fetcher, () => "XSRF-TOKEN=token");
  assert.equal((calls[1].init?.headers as Record<string, string>)["X-XSRF-TOKEN"], "token");
  assert.equal(JSON.parse(String(calls[1].init?.body)).amount_minor_units, "9007199254740993");
  const expectations = await fetchIncomeExpectations(fetcher);
  assert.equal(expectations[0].amountFormatted, "AMD 9,007,199,254,740,993");
  await completeManualIncomeExpectation("expectation-id", fetcher, () => "XSRF-TOKEN=token");
  assert.equal(calls.at(-1)?.url, "/api/v1/income-expectations/expectation-id/complete");
  assert.equal(calls.at(-1)?.init?.method, "POST");
});

test("manual transaction form requires an account and distinguishes transfer fields", () => {
  const html = renderToStaticMarkup(
    <ManualTransactionForm
      accounts={[{
        id: "account-id", name: "Wallet", type: "ASSET", currency: "AMD",
        postedBalanceFormatted: "AMD 0", pendingChangeFormatted: "AMD 0",
        creditLiabilityFormatted: "AMD 0", isActive: true,
      }]}
      busy={false}
      onBusyChange={() => {}}
      onSaved={async () => true}
    />,
  );
  assert.match(html, /Add a manual transaction/);
  assert.match(html, /integer AMD units/);
  assert.match(html, /Financial date \(Yerevan\)/);
});

test("manual income expectation form labels assumptions and excludes them from current money", () => {
  const html = renderToStaticMarkup(
    <ManualIncomeExpectationForm busy={false} onBusyChange={() => {}} onSaved={async () => true} />,
  );
  assert.match(html, /Add an expected income/);
  assert.match(html, /manual assumption only/);
  assert.match(html, /whole AMD/);
});

test("manual accounts retain integer precision and use authenticated CSRF requests", async () => {
  const calls: { url: string; init?: RequestInit }[] = [];
  const account = { id: "c5512aad-4e31-4f66-8865-f9e2f5c087bd", name: "Bank", type: "ASSET" as const, opening_balance: "9007199254740993" };
  const fetcher: typeof fetch = async (input, init) => {
    calls.push({ url: String(input), init });
    if (String(input).endsWith("/csrf")) return new Response(null, { status: 204 });
    if (init?.method === "POST") return Response.json({ data: { id: account.id } }, { status: 201 });
    const money = { minor_units: "9007199254740993", currency: "AMD", currency_exponent: 0 };
    return Response.json({ data: [{ id: account.id, name: "Bank", type: "ASSET", is_active: true,
      posted_balance: money, pending_change: money, credit_liability: money }] });
  };
  await createManualAccount(account, fetcher, () => "XSRF-TOKEN=token");
  assert.equal((calls[1].init?.headers as Record<string, string>)["X-XSRF-TOKEN"], "token");
  assert.equal(JSON.parse(String(calls[1].init?.body)).opening_balance, "9007199254740993");
  assert.equal((await fetchAccounts(fetcher))[0].postedBalanceFormatted, "AMD 9,007,199,254,740,993");
  await assert.rejects(fetchAccounts(async () => new Response(null, { status: 500 })), /Accounts could not be loaded/);
});

test("manual account form requires an explicitly entered opening balance and distinguishes credit", () => {
  const html = renderToStaticMarkup(
    <ManualAccountForm busy={false} onBusyChange={() => {}} onSaved={async () => true} />,
  );
  assert.match(html, /Add a manual account/);
  assert.match(html, /Opening owned balance \(AMD\)/);
  assert.match(html, /Credit liability/);
  assert.doesNotMatch(html, /value="0"/);
  assert.match(html, /does not connect to a bank/);
});

test("saved budget is confirmed only when its updated data is available", () => {
  assert.equal(budgetRefreshOutcome({ connectionState: "unavailable", apiHealthy: false, data: null }), "unavailable");
  assert.equal(budgetRefreshOutcome({ connectionState: "unauthenticated", apiHealthy: true, data: null }), "expired");
  const data: DashboardWorkspacePayload = {
    balance: {
      currency: "AMD", postedOwnedFundsFormatted: "AMD 0", pendingOwnedFundsChangeFormatted: "AMD 0",
      pendingOwnedFundsOutflowsFormatted: "AMD 0", creditLiabilitiesFormatted: "AMD 0",
      pendingCreditLiabilityChangeFormatted: "AMD 0",
    },
    budget: null, accounts: [], recentTransactions: [], incomeExpectations: [],
  };
  assert.equal(budgetRefreshOutcome({ connectionState: "ready", apiHealthy: true, data }), "unavailable");
  data.budget = {
    currency: "AMD", planningDays: 30, availableFundsFormatted: "AMD 0", dailyLimitFormatted: "AMD 0",
    mandatoryPaymentsFormatted: "AMD 0", protectedReserveFormatted: "AMD 0", allocatedSavingsFormatted: "AMD 0",
  };
  assert.equal(budgetRefreshOutcome({ connectionState: "ready", apiHealthy: true, data }), "ready");
});

test("budget settings form displays explicit AMD assumptions without fabricated defaults", () => {
  const html = renderToStaticMarkup(
    <BudgetSettingsForm busy={false} onBusyChange={() => {}} onSaved={async () => true} />,
  );
  assert.match(html, /Mandatory payments \(AMD\)/);
  assert.match(html, /Protected reserve \(AMD\)/);
  assert.match(html, /Allocated savings \(AMD\)/);
  assert.match(html, /Planning horizon \(days\)/);
  assert.doesNotMatch(html, /value="(?:0|30)"/);
  const populated = renderToStaticMarkup(
    <BudgetSettingsForm initial={{
      mandatory_payments: "9007199254740993", protected_reserve: "0", allocated_savings: "123", planning_days: 30,
    }} busy={false} onBusyChange={() => {}} onSaved={async () => true} />,
  );
  assert.match(populated, /value="9007199254740993"/);
  assert.match(populated, /value="30"/);
});

test("budget settings use CSRF and preserve amounts as strings", async () => {
  const calls: { url: string; init?: RequestInit }[] = [];
  const fetcher: typeof fetch = async (input, init) => {
    calls.push({ url: String(input), init });
    return new Response(null, { status: 204 });
  };
  await saveBudgetSettings({
    mandatory_payments: "9007199254740993",
    protected_reserve: "0",
    allocated_savings: "10",
    planning_days: 30,
  }, fetcher, () => "XSRF-TOKEN=token");
  assert.deepEqual(calls.map((call) => call.url), ["/api/v1/session/csrf", "/api/v1/budget/settings"]);
  assert.equal((calls[1].init?.headers as Record<string, string>)["X-XSRF-TOKEN"], "token");
  assert.equal(JSON.parse(String(calls[1].init?.body)).mandatory_payments, "9007199254740993");
  await assert.rejects(saveBudgetSettings({
    mandatory_payments: "0", protected_reserve: "0", allocated_savings: "0", planning_days: 30,
  }, async (input) => new Response(null, { status: String(input).endsWith("/csrf") ? 204 : 422 }), () => "XSRF-TOKEN=token"),
  (error: unknown) => error instanceof SessionError && error.status === 422);
});

test("empty and unauthenticated states never ship unlabeled demo figures", () => {
  const emptyDashboardHtml = renderToStaticMarkup(<DashboardView />);
  assert.match(emptyDashboardHtml, /Financial data is not connected yet/);
  assert.match(
    emptyDashboardHtml,
    /No sample balances or estimates are shown here\./,
  );
  assert.doesNotMatch(emptyDashboardHtml, /AMD\s+\d/);

  const unauthHtml = renderToStaticMarkup(
    <DashboardView connectionState="unauthenticated" />,
  );
  assert.match(unauthHtml, /Owner authentication required/);
  assert.doesNotMatch(unauthHtml, /AMD\s+\d/);

  for (const view of [
    <AccountsPageView key="accounts" />,
    <TransactionsPageView key="transactions" />,
    <BudgetPageView key="budget" />,
    <ExpectationsPageView key="expectations" />,
  ]) {
    const html = renderToStaticMarkup(view);
    assert.match(html, /Financial data is not connected yet/);
    assert.doesNotMatch(html, /AMD\s+\d/);
  }
});

test("session requests use same-origin CSRF protection and do not send passwords in URLs", async () => {
  const calls: { url: string; init?: RequestInit }[] = [];
  const fetcher: typeof fetch = async (input, init) => {
    calls.push({ url: String(input), init });
    return new Response(null, { status: 204 });
  };
  const cookie = () => "other=value; XSRF-TOKEN=encrypted%2Btoken";

  await signIn("user@example.test", "test-password", fetcher, cookie);
  await signOut(fetcher, cookie);

  assert.deepEqual(calls.map((call) => call.url), [
    "/api/v1/session/csrf", "/api/v1/session",
    "/api/v1/session/csrf", "/api/v1/session",
  ]);
  assert.equal((calls[1].init?.headers as Record<string, string>)["X-XSRF-TOKEN"], "encrypted+token");
  assert.equal(calls[1].init?.credentials, "same-origin");
  assert.equal(calls[3].init?.method, "DELETE");
  assert.equal(calls[3].init?.body, undefined);
  assert.ok(calls.every((call) => !call.url.includes("test-password")));
});

test("session failures and missing CSRF cookies are explicit", async () => {
  await assert.rejects(
    signIn("user@example.test", "wrong", async (input) =>
      new Response(null, { status: String(input).endsWith("/csrf") ? 204 : 422 }), () => "XSRF-TOKEN=token"),
    (error: unknown) => error instanceof SessionError && error.status === 422,
  );
  await assert.rejects(
    signOut(async () => new Response(null, { status: 204 }), () => ""),
    /CSRF cookie is missing/,
  );
});

test("only a missing budget is treated as an unset budget; server errors remain unavailable", async () => {
  const money = { minor_units: "0", currency: "AMD", currency_exponent: 0 };
  const fetchWithBudgetStatus = (budgetStatus: number): typeof fetch => async (input) => {
    const url = String(input);
    if (url.endsWith("/status")) return Response.json({ status: "ok" });
    if (url.includes("/balance?")) return Response.json({ data: {
      owned_funds: money, pending_owned_funds_change: money, pending_owned_funds_debits: money,
      credit_liabilities: money, pending_credit_liability_change: money,
    } });
    return new Response(null, { status: budgetStatus });
  };
  assert.equal((await fetchWorkspaceOverview(fetchWithBudgetStatus(404))).connectionState, "ready");
  assert.equal((await fetchWorkspaceOverview(fetchWithBudgetStatus(404))).data?.budget, null);
  assert.equal((await fetchWorkspaceOverview(fetchWithBudgetStatus(500))).connectionState, "unavailable");
  assert.equal((await fetchWorkspaceOverview(fetchWithBudgetStatus(401))).connectionState, "unauthenticated");
});

test("dashboard and secondary pages keep posted, pending, credit liability, and assumption labels distinct", () => {
  const payload: DashboardWorkspacePayload = {
    balance: {
      currency: "AMD",
      postedOwnedFundsFormatted: "AMD 150,000",
      pendingOwnedFundsChangeFormatted: "AMD 30,000",
      pendingOwnedFundsOutflowsFormatted: "AMD 10,000",
      creditLiabilitiesFormatted: "AMD 15,000",
      pendingCreditLiabilityChangeFormatted: "AMD 0",
    },
    budget: {
      currency: "AMD",
      planningDays: 25,
      availableFundsFormatted: "AMD 85,000",
      dailyLimitFormatted: "AMD 3,400",
      mandatoryPaymentsFormatted: "AMD 20,000",
      protectedReserveFormatted: "AMD 30,000",
      allocatedSavingsFormatted: "AMD 5,000",
    },
    accounts: [
      {
        id: "acc-1",
        name: "Primary Checking",
        type: "ASSET",
        currency: "AMD",
        postedBalanceFormatted: "AMD 150,000",
        pendingChangeFormatted: "AMD 30,000",
        creditLiabilityFormatted: "AMD 0",
        isActive: true,
      },
      {
        id: "acc-2",
        name: "Credit Card",
        type: "CREDIT",
        currency: "AMD",
        postedBalanceFormatted: "AMD 0",
        pendingChangeFormatted: "AMD 0",
        creditLiabilityFormatted: "AMD 15,000",
        isActive: true,
      },
    ],
    recentTransactions: [
      {
        id: "tx-1",
        accountId: "acc-1",
        accountName: "Primary Checking",
        type: "EXPENSE",
        status: "PENDING",
        amountFormatted: "AMD -10,000",
        yerevanDate: "2026-03-11",
        description: "Utility hold",
        merchant: "Electric Co",
        transferDirection: null,
        isGeneratedCounterpart: false,
      },
      {
        id: "tx-2",
        accountId: "acc-2",
        accountName: "Credit Card",
        type: "EXPENSE",
        status: "POSTED",
        amountFormatted: "AMD -3,000",
        yerevanDate: "2026-03-10",
        description: "Pharmacy",
        merchant: "Pharmacy",
        transferDirection: null,
        isGeneratedCounterpart: false,
      },
    ],
    incomeExpectations: [
      {
        id: "exp-1",
        description: "Consulting retainer",
        amountFormatted: "AMD 250,000",
        expectedDate: "2026-03-15",
        status: "PLANNED",
      },
    ],
  };

  const html = renderToStaticMarkup(
    <DashboardView connectionState="ready" payload={payload} />,
  );

  assert.match(html, /Posted balance \(owned funds\)/);
  assert.match(html, /AMD 150,000/);
  assert.match(html, /Pending outflows \(unsettled\)/);
  assert.match(html, /AMD 10,000/);
  assert.match(html, /Credit liabilities/);
  assert.match(html, /AMD 15,000/);
  assert.match(html, /Recommended daily limit/);
  assert.match(html, /AMD 3,400/);
  assert.match(html, /Available funds \(AMD 85,000\) over 25 days\./);
  assert.match(html, /Manual Income Expectations \(Assumptions Only\)/);
  assert.match(html, /Excluded from actual balance &amp; budget/);
  assert.match(html, /Consulting retainer/);
  assert.match(html, /PENDING/);
  assert.match(html, /POSTED/);
});

test("accessible navigation and responsive mobile layout classes are present", () => {
  const html = renderToStaticMarkup(<AccountsPageView />);

  assert.match(html, /Skip to main content/);
  assert.match(html, /aria-label="Primary navigation"/);
  assert.match(html, /aria-current="page"/);
  assert.match(html, /sm:hidden/);
  assert.match(html, /Asia\/Yerevan/);
});

test("versioned /api/v1 balance and budget responses are formatted losslessly and handle unauthenticated states", async () => {
  assert.equal(
    resolveApiBaseUrl("http://localhost:8000/api/v1/"),
    "http://localhost:8000/api/v1",
  );
  assert.throws(
    () => resolveApiBaseUrl("http://localhost:8000/api/v2"),
    /versioned \/api\/v1 boundary/,
  );

  assert.equal(
    formatMoneyResource({
      minor_units: "9007199254740993",
      currency: "AMD",
      currency_exponent: 0,
    }),
    "AMD 9,007,199,254,740,993",
  );
  assert.equal(
    formatMoneyResource({
      minor_units: "-7000",
      currency: "AMD",
      currency_exponent: 0,
    }),
    "AMD -7,000",
  );
  assert.equal(
    formatMoneyResource({
      minor_units: "1234",
      currency: "USD",
      currency_exponent: 2,
    }),
    "USD 12.34",
  );

  const unauthResult = await fetchWorkspaceOverview(async (input) => {
    const url = String(input);
    if (url.endsWith("/status")) {
      return new Response(JSON.stringify({ status: "ok" }), { status: 200 });
    }
    return new Response(JSON.stringify({ message: "Unauthenticated." }), {
      status: 401,
    });
  }, "http://localhost:8000/api/v1");

  assert.equal(unauthResult.connectionState, "unauthenticated");
  assert.equal(unauthResult.apiHealthy, true);
  assert.equal(unauthResult.data, null);

  const readyResult = await fetchWorkspaceOverview(async (input) => {
    const url = String(input);
    if (url.endsWith("/status")) {
      return new Response(JSON.stringify({ status: "ok" }), { status: 200 });
    }
    if (url.includes("/balance?")) {
      return new Response(
        JSON.stringify({
          data: {
            owned_funds: {
              minor_units: "100000",
              currency: "AMD",
              currency_exponent: 0,
            },
            pending_owned_funds_change: {
              minor_units: "-7000",
              currency: "AMD",
              currency_exponent: 0,
            },
            pending_owned_funds_debits: {
              minor_units: "7000",
              currency: "AMD",
              currency_exponent: 0,
            },
            credit_liabilities: {
              minor_units: "40000",
              currency: "AMD",
              currency_exponent: 0,
            },
            pending_credit_liability_change: {
              minor_units: "0",
              currency: "AMD",
              currency_exponent: 0,
            },
          },
        }),
        { status: 200 },
      );
    }
    if (url.includes("/budget?")) {
      return new Response(
        JSON.stringify({
          data: {
            available_funds: {
              minor_units: "83000",
              currency: "AMD",
              currency_exponent: 0,
            },
            daily_limit: {
              minor_units: "8300",
              currency: "AMD",
              currency_exponent: 0,
            },
            mandatory_payments: {
              minor_units: "10000",
              currency: "AMD",
              currency_exponent: 0,
            },
            protected_reserve: {
              minor_units: "0",
              currency: "AMD",
              currency_exponent: 0,
            },
            allocated_savings: {
              minor_units: "0",
              currency: "AMD",
              currency_exponent: 0,
            },
            planning_days: 10,
          },
        }),
        { status: 200 },
      );
    }
    return new Response(null, { status: 404 });
  }, "http://localhost:8000/api/v1");

  assert.equal(readyResult.connectionState, "ready");
  assert.equal(
    readyResult.data?.balance.postedOwnedFundsFormatted,
    "AMD 100,000",
  );
  assert.equal(
    readyResult.data?.balance.pendingOwnedFundsOutflowsFormatted,
    "AMD 7,000",
  );
  assert.equal(
    readyResult.data?.balance.creditLiabilitiesFormatted,
    "AMD 40,000",
  );
  assert.equal(readyResult.data?.budget?.availableFundsFormatted, "AMD 83,000");
  assert.equal(readyResult.data?.budget?.dailyLimitFormatted, "AMD 8,300");
  assert.equal(readyResult.data?.budget?.planningDays, 10);
});
