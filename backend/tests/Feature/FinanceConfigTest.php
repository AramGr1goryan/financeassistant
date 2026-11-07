<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinanceConfigTest extends TestCase
{
    public function test_finance_defaults_use_amd_and_yerevan_display_timezone_while_app_timezone_remains_utc(): void
    {
        $this->assertSame('AMD', config('finance.currency'));
        $this->assertSame('Asia/Yerevan', config('finance.display_timezone'));
        $this->assertSame('UTC', config('app.timezone'));
    }
}
