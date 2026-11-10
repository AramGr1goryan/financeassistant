"use client";

import React, { useRef, useState } from "react";
import { createManualTransaction, SessionError, type AccountItemDto, type TransactionItemDto } from "../lib/api";

export function ManualTransactionForm({
  accounts,
  busy,
  onBusyChange,
  onSaved,
}: {
  accounts: AccountItemDto[];
  busy: boolean;
  onBusyChange: (busy: boolean) => void;
  onSaved: () => Promise<boolean>;
}) {
  const [accountId, setAccountId] = useState("");
  const [counterpartId, setCounterpartId] = useState("");
  const [amount, setAmount] = useState("");
  const [type, setType] = useState<TransactionItemDto["type"]>("EXPENSE");
  const [status, setStatus] = useState<"PENDING" | "POSTED">("POSTED");
  const [date, setDate] = useState("");
  const [description, setDescription] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const idempotencyKey = useRef<string | null>(null);
  const activeAccounts = accounts.filter((account) => account.isActive);
  const counterpartAccounts = activeAccounts.filter((account) => account.id !== accountId);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setNotice("");
    onBusyChange(true);
    idempotencyKey.current ??= window.crypto.randomUUID();

    try {
      await createManualTransaction({
        idempotency_key: idempotencyKey.current,
        account_id: accountId,
        amount_minor_units: amount,
        type,
        status,
        transaction_date: date,
        ...(description.trim() ? { description: description.trim() } : {}),
        ...(type === "TRANSFER" ? {
          transfer_direction: "OUT" as const,
          transfer_counterpart_account_id: counterpartId,
        } : {}),
      });
      if (!(await onSaved())) {
        setNotice("Transaction was saved, but updated data could not be loaded. Retry after the connection is restored.");
        return;
      }
      idempotencyKey.current = null;
      setAmount("");
      setDescription("");
      setNotice("Transaction saved.");
    } catch (failure) {
      setError(failure instanceof SessionError && failure.status === 401
        ? "Your session expired. Sign in again before recording a transaction."
        : failure instanceof SessionError && failure.status === 409
          ? "This transaction identifier conflicts with an existing ledger entry. Refresh the ledger before continuing."
          : failure instanceof SessionError && failure.status === 422
            ? "The transaction was rejected. Check the amount, account compatibility, date, and transfer details."
            : "Could not confirm whether the transaction was saved. Retry with the same form values or refresh the ledger.");
    } finally {
      onBusyChange(false);
    }
  }

  return (
    <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <div>
        <h2 className="text-base font-semibold">Add a manual transaction</h2>
        <p className="mt-1 text-sm text-slate-600">Amounts are exact integer AMD units. Pending entries remain separate from posted balances.</p>
      </div>
      {activeAccounts.length === 0 ? (
        <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Create an active account before recording transactions.</p>
      ) : (
        <>
          <fieldset disabled={busy} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <label className="block text-sm">Account
                <select required value={accountId} onChange={(event) => { setAccountId(event.target.value); setCounterpartId(""); }} className="mt-1 block w-full rounded border border-slate-300 p-2">
                  <option value="">Select an account</option>
                  {activeAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.type})</option>)}
                </select>
              </label>
              <label className="block text-sm">Type
                <select value={type} onChange={(event) => setType(event.target.value as TransactionItemDto["type"])} className="mt-1 block w-full rounded border border-slate-300 p-2">
                  {(["INCOME", "EXPENSE", "FEE", "REFUND", "TRANSFER", "ADJUSTMENT"] as const).map((value) => <option key={value} value={value}>{value}</option>)}
                </select>
              </label>
              <label className="block text-sm">Amount (integer AMD units)
                <input required inputMode="numeric" pattern={type === "ADJUSTMENT" ? "-?[0-9]+" : "[0-9]+"} type="text" value={amount} onChange={(event) => setAmount(event.target.value)} className="mt-1 block w-full rounded border border-slate-300 p-2" />
              </label>
              <label className="block text-sm">Financial date (Yerevan)
                <input required type="date" value={date} onChange={(event) => setDate(event.target.value)} className="mt-1 block w-full rounded border border-slate-300 p-2" />
              </label>
              <label className="block text-sm">Ledger status
                <select value={status} onChange={(event) => setStatus(event.target.value as "PENDING" | "POSTED")} className="mt-1 block w-full rounded border border-slate-300 p-2">
                  <option value="POSTED">Posted</option>
                  <option value="PENDING">Pending</option>
                </select>
              </label>
              {type === "TRANSFER" && (
                <label className="block text-sm">Transfer to
                  <select required value={counterpartId} onChange={(event) => setCounterpartId(event.target.value)} className="mt-1 block w-full rounded border border-slate-300 p-2">
                    <option value="">Select another account</option>
                    {counterpartAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} ({account.type})</option>)}
                  </select>
                </label>
              )}
            </div>
            <label className="block text-sm">Description
              <input maxLength={255} type="text" value={description} onChange={(event) => setDescription(event.target.value)} className="mt-1 block w-full rounded border border-slate-300 p-2" />
            </label>
            <button disabled={!accountId} type="submit" className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
              {busy ? "Saving…" : "Save transaction"}
            </button>
          </fieldset>
          {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
          {notice && <p role="status" className="text-sm text-emerald-800">{notice}</p>}
        </>
      )}
    </form>
  );
}
