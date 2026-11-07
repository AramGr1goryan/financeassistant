<?php

namespace App\Domain\Income;

enum IncomeExpectationStatus: string
{
    case Planned = 'PLANNED';
    case Completed = 'COMPLETED';
}
