import React from "react";
import {
  AccountsSection,
  EmptyWorkspaceBanner,
} from "../../components/FinancialSections";
import { WorkspaceShell } from "../../components/WorkspaceShell";
import type { AccountItemDto } from "../../lib/api";

export function AccountsPageView({
  accounts = [],
}: {
  accounts?: AccountItemDto[];
}) {
  return (
    <WorkspaceShell
      currentPath="/accounts"
      title="Accounts & Credit Liabilities"
      subtitle="Owned asset balances and credit account liabilities are tracked separately in integer minor units. Credit limits and cash accounts are never treated as spendable funds."
      statusLabel={accounts.length > 0 ? "Connected (/api/v1)" : "Setup in progress"}
    >
      {accounts.length === 0 && <EmptyWorkspaceBanner />}
      <AccountsSection accounts={accounts} />
    </WorkspaceShell>
  );
}

export default function AccountsPage() {
  return <AccountsPageView />;
}

