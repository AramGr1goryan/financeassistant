"use client";

import React, { useState } from "react";
import { createManualAccount, SessionError, type ManualAccountInput } from "../lib/api";

export function ManualAccountForm({ busy, onBusyChange, onSaved }: {
  busy: boolean;
  onBusyChange: (busy: boolean) => void;
  onSaved: () => Promise<boolean>;
}) {
  const [name, setName] = useState("");
  const [type, setType] = useState<ManualAccountInput["type"]>("ASSET");
  const [opening, setOpening] = useState("");
  const [message, setMessage] = useState("");
  const [pending, setPending] = useState<ManualAccountInput | null>(null);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    if (!/^(0|[1-9]\d*)$/.test(opening) || opening.length > 19 || BigInt(opening) > BigInt("9223372036854775807")) {
      setMessage("Enter a non-negative whole number of AMD within the supported range.");
      return;
    }

    const account = pending ?? { id: crypto.randomUUID(), name, type, opening_balance: opening };
    setPending(account);
    onBusyChange(true);
    try {
      await createManualAccount(account);
      setPending(null);
      setName("");
      setOpening("");
      if (await onSaved()) setMessage("Account created.");
    } catch (failure) {
      if (failure instanceof SessionError && (failure.status === 422 || failure.status === 409)) {
        setPending(null);
        setMessage("Account was not created. Check the name and amount, then try again.");
      } else {
        setMessage("Could not confirm account creation. Retry to safely repeat the same request; do not change the fields.");
      }
    } finally {
      onBusyChange(false);
    }
  }

  const locked = busy || pending !== null;
  return (
    <form onSubmit={submit} className="max-w-xl space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
      <h2 className="text-lg font-semibold">Add a manual account</h2>
      <p className="text-sm text-slate-600">This does not connect to a bank. Enter the opening owned balance or the amount currently owed, in whole AMD.</p>
      <label className="block text-sm">Account name
        <input required maxLength={255} disabled={locked} className="mt-1 block w-full rounded border border-slate-300 p-2" value={name} onChange={(event) => setName(event.target.value)} />
      </label>
      <label className="block text-sm">Account type
        <select disabled={locked} className="mt-1 block w-full rounded border border-slate-300 p-2" value={type} onChange={(event) => setType(event.target.value === "CREDIT" ? "CREDIT" : "ASSET")}>
          <option value="ASSET">Owned account</option>
          <option value="CREDIT">Credit liability</option>
        </select>
      </label>
      <label className="block text-sm">{type === "ASSET" ? "Opening owned balance (AMD)" : "Opening amount owed (AMD)"}
        <input required disabled={locked} inputMode="numeric" className="mt-1 block w-full rounded border border-slate-300 p-2" value={opening} onChange={(event) => setOpening(event.target.value)} />
      </label>
      <button disabled={busy} type="submit" className="rounded bg-slate-900 px-4 py-2 text-sm text-white disabled:opacity-50">{busy ? "Saving…" : pending ? "Retry request" : "Create account"}</button>
      {message && <p role="status" className="text-sm">{message}</p>}
    </form>
  );
}
