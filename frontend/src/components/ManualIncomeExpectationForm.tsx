"use client";

import React, { useRef, useState } from "react";
import { createManualIncomeExpectation, SessionError, type ManualIncomeExpectationInput } from "../lib/api";

export function ManualIncomeExpectationForm({
  busy,
  onBusyChange,
  onSaved,
}: {
  busy: boolean;
  onBusyChange: (busy: boolean) => void;
  onSaved: () => Promise<boolean>;
}) {
  const [description, setDescription] = useState("");
  const [amount, setAmount] = useState("");
  const [date, setDate] = useState("");
  const [message, setMessage] = useState("");
  const idempotencyId = useRef<string | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    if (!/^[1-9]\d*$/.test(amount) || amount.length > 19 || BigInt(amount) > BigInt("9223372036854775807")) {
      setMessage("Enter a positive whole AMD amount within the supported range.");
      return;
    }

    onBusyChange(true);
    idempotencyId.current ??= window.crypto.randomUUID();
    const input: ManualIncomeExpectationInput = {
      id: idempotencyId.current,
      description: description.trim(),
      amount_minor_units: amount,
      expected_date: date,
    };
    try {
      await createManualIncomeExpectation(input);
      if (!(await onSaved())) {
        setMessage("Expectation was saved, but updated data could not be loaded. Retry after the connection is restored.");
        return;
      }
      idempotencyId.current = null;
      setDescription("");
      setAmount("");
      setMessage("Income assumption saved. It does not change your balance or budget.");
    } catch (failure) {
      setMessage(failure instanceof SessionError && failure.status === 401
        ? "Your session expired. Sign in again before recording an expectation."
        : failure instanceof SessionError && failure.status === 422
          ? "The expectation was rejected. Check its description, whole AMD amount, and Yerevan date."
          : failure instanceof SessionError && failure.status === 409
            ? "This expectation identifier conflicts with existing data. Refresh the list before continuing."
            : "Could not confirm whether the expectation was saved. Retry safely or refresh the page.");
    } finally {
      onBusyChange(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <div>
        <h2 className="text-base font-semibold">Add an expected income</h2>
        <p className="mt-1 text-sm text-slate-600">This is a manual assumption only; it is never counted as current money.</p>
      </div>
      <fieldset disabled={busy} className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-2">
          <label className="block text-sm">Description
            <input required maxLength={255} value={description} onChange={(event) => setDescription(event.target.value)} className="mt-1 block w-full rounded border border-slate-300 p-2" />
          </label>
          <label className="block text-sm">Amount (whole AMD)
            <input required inputMode="numeric" pattern="[0-9]+" value={amount} onChange={(event) => setAmount(event.target.value)} className="mt-1 block w-full rounded border border-slate-300 p-2" />
          </label>
          <label className="block text-sm">Expected date (Yerevan)
            <input required type="date" value={date} onChange={(event) => setDate(event.target.value)} className="mt-1 block w-full rounded border border-slate-300 p-2" />
          </label>
        </div>
        <button type="submit" className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
          {busy ? "Saving…" : "Save expectation"}
        </button>
      </fieldset>
      {message && <p role="status" className="text-sm text-slate-700">{message}</p>}
    </form>
  );
}
