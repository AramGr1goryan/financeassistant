<?php

namespace App\Domain\AiAssistant;

enum AiQueryIntent: string
{
    case BalanceSummary = 'BALANCE_SUMMARY';
    case BudgetSummary = 'BUDGET_SUMMARY';
    case IncomeExpectations = 'INCOME_EXPECTATIONS';
}

