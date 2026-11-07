import React from "react";
import {
  AccountsSection,
  BudgetSection,
  EmptyWorkspaceBanner,
  FinancialBoundaryCards,
  IncomeExpectationsSection,
  TransactionsSection,
} from "../components/FinancialSections";
import { WorkspaceShell } from "../components/WorkspaceShell";
import { LiveDashboard } from "../components/LiveDashboard";
import type {
  DashboardWorkspacePayload,
  WorkspaceConnectionState,
} from "../lib/api";

export interface DashboardViewProps {
  connectionState?: WorkspaceConnectionState;
  payload?: DashboardWorkspacePayload | null;
}

export function DashboardView({
  connectionState = "empty",
  payload = null,
}: DashboardViewProps) {
  const statusLabel =
    connectionState === "ready" && payload
      ? "Connected (/api/v1)"
      : connectionState === "unauthenticated"
        ? "Sign-in required"
        : "Setup in progress";

  return (
    <WorkspaceShell
      currentPath="/"
      title="A clearer view of your finances starts here."
      subtitle="Your dashboard renders authoritative data from the versioned /api/v1 backend. Posted funds, pending outflows, credit liabilities, and manual income assumptions remain strictly distinct."
      statusLabel={statusLabel}
    >
      {!payload && <EmptyWorkspaceBanner connectionState={connectionState} />}
      <FinancialBoundaryCards payload={payload} />
      <AccountsSection accounts={payload?.accounts ?? []} />
      <BudgetSection budget={payload?.budget ?? null} />
      <IncomeExpectationsSection
        expectations={payload?.incomeExpectations ?? []}
      />
      <TransactionsSection transactions={payload?.recentTransactions ?? []} />
    </WorkspaceShell>
  );
}

export default function Home() {
  return <LiveDashboard />;
}
