import React from "react";
import {
  EmptyWorkspaceBanner,
  TransactionsSection,
} from "../../components/FinancialSections";
import { WorkspaceShell } from "../../components/WorkspaceShell";
import type { TransactionItemDto } from "../../lib/api";

export function TransactionsPageView({
  transactions = [],
}: {
  transactions?: TransactionItemDto[];
}) {
  return (
    <WorkspaceShell
      currentPath="/transactions"
      title="Transaction Ledger"
      subtitle="Canonical ledger records grouped by Asia/Yerevan financial days. Pending, reversed, and declined transactions are never merged into posted balances."
      statusLabel={
        transactions.length > 0 ? "Connected (/api/v1)" : "Setup in progress"
      }
    >
      {transactions.length === 0 && <EmptyWorkspaceBanner />}
      <TransactionsSection transactions={transactions} />
    </WorkspaceShell>
  );
}

export default function TransactionsPage() {
  return <TransactionsPageView />;
}

