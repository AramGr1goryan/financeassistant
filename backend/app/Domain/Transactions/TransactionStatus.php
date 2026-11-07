<?php

namespace App\Domain\Transactions;

enum TransactionStatus: string
{
    case Pending = 'PENDING';
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';
    case Declined = 'DECLINED';
}
