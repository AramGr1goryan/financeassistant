<?php

namespace App\Domain\Accounts;

enum AccountType: string
{
    case Asset = 'ASSET';
    case Credit = 'CREDIT';
}
