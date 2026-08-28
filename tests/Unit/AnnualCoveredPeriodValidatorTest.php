<?php

namespace Tests\Unit;

use App\Services\BIR\AnnualCoveredPeriodValidator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * One taxable year, and nothing else.
 *
 * The rule exists because the 1604E always carries the taxable year end,
 * 12/31/YYYY, whatever period was selected on screen -- so accepting a shorter
 * period would file a full-year return holding part of a year. The cases below are
 * the four the covered period can be wrong in: short at the end, short at the
 * start, spanning two years, or backwards.
 *
 * No framework, no database: the class reads nothing but the two dates.
 */
class AnnualCoveredPeriodValidatorTest extends TestCase
{
    private AnnualCoveredPeriodValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new AnnualCoveredPeriodValidator();
    }

    private function period(string $startDate, string $endDate): array
    {
        return [
            Carbon::parse($startDate)->startOfDay(),
            Carbon::parse($endDate)->endOfDay(),
        ];
    }

    public function test_january_first_to_december_thirty_first_is_one_full_taxable_year(): void
    {
        [$start, $end] = $this->period('2026-01-01', '2026-12-31');

        $this->assertTrue($this->validator->isFullTaxableYear($start, $end));
        $this->assertSame([], $this->validator->errors($start, $end));
    }

    /**
     * A leap year ends on 12/31 like every other year -- nothing here counts days.
     */
    public function test_a_leap_year_is_accepted(): void
    {
        [$start, $end] = $this->period('2024-01-01', '2024-12-31');

        $this->assertTrue($this->validator->isFullTaxableYear($start, $end));
    }

    public function test_a_period_ending_before_december_is_refused_on_the_end_date(): void
    {
        [$start, $end] = $this->period('2026-01-01', '2026-07-31');

        $this->assertFalse($this->validator->isFullTaxableYear($start, $end));
        $this->assertSame(
            ['end_date' => AnnualCoveredPeriodValidator::MESSAGE],
            $this->validator->errors($start, $end)
        );
    }

    public function test_a_period_starting_after_january_is_refused_on_the_start_date(): void
    {
        [$start, $end] = $this->period('2026-02-01', '2026-12-31');

        $this->assertFalse($this->validator->isFullTaxableYear($start, $end));
        $this->assertSame(
            ['start_date' => AnnualCoveredPeriodValidator::MESSAGE],
            $this->validator->errors($start, $end)
        );
    }

    /**
     * Twelve months, but not one taxable year: the 1604E is filed per year.
     */
    public function test_a_twelve_month_period_across_two_years_is_refused(): void
    {
        [$start, $end] = $this->period('2026-02-01', '2027-01-31');

        $this->assertFalse($this->validator->isFullTaxableYear($start, $end));
        $this->assertArrayHasKey('start_date', $this->validator->errors($start, $end));
        $this->assertArrayHasKey('end_date', $this->validator->errors($start, $end));
    }

    public function test_january_first_to_january_thirty_first_of_the_next_year_is_refused(): void
    {
        [$start, $end] = $this->period('2026-01-01', '2027-01-31');

        $this->assertFalse($this->validator->isFullTaxableYear($start, $end));
        $this->assertSame(
            ['end_date' => AnnualCoveredPeriodValidator::MESSAGE],
            $this->validator->errors($start, $end)
        );
    }

    /**
     * Both dates are the right month and day, so only the year check catches it.
     */
    public function test_two_whole_years_are_refused(): void
    {
        [$start, $end] = $this->period('2026-01-01', '2027-12-31');

        $this->assertFalse($this->validator->isFullTaxableYear($start, $end));
        $this->assertSame(
            ['end_date' => AnnualCoveredPeriodValidator::MESSAGE],
            $this->validator->errors($start, $end)
        );
    }

    public function test_a_backwards_period_is_refused(): void
    {
        [$start, $end] = $this->period('2026-12-31', '2026-01-01');

        $this->assertFalse($this->validator->isFullTaxableYear($start, $end));
        $this->assertNotSame([], $this->validator->errors($start, $end));
    }

    public function test_the_time_of_day_does_not_decide_the_answer(): void
    {
        $this->assertTrue($this->validator->isFullTaxableYear(
            Carbon::parse('2026-01-01 23:59:59'),
            Carbon::parse('2026-12-31 00:00:00')
        ));
    }
}
