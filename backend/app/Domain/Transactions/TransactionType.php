<?php

namespace App\Domain\Transactions;

enum TransactionType: string
{
    case Income = 'INCOME';
    case Expense = 'EXPENSE';
    case Transfer = 'TRANSFER';
    case Refund = 'REFUND';
    case Fee = 'FEE';
    case Adjustment = 'ADJUSTMENT';
}
