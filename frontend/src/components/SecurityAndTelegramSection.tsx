"use client";

import React, { useEffect, useState } from "react";
import {
  fetchTelegramOwnerStatus,
  linkTelegramOwner,
  registerTelegramWebhook,
  updateUserPassword,
  SessionError,
  type TelegramOwnerStatusDto,
} from "../lib/api";

export function SecurityAndTelegramSection({
  busy,
  onBusyChange,
}: {
  busy: boolean;
  onBusyChange: (busy: boolean) => void;
}) {
  const [telegramStatus, setTelegramStatus] = useState<TelegramOwnerStatusDto | null>(null);
  const [telegramNotice, setTelegramNotice] = useState("");
  const [telegramError, setTelegramError] = useState("");

  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [passwordNotice, setPasswordNotice] = useState("");
  const [passwordError, setPasswordError] = useState("");

  useEffect(() => {
    let ignore = false;
    void fetchTelegramOwnerStatus()
      .then((status) => {
        if (!ignore) {
          setTelegramStatus(status);
        }
      })
      .catch(() => {});
    return () => {
      ignore = true;
    };
  }, []);

  async function handleLinkTelegram() {
    onBusyChange(true);
    setTelegramNotice("");
    setTelegramError("");
    try {
      await linkTelegramOwner();
      setTelegramNotice("Telegram account linked successfully! You can now use the bot in Telegram.");
      const status = await fetchTelegramOwnerStatus();
      setTelegramStatus(status);
    } catch (err) {
      setTelegramError(err instanceof SessionError ? err.message : "Failed to link Telegram account.");
    } finally {
      onBusyChange(false);
    }
  }

  async function handleRegisterWebhook() {
    onBusyChange(true);
    setTelegramNotice("");
    setTelegramError("");
    try {
      await registerTelegramWebhook();
      setTelegramNotice("Telegram webhook registered successfully for Bot API (messages & widgets)!");
    } catch (err) {
      setTelegramError(err instanceof SessionError ? err.message : "Failed to register webhook.");
    } finally {
      onBusyChange(false);
    }
  }

  async function handlePasswordChange(e: React.FormEvent) {
    e.preventDefault();
    setPasswordNotice("");
    setPasswordError("");

    if (newPassword.length < 12) {
      setPasswordError("New password must be at least 12 characters.");
      return;
    }

    if (newPassword !== confirmation) {
      setPasswordError("New passwords do not match.");
      return;
    }

    onBusyChange(true);
    try {
      await updateUserPassword(currentPassword, newPassword, confirmation);
      setPasswordNotice("Password updated successfully. Please remember your new password.");
      setCurrentPassword("");
      setNewPassword("");
      setConfirmation("");
    } catch (err) {
      setPasswordError(
        err instanceof SessionError && err.status === 422
          ? "Current password was incorrect, or new password requirements were not met."
          : "Failed to update password. Please try again."
      );
    } finally {
      onBusyChange(false);
    }
  }

  return (
    <div className="grid gap-6 md:grid-cols-2">
      {/* Telegram Integration Card */}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 className="text-base font-semibold">Telegram Bot Integration</h2>
        <p className="mt-1 text-sm text-slate-600">
          Control your finances directly via Telegram chat and Bot API 10.4 widgets.
        </p>

        {telegramNotice && (
          <p className="mt-3 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{telegramNotice}</p>
        )}
        {telegramError && (
          <p className="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-800">{telegramError}</p>
        )}

        <div className="mt-4 space-y-3 text-sm">
          <div className="flex items-center justify-between border-b border-slate-100 pb-2">
            <span className="text-slate-600">Status:</span>
            <span className={`font-medium ${telegramStatus?.isLinked ? "text-emerald-700" : "text-amber-700"}`}>
              {telegramStatus?.isLinked ? "Linked & Active" : "Not Linked"}
            </span>
          </div>

          {telegramStatus?.isLinked && telegramStatus.telegramUserId && (
            <div className="flex items-center justify-between border-b border-slate-100 pb-2">
              <span className="text-slate-600">Telegram User ID:</span>
              <span className="font-mono text-xs">{telegramStatus.telegramUserId}</span>
            </div>
          )}

          <div className="flex flex-wrap gap-2 pt-2">
            {!telegramStatus?.isLinked && (
              <button
                type="button"
                disabled={busy}
                onClick={() => void handleLinkTelegram()}
                className="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
              >
                Link Configured Telegram ID
              </button>
            )}
            <button
              type="button"
              disabled={busy}
              onClick={() => void handleRegisterWebhook()}
              className="rounded-lg border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 disabled:opacity-50"
            >
              Register Webhook
            </button>
          </div>
        </div>

        <div className="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
          <p className="font-semibold text-slate-700">How to use in Telegram:</p>
          <ul className="mt-1 list-disc pl-4 space-y-0.5">
            <li><code>/balance</code> — Real-time balances</li>
            <li><code>/accounts</code> — Account list & liabilities</li>
            <li><code>/widget</code> — Interactive Bot API 10.4 buttons</li>
            <li><code>/expense &lt;account&gt; &lt;amount&gt; &lt;desc&gt;</code> — Quick expense</li>
            <li><code>/app</code> — Open this Mini App inside Telegram</li>
          </ul>
        </div>
      </section>

      {/* Password Change Card */}
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <h2 className="text-base font-semibold">Change Password</h2>
        <p className="mt-1 text-sm text-slate-600">
          Securely rotate your password directly from your browser.
        </p>

        {passwordNotice && (
          <p className="mt-3 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800">{passwordNotice}</p>
        )}
        {passwordError && (
          <p className="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-800">{passwordError}</p>
        )}

        <form onSubmit={handlePasswordChange} className="mt-4 space-y-3">
          <label className="block text-sm">
            Current Password
            <input
              type="password"
              required
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              className="mt-1 block w-full rounded border border-slate-300 p-2 text-sm"
              autoComplete="current-password"
            />
          </label>
          <label className="block text-sm">
            New Password (at least 12 characters)
            <input
              type="password"
              required
              minLength={12}
              maxLength={72}
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              className="mt-1 block w-full rounded border border-slate-300 p-2 text-sm"
              autoComplete="new-password"
            />
          </label>
          <label className="block text-sm">
            Confirm New Password
            <input
              type="password"
              required
              minLength={12}
              maxLength={72}
              value={confirmation}
              onChange={(e) => setConfirmation(e.target.value)}
              className="mt-1 block w-full rounded border border-slate-300 p-2 text-sm"
              autoComplete="new-password"
            />
          </label>
          <button
            type="submit"
            disabled={busy || !currentPassword || !newPassword || !confirmation}
            className="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
          >
            {busy ? "Updating…" : "Update Password"}
          </button>
        </form>
      </section>
    </div>
  );
}
