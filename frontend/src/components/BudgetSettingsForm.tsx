"use client";

import React, { useState } from "react";
import { saveBudgetSettings, SessionError, type BudgetSettingsInput } from "../lib/api";

export function BudgetSettingsForm({
  initial,
  busy,
  onBusyChange,
  onSaved,
}: {
  initial?: BudgetSettingsInput;
  busy: boolean;
  onBusyChange: (busy: boolean) => void;
  onSaved: () => Promise<boolean>;
}) {
  const [mandatory, setMandatory] = useState(initial?.mandatory_payments ?? "");
  const [reserve, setReserve] = useState(initial?.protected_reserve ?? "");
  const [savings, setSavings] = useState(initial?.allocated_savings ?? "");
  const [days, setDays] = useState(initial ? String(initial.planning_days) : "");
  const [message, setMessage] = useState("");

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    if (![mandatory, reserve, savings].every((value) => /^(0|[1-9]\d*)$/.test(value) && value.length <= 19 && BigInt(value) <= BigInt("9223372036854775807"))
      || !/^[1-9]\d*$/.test(days) || days.length > 10 || BigInt(days) > BigInt("2147483647")) {
      setMessage("Enter whole, non-negative AMD amounts and a planning horizon from 1 to 2147483647 days.");
      return;
    }

    onBusyChange(true);
    try {
      await saveBudgetSettings({
        mandatory_payments: mandatory,
        protected_reserve: reserve,
        allocated_savings: savings,
        planning_days: Number(days),
      });
      if (await onSaved()) setMessage("Settings saved.");
    } catch (failure) {
      setMessage(failure instanceof SessionError && failure.status === 401
        ? "Your session has expired. Sign in again."
        : "Could not confirm whether settings were saved. Refresh the budget before trying again.");
    } finally {
      onBusyChange(false);
    }
  }

  return (
    <form onSubmit={submit} className="max-w-xl space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
      <h2 className="text-lg font-semibold">Budget assumptions</h2>
      <p className="text-sm text-slate-600">Enter totals for the planning period in whole AMD. Do not count pending outflows again as mandatory payments.</p>
      <fieldset disabled={busy} className="space-y-3">
        <label className="block text-sm">Mandatory payments (AMD)
          <input className="mt-1 block w-full rounded border border-slate-300 p-2" inputMode="numeric" required value={mandatory} onChange={(event) => setMandatory(event.target.value)} />
        </label>
        <label className="block text-sm">Protected reserve (AMD)
          <input className="mt-1 block w-full rounded border border-slate-300 p-2" inputMode="numeric" required value={reserve} onChange={(event) => setReserve(event.target.value)} />
        </label>
        <label className="block text-sm">Allocated savings (AMD)
          <input className="mt-1 block w-full rounded border border-slate-300 p-2" inputMode="numeric" required value={savings} onChange={(event) => setSavings(event.target.value)} />
        </label>
        <label className="block text-sm">Planning horizon (days)
          <input className="mt-1 block w-full rounded border border-slate-300 p-2" inputMode="numeric" required value={days} onChange={(event) => setDays(event.target.value)} />
        </label>
        <button type="submit" className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{busy ? "Saving…" : "Save assumptions"}</button>
      </fieldset>
      {message && <p role="status" className="text-sm">{message}</p>}
    </form>
  );
}
