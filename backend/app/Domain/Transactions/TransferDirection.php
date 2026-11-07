<?php

namespace App\Domain\Transactions;

enum TransferDirection: string
{
    case In = 'IN';
    case Out = 'OUT';
}
