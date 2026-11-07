import React from "react";
import {
  BudgetSection,
  EmptyWorkspaceBanner,
} from "../../components/FinancialSections";
import { WorkspaceShell } from "../../components/WorkspaceShell";
import { LiveDashboard } from "../../components/LiveDashboard";
import type { BudgetSummaryDto } from "../../lib/api";

export function BudgetPageView({
  budget = null,
}: {
  budget?: BudgetSummaryDto | null;
}) {
  return (
    <WorkspaceShell
      currentPath="/budget"
      title="Conservative Budget & Planning Horizon"
      subtitle="Deterministic budget calculation deducting pending outflows, mandatory payments, protected reserves, and allocated savings across a 30-day default horizon."
      statusLabel={budget ? "Connected (/api/v1)" : "Setup in progress"}
    >
      {!budget && <EmptyWorkspaceBanner />}
      <BudgetSection budget={budget} />
    </WorkspaceShell>
  );
}

export default function BudgetPage() {
  return <LiveDashboard currentPath="/budget" />;
}
