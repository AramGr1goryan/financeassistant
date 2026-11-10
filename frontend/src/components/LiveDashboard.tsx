"use client";

import React, { useCallback, useEffect, useRef, useState } from "react";
import { AccountsSection, BudgetSection, FinancialBoundaryCards, IncomeExpectationsSection, TransactionsSection } from "./FinancialSections";
import { WorkspaceShell } from "./WorkspaceShell";
import { BudgetSettingsForm } from "./BudgetSettingsForm";
import { ManualAccountForm } from "./ManualAccountForm";
import { ManualTransactionForm } from "./ManualTransactionForm";
import { ManualIncomeExpectationForm } from "./ManualIncomeExpectationForm";
import { budgetRefreshOutcome, completeManualIncomeExpectation, fetchAccounts, fetchIncomeExpectations, fetchTransactions, fetchWorkspaceOverview, SessionError, signIn, signOut, updateManualTransactionStatus, type WorkspaceOverviewResult } from "../lib/api";

export function LiveDashboard({ currentPath = "/" }: { currentPath?: "/" | "/budget" | "/accounts" | "/transactions" | "/expectations" }) {
  const [result, setResult] = useState<WorkspaceOverviewResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const requestId = useRef(0);

  const refresh = useCallback(async () => {
    const id = ++requestId.current;
    let next = await fetchWorkspaceOverview(fetch, undefined, "AMD", 0, currentPath !== "/accounts");
    if ((currentPath === "/accounts" || currentPath === "/transactions" || currentPath === "/expectations") && next.connectionState === "ready" && next.data) {
      try {
        const [accounts, recentTransactions, incomeExpectations] = await Promise.all([
          currentPath === "/accounts" || currentPath === "/transactions" ? fetchAccounts() : Promise.resolve(next.data.accounts),
          currentPath === "/transactions" ? fetchTransactions() : Promise.resolve(next.data.recentTransactions),
          currentPath === "/expectations" ? fetchIncomeExpectations() : Promise.resolve(next.data.incomeExpectations),
        ]);
        next = { ...next, data: { ...next.data, accounts, recentTransactions, incomeExpectations } };
      } catch (failure) {
        next = failure instanceof SessionError && failure.status === 401
          ? { connectionState: "unauthenticated", apiHealthy: true, data: null }
          : { connectionState: "unavailable", apiHealthy: true, data: null };
      }
    }
    if (id === requestId.current) setResult(next);
    return id === requestId.current ? next : null;
  }, [currentPath]);

  async function refreshAfterAccountSave(): Promise<boolean> {
    const next = await refresh();
    if (!next) return false;
    if (next.connectionState !== "ready") {
      setError("Account was created, but the account list could not be refreshed. Reload before making another change.");
      return false;
    }
    setError("");
    return true;
  }

  async function refreshAfterBudgetSave(): Promise<boolean> {
    const next = await refresh();
    if (!next) return false;
    const outcome = budgetRefreshOutcome(next);
    if (outcome !== "ready") {
      setError(outcome === "expired"
        ? "Settings were saved, but your session expired before the updated budget could be loaded. Sign in again."
        : "Settings were saved, but the updated budget could not be loaded. Refresh the page before making another change.");
      return false;
    }
    setError("");
    return true;
  }

  async function retry() {
    const next = await refresh();
    if (next?.connectionState === "ready") setError("");
  }

  useEffect(() => {
    const pending = requestId;
    void refresh();
    return () => { pending.current++; };
  }, [refresh]);

  async function handleSignIn(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      await signIn(email, password);
      setPassword("");
      await refresh();
    } catch (failure) {
      setPassword("");
      setError(failure instanceof SessionError && failure.status === 422
        ? "Invalid email or password."
        : failure instanceof SessionError && failure.status === 429
          ? "Too many attempts. Please wait before trying again."
          : "Sign-in failed. Check the connection and try again.");
    } finally {
      setBusy(false);
    }
  }

  async function handleSignOut() {
    setBusy(true);
    setError("");
    requestId.current++;
    setResult(null);
    try {
      await signOut();
      setResult({ connectionState: "unauthenticated", apiHealthy: true, data: null });
    } catch {
      setResult({ connectionState: "unavailable", apiHealthy: false, data: null });
      setError("Sign-out failed. Your session may still be active; try again.");
    } finally {
      setBusy(false);
    }
  }

  const ready = result?.connectionState === "ready" && result.data !== null;

  return (
    <WorkspaceShell
      currentPath={currentPath}
      title="A clearer view of your finances starts here."
      subtitle="Posted funds, pending outflows, and credit liabilities are shown separately."
      statusLabel={ready ? "Connected (/api/v1)" : result?.connectionState === "unauthenticated" ? "Sign-in required" : "Connection status"}
    >
      {error && <p role="alert" className="rounded-xl bg-red-50 p-4 text-sm text-red-800">{error}</p>}
      {!result && <p role="status">Loading workspace…</p>}
      {result?.connectionState === "unavailable" && (
        <section className="rounded-xl bg-amber-50 p-5">
          <p>Financial service is unavailable. Please try again.</p>
          <button type="button" className="mt-3 underline" onClick={() => void retry()}>Retry</button>
          <button type="button" disabled={busy} className="ml-4 underline" onClick={() => void handleSignOut()}>Sign out</button>
        </section>
      )}
      {result?.connectionState === "unauthenticated" && (
        <form onSubmit={handleSignIn} className="max-w-md space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
          <h2 className="text-lg font-semibold">Sign in</h2>
          <p className="text-sm text-slate-600">Use an existing account. Public registration is closed.</p>
          <label className="block text-sm">Email
            <input className="mt-1 block w-full rounded border border-slate-300 p-2" type="email" autoComplete="username" required value={email} onChange={(event) => setEmail(event.target.value)} />
          </label>
          <label className="block text-sm">Password
            <input className="mt-1 block w-full rounded border border-slate-300 p-2" type="password" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} />
          </label>
          <button disabled={busy} className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50" type="submit">{busy ? "Signing in…" : "Sign in"}</button>
        </form>
      )}
      {ready && result.data && (
        <>
          <button type="button" disabled={busy} onClick={() => void handleSignOut()} className="rounded border border-slate-400 px-4 py-2 text-sm disabled:opacity-50">Sign out</button>
          {currentPath === "/accounts" ? (
            <>
              <AccountsSection accounts={result.data.accounts} />
              <ManualAccountForm busy={busy} onBusyChange={setBusy} onSaved={refreshAfterAccountSave} />
            </>
          ) : currentPath === "/transactions" ? (
            <>
              <TransactionsSection
                transactions={result.data.recentTransactions}
                busy={busy}
                onStatusChange={async (id, status) => {
                  setBusy(true);
                  try {
                    await updateManualTransactionStatus(id, status);
                    const next = await refresh();
                    if (next?.connectionState === "ready") {
                      setError("");
                      return true;
                    }
                    setError("Transaction status was updated, but refreshed data could not be loaded.");
                    return false;
                  } catch {
                    setError("Transaction status could not be updated. Refresh the ledger before trying again.");
                    return false;
                  } finally {
                    setBusy(false);
                  }
                }}
              />
              <ManualTransactionForm
                accounts={result.data.accounts}
                busy={busy}
                onBusyChange={setBusy}
                onSaved={async () => {
                  const next = await refresh();
                  if (next?.connectionState === "ready") {
                    setError("");
                    return true;
                  }
                  setError("Transaction was saved, but updated data could not be loaded. Reload before making another change.");
                  return false;
                }}
              />
            </>
          ) : currentPath === "/expectations" ? (
            <>
              <IncomeExpectationsSection
                expectations={result.data.incomeExpectations}
                busy={busy}
                onComplete={async (id) => {
                  setBusy(true);
                  try {
                    await completeManualIncomeExpectation(id);
                    const next = await refresh();
                    if (next?.connectionState === "ready") {
                      setError("");
                      return true;
                    }
                    setError("Expectation was completed, but updated data could not be loaded. Reload to verify its status.");
                    return false;
                  } catch {
                    setError("Expectation could not be completed. Refresh the list before trying again.");
                    return false;
                  } finally {
                    setBusy(false);
                  }
                }}
              />
              <ManualIncomeExpectationForm
                busy={busy}
                onBusyChange={setBusy}
                onSaved={async () => {
                  const next = await refresh();
                  if (next?.connectionState === "ready") {
                    setError("");
                    return true;
                  }
                  setError("Expectation was saved, but updated data could not be loaded. Reload before making another change.");
                  return false;
                }}
              />
            </>
          ) : (
            <>
              <FinancialBoundaryCards payload={result.data} />
              <BudgetSection budget={result.data.budget} />
            </>
          )}
          {currentPath === "/budget" && (
            <BudgetSettingsForm
              initial={result.data.budget?.settings}
              busy={busy}
              onBusyChange={setBusy}
              onSaved={refreshAfterBudgetSave}
            />
          )}
        </>
      )}
    </WorkspaceShell>
  );
}
