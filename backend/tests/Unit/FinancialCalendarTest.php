<?php

namespace Tests\Unit;

use App\Domain\FinancialCalendar;
use PHPUnit\Framework\TestCase;

class FinancialCalendarTest extends TestCase
{
    private function calendar(): FinancialCalendar
    {
        return new FinancialCalendar('Asia/Yerevan');
    }

    public function test_yerevan_late_evening_timestamp_uses_the_local_financial_day(): void
    {
        $financialDate = $this->calendar()->financialDate('2026-03-10T23:55:00+04:00');

        $this->assertSame('2026-03-10', $financialDate);
    }

    public function test_yerevan_early_morning_timestamp_uses_the_next_local_financial_day(): void
    {
        $financialDate = $this->calendar()->financialDate('2026-03-11T00:05:00+04:00');

        $this->assertSame('2026-03-11', $financialDate);
    }

    public function test_local_day_bounds_convert_to_the_previous_utc_date_when_needed(): void
    {
        $bounds = $this->calendar()->utcBoundsForFinancialDay('2026-03-11');

        $this->assertSame('2026-03-10T20:00:00+00:00', $bounds['start']->toIso8601String());
        $this->assertSame('2026-03-11T20:00:00+00:00', $bounds['end']->toIso8601String());
    }

    public function test_timestamp_without_an_explicit_timezone_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->calendar()->financialDate('2026-03-11T00:05:00');
    }
}
