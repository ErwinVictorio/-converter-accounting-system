<?php

namespace App\Services\BIR;

use DateTimeInterface;
use Throwable;

/**
 * Guards rows before they reach the RELIEF Importation (type "I") DAT.
 *
 * Deliberately has no TIN or address checks: unlike the Purchase schedule, the
 * importation detail line has neither field, because foreign suppliers have no
 * Philippine TIN.
 */
class BirImportationRowValidator
{
    private const AMOUNT_FIELDS = [
        'dutiable_value',
        'charges',
        'exempt',
        'taxable_goods',
        'vat_payable',
    ];

    public function validate(array $row, int $excelRow): array
    {
        $errors = [];

        foreach (['import_entry_no', 'supplier', 'country', 'or_number'] as $field) {
            if (trim((string) ($row[$field] ?? '')) === '') {
                $errors[] = "Row {$excelRow}: {$field} is required.";
            }
        }

        foreach (['supplier', 'country'] as $field) {
            $value = (string) ($row[$field] ?? '');

            if (str_contains($value, ',') || str_contains($value, '&')) {
                $errors[] = "Row {$excelRow}: {$field} cannot contain comma or ampersand.";
            }
        }

        foreach (['assessment_date', 'importation_date', 'payment_date'] as $field) {
            if (! $this->isDate($row[$field] ?? null)) {
                $errors[] = "Row {$excelRow}: {$field} must be a valid date.";
            }
        }

        foreach (self::AMOUNT_FIELDS as $field) {
            $value = $row[$field] ?? 0;

            if ($value === '' || ! is_numeric(preg_replace('/[^\d.-]/', '', (string) $value))) {
                $errors[] = "Row {$excelRow}: {$field} must be numeric. Use 0 when there is no amount.";
            }
        }

        $rate = $this->number($row['vat_rate'] ?? null);

        if ($rate <= 0) {
            $errors[] = "Row {$excelRow}: vat_rate must be numeric and greater than 0.";
        }

        // Only worth checking once every amount is known to be a number.
        if ($errors === []) {
            $errors = array_merge($errors, $this->consistencyErrors($row, $excelRow, $rate));
        }

        return $errors;
    }

    /**
     * Consistency checks that catch a typo before the return does.
     *
     * Both are strict. vat_payable holds on all three rows of the BIR sample
     * file, and taxable_goods is now confirmed by the entry screen, which derives
     * charges = total landed cost - dutiable value and taxable = total landed
     * cost - exempt; substituting gives dutiable + charges - exempt. (This check
     * used to accept "dutiable - exempt" as well, because every row in the sample
     * file and BIR's template had charges = 0, leaving the sign unconfirmable.)
     */
    private function consistencyErrors(array $row, int $excelRow, float $rate): array
    {
        $errors = [];

        $dutiable = $this->number($row['dutiable_value'] ?? null);
        $charges = $this->number($row['charges'] ?? null);
        $exempt = $this->number($row['exempt'] ?? null);
        $taxable = $this->number($row['taxable_goods'] ?? null);
        $vat = $this->number($row['vat_payable'] ?? null);

        $expectedTaxable = round($dutiable + $charges - $exempt, 2);

        if (abs($taxable - $expectedTaxable) > 0.01) {
            $errors[] = "Row {$excelRow}: taxable_goods ({$this->show($taxable)}) should equal "
                . "dutiable_value + charges - exempt ({$this->show($expectedTaxable)}).";
        }

        $expectedVat = round($taxable * $rate / 100, 2);

        if (abs($vat - $expectedVat) > 0.01) {
            $errors[] = "Row {$excelRow}: vat_payable ({$this->show($vat)}) should equal "
                . "taxable_goods x {$this->show($rate)}% ({$this->show($expectedVat)}).";
        }

        return $errors;
    }

    private function isDate(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        try {
            return strtotime($value) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function number(mixed $value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0.0;
        }

        return round((float) preg_replace('/[^\d.-]/', '', (string) $value), 2);
    }

    private function show(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
