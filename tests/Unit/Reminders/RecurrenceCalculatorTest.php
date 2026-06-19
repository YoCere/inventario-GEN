<?php

namespace Tests\Unit\Reminders;

use App\Services\Reminders\RecurrenceCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class RecurrenceCalculatorTest extends TestCase
{
    private RecurrenceCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new RecurrenceCalculator();
    }

    public function test_non_recurring_returns_null(): void
    {
        $result = $this->calc->next(Carbon::parse('2026-06-19 15:00', 'UTC'), 'none', null, 'UTC');
        $this->assertNull($result);
    }

    public function test_daily_preserves_time_of_day(): void
    {
        $result = $this->calc->next(Carbon::parse('2026-06-19 15:00', 'UTC'), 'daily', null, 'UTC');
        $this->assertNotNull($result);
        $this->assertSame('2026-06-20 15:00:00', $result->toDateTimeString());
    }

    public function test_weekly_to_next_selected_weekday(): void
    {
        // 2026-06-19 is a Friday (isoWeekday 5); next Monday (isoWeekday 1) is 2026-06-22
        $result = $this->calc->next(
            Carbon::parse('2026-06-19 09:00', 'UTC'),
            'weekly',
            ['days' => [1]],
            'UTC'
        );
        $this->assertNotNull($result);
        $this->assertSame('2026-06-22', $result->toDateString());
        $this->assertSame('09:00:00', $result->toTimeString());
    }

    public function test_monthly_clamps_to_end_of_short_month(): void
    {
        // Jan 31 + 1 month with day=31 should clamp to Feb 28 (2026 is not a leap year)
        $result = $this->calc->next(
            Carbon::parse('2026-01-31 08:00', 'UTC'),
            'monthly',
            ['day' => 31],
            'UTC'
        );
        $this->assertNotNull($result);
        $this->assertSame('2026-02-28', $result->toDateString());
    }

    public function test_wall_clock_preserved_across_non_utc_tz(): void
    {
        // America/La_Paz is UTC-4 all year (no DST).
        // 2026-06-19 19:00 UTC = 2026-06-19 15:00 La_Paz local.
        // Next daily should be 2026-06-20 15:00 La_Paz = 2026-06-20 19:00 UTC.
        $result = $this->calc->next(
            Carbon::parse('2026-06-19 19:00', 'UTC'),
            'daily',
            null,
            'America/La_Paz'
        );
        $this->assertNotNull($result);
        $this->assertSame(
            '2026-06-20 19:00:00',
            $result->setTimezone('UTC')->toDateTimeString()
        );
    }
}
