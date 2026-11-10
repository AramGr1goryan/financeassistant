import React from "react";
import {
  EmptyWorkspaceBanner,
  IncomeExpectationsSection,
} from "../../components/FinancialSections";
import { WorkspaceShell } from "../../components/WorkspaceShell";
import { LiveDashboard } from "../../components/LiveDashboard";
import type { IncomeExpectationDto } from "../../lib/api";

export function ExpectationsPageView({
  expectations = [],
}: {
  expectations?: IncomeExpectationDto[];
}) {
  return (
    <WorkspaceShell
      currentPath="/expectations"
      title="Manual Income Expectations"
      subtitle="Owner-scoped manual income assumptions in Asia/Yerevan calendar dates. Expectations and completion states never alter actual balances or available budget."
      statusLabel={
        expectations.length > 0 ? "Connected (/api/v1)" : "Setup in progress"
      }
    >
      {expectations.length === 0 && <EmptyWorkspaceBanner />}
      <IncomeExpectationsSection expectations={expectations} />
    </WorkspaceShell>
  );
}

export default function ExpectationsPage() {
  return <LiveDashboard currentPath="/expectations" />;
}
