import Link from "next/link";
import React from "react";

export interface NavItem {
  href: string;
  label: string;
}

export const PRIMARY_NAV_ITEMS: readonly NavItem[] = [
  { href: "/", label: "Overview" },
  { href: "/accounts", label: "Accounts" },
  { href: "/transactions", label: "Transactions" },
  { href: "/budget", label: "Budget" },
  { href: "/expectations", label: "Income Expectations" },
];

export interface WorkspaceShellProps {
  currentPath: string;
  title: string;
  subtitle: string;
  statusLabel?: string;
  children: React.ReactNode;
}

export function WorkspaceShell({
  currentPath,
  title,
  subtitle,
  statusLabel = "Setup in progress",
  children,
}: WorkspaceShellProps) {
  return (
    <div className="min-h-screen bg-[#f7f8fa] text-slate-900">
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-slate-900 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
      >
        Skip to main content
      </a>

      <header className="border-b border-slate-200/80 bg-white">
        <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-8 sm:py-4">
          <div className="flex items-center justify-between gap-3">
            <Link
              className="flex items-center gap-3"
              href="/"
              aria-label="Finance home"
            >
              <span className="flex size-9 items-center justify-center rounded-xl bg-slate-900 text-sm font-semibold text-white">
                F
              </span>
              <span className="text-sm font-semibold tracking-tight">
                Finance Assistant
              </span>
            </Link>

            <span className="inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800 sm:hidden">
              {statusLabel}
            </span>
          </div>

          <nav
            aria-label="Primary navigation"
            className="flex flex-wrap items-center gap-1 sm:gap-2"
          >
            {PRIMARY_NAV_ITEMS.map((item) => {
              const isActive = item.href === currentPath;
              return (
                <Link
                  key={item.href}
                  href={item.href}
                  aria-current={isActive ? "page" : undefined}
                  className={
                    isActive
                      ? "rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white sm:text-sm"
                      : "rounded-lg px-3 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 sm:text-sm"
                  }
                >
                  {item.label}
                </Link>
              );
            })}
          </nav>

          <div className="hidden items-center gap-2 sm:flex">
            <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700">
              AMD · Asia/Yerevan
            </span>
            <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-800">
              {statusLabel}
            </span>
          </div>
        </div>
      </header>

      <main
        id="main-content"
        className="mx-auto max-w-7xl px-4 py-8 sm:px-8 sm:py-12"
      >
        <section className="max-w-3xl">
          <p className="text-xs font-semibold uppercase tracking-wider text-blue-700 sm:text-sm">
            Private finance workspace
          </p>
          <h1 className="mt-2 text-2xl font-semibold tracking-tight text-slate-950 sm:text-4xl">
            {title}
          </h1>
          <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base sm:leading-7">
            {subtitle}
          </p>
        </section>

        <div className="mt-8 space-y-8">{children}</div>

        <footer className="mt-12 border-t border-slate-200/80 pt-6 text-xs leading-5 text-slate-500">
          Amounts are displayed in AMD (whole drams, exponent 0). Timestamps are
          stored in UTC and presented in Asia/Yerevan. Cash tracking is
          disabled; credit limits and manual income expectations are never
          counted as available spending funds.
        </footer>
      </main>
    </div>
  );
}
