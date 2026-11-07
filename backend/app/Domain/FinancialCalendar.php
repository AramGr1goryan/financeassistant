<?php

namespace App\Domain;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class FinancialCalendar
{
    private readonly DateTimeZone $displayTimezone;

    public function __construct(?string $displayTimezone = null)
    {
        $this->displayTimezone = new DateTimeZone(
            $displayTimezone ?? (string) config('finance.display_timezone', 'Asia/Yerevan'),
        );
    }

    public function financialDate(DateTimeInterface|string $timestamp): string
    {
        if (is_string($timestamp)) {
            $timestamp = $this->parseUtcTimestamp($timestamp);
        }

        return CarbonImmutable::instance($timestamp)
            ->setTimezone($this->displayTimezone)
            ->toDateString();
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function utcBoundsForFinancialDay(string $financialDate): array
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $financialDate) !== 1) {
            throw new InvalidArgumentException('Financial date must use YYYY-MM-DD format.');
        }

        $localStart = new DateTimeImmutable($financialDate.' 00:00:00', $this->displayTimezone);

        if ($localStart->format('Y-m-d') !== $financialDate) {
            throw new InvalidArgumentException('Financial date is not a valid calendar day.');
        }

        $localEnd = $localStart->modify('+1 day');

        return [
            'start' => CarbonImmutable::instance($localStart)->utc(),
            'end' => CarbonImmutable::instance($localEnd)->utc(),
        ];
    }

    private function parseUtcTimestamp(string $timestamp): DateTimeImmutable
    {
        if (preg_match(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D',
            $timestamp,
        ) !== 1) {
            throw new InvalidArgumentException('Timestamp must be ISO-8601 with an explicit timezone.');
        }

        $date = new DateTimeImmutable($timestamp);

        if ($date->format('Y-m-d\TH:i:s') !== substr($timestamp, 0, 19)) {
            throw new InvalidArgumentException('Timestamp components are not valid.');
        }

        return $date;
    }
}
