<?php

namespace App\Services\BIR;

use Carbon\Carbon;

/**
 * Checks that an Annual Expanded WTAX covered period is exactly one taxable year.
 *
 * The AVS 1604E validator follows the Tax Year typed on its validation screen, and
 * the generated file always carries the taxable year end, 12/31/YYYY. So a covered
 * period of 01/01/2026 to 07/31/2026 would produce a file dated 12/31/2026 holding
 * seven months of payees -- a full-year return understated by five months, with
 * nothing in the file to say so. Rather than quietly widening the period, both the
 * upload and the download refuse it and say what the period has to be.
 *
 * One rule, one place: VatInputController rejects the upload before any stored row
 * is deleted, DatFileController rejects the download before the generator runs, and
 * resources/js/lib/annualCoveredPeriod.js repeats it on screen so the user is told
 * before submitting. This class stays the source of truth.
 */
class AnnualCoveredPeriodValidator
{
    public const MESSAGE = 'Annual Expanded WTAX must cover one full taxable year: '
        . 'January 1 to December 31 of the same year.';

    public function isFullTaxableYear(Carbon $startDate, Carbon $endDate): bool
    {
        return $startDate->year === $endDate->year
            && $this->isJanuaryFirst($startDate)
            && $this->isDecemberThirtyFirst($endDate);
    }

    /**
     * The same rule as validation errors, keyed by the form field that is wrong, so
     * the message lands under the date the user has to change.
     *
     * @return array<string, string> empty when the period is one full taxable year
     */
    public function errors(Carbon $startDate, Carbon $endDate): array
    {
        if ($this->isFullTaxableYear($startDate, $endDate)) {
            return [];
        }

        $errors = [];

        if (! $this->isJanuaryFirst($startDate)) {
            $errors['start_date'] = self::MESSAGE;
        }

        if (! $this->isDecemberThirtyFirst($endDate) || $startDate->year !== $endDate->year) {
            $errors['end_date'] = self::MESSAGE;
        }

        return $errors;
    }

    private function isJanuaryFirst(Carbon $date): bool
    {
        return $date->month === 1 && $date->day === 1;
    }

    private function isDecemberThirtyFirst(Carbon $date): bool
    {
        return $date->month === 12 && $date->day === 31;
    }
}
