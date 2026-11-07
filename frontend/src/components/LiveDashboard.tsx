"use client";

import React, { useEffect, useRef, useState } from "react";
import { BudgetSection, FinancialBoundaryCards } from "./FinancialSections";
import { WorkspaceShell } from "./WorkspaceShell";
import { BudgetSettingsForm } from "./BudgetSettingsForm";
import { fetchWorkspaceOverview, SessionError, signIn, signOut, type WorkspaceOverviewResult } from "../lib/api";

export function LiveDashboard({ currentPath = "/" }: { currentPath?: "/" | "/budget" }) {
  const [result, setResult] = useState<WorkspaceOverviewResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const requestId = useRef(0);

  async function refresh() {
    const id = ++requestId.current;
    const next = await fetchWorkspaceOverview();
    if (id === requestId.current) setResult(next);
  }

  useEffect(() => {
    const pending = requestId;
    void refresh();
    return () => { pending.current++; };
  }, []);

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
          <button type="button" className="mt-3 underline" onClick={() => void refresh()}>Retry</button>
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
          <FinancialBoundaryCards payload={result.data} />
          <BudgetSection budget={result.data.budget} />
          {currentPath === "/budget" && (
            <BudgetSettingsForm
              initial={result.data.budget?.settings}
              busy={busy}
              onBusyChange={setBusy}
              onSaved={refresh}
            />
          )}
          <p className="text-sm text-slate-600">Account details, transactions, and income expectations are not connected to this dashboard yet.</p>
        </>
      )}
    </WorkspaceShell>
  );
}
