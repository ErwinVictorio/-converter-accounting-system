<?php

namespace App\Services\BIR;

/**
 * Checks one stored expanded withholding tax row before it reaches the 1601EQ/QAP
 * generator, so a bad row is reported on screen instead of silently producing a
 * DAT the BIR will bounce.
 *
 * Three rules the reference file rules out, and which this validator therefore
 * does NOT apply:
 *
 * - Negative amounts are legitimate. Row 19 of the reference file is a reversal
 *   at -51600.00 / -2580.00.
 * - middle_name is optional even for individuals. Three of the twelve
 *   individual payees in the reference file leave it blank.
 * - The payee branch code is not derived from the TIN. Every payee carries 0000,
 *   including those whose TIN has a non-zero branch suffix.
 *
 * Every check here only ever reports. The uploaded income payment, rate and tax
 * amount are never replaced with a computed figure -- including by the
 * tax_amount = ROUND(income x rate / 100, 2) check at the end, which names the
 * discrepancy and leaves both uploaded values standing. A failing row stays in the
 * table as uploaded and blocks the DAT until the workbook is corrected.
 */
class BirExpandedWtaxRowValidator
{
    private const NAME_FIELDS = ['company_name', 'last_name', 'first_name', 'middle_name'];

    /**
     * @return string[] one message per problem, empty when the row is filable
     */
    public function validate(array $row, int $excelRow): array
    {
        $errors = [];

        $tin = substr($this->digits($this->text($row, 'payee_tin')), 0, 9);

        if (! preg_match('/^\d{9}$/', $tin)) {
            $errors[] = "Row {$excelRow}: payee_tin must contain at least 9 digits.";
        } elseif ($tin === '000000000') {
            $errors[] = "Row {$excelRow}: payee_tin cannot be 000000000.";
        }

        if ($this->digits($this->text($row, 'payee_branch_code')) === '') {
            $errors[] = "Row {$excelRow}: payee_branch_code is required (use 0000 for head office).";
        }

        $type = strtolower(trim($this->text($row, 'payee_type')));

        if (! in_array($type, ['company', 'individual'], true)) {
            $errors[] = "Row {$excelRow}: payee_type must be company or individual.";
        }

        if ($type === 'company' && $this->text($row, 'company_name') === '') {
            $errors[] = "Row {$excelRow}: company_name is required for a company payee.";
        }

        if ($type === 'individual') {
            foreach (['last_name', 'first_name'] as $field) {
                if ($this->text($row, $field) === '') {
                    $errors[] = "Row {$excelRow}: {$field} is required for an individual payee.";
                }
            }
        }

        // The BIR template's two name sides are mutually exclusive -- a company
        // fills companyName and leaves surName/firstName/middleName blank, an
        // individual does the reverse. A row that fills both contradicts itself and
        // the DAT has no way to express it, so it is reported rather than guessed.
        $filledNameParts = array_values(array_filter(
            ['last_name', 'first_name', 'middle_name'],
            fn ($field) => $this->text($row, $field) !== ''
        ));

        if ($this->text($row, 'company_name') !== '' && $filledNameParts !== []) {
            $errors[] = "Row {$excelRow}: company_name is filled alongside "
                . implode(' and ', $filledNameParts)
                . '. Fill either the company name or the individual name columns, not both.';
        }

        foreach (self::NAME_FIELDS as $field) {
            $value = $this->text($row, $field);

            if (str_contains($value, ',') || str_contains($value, '&')) {
                $errors[] = "Row {$excelRow}: {$field} cannot contain a comma or ampersand.";
            }
        }

        $rate = $this->text($row, 'tax_rate');

        if (! is_numeric($rate)) {
            $errors[] = "Row {$excelRow}: tax_rate must be numeric.";
        } elseif ((float) $rate <= 0) {
            $errors[] = "Row {$excelRow}: tax_rate must be greater than 0.";
        }

        foreach (['income_payment', 'tax_withheld'] as $field) {
            if (! is_numeric($this->text($row, $field))) {
                $errors[] = "Row {$excelRow}: {$field} must be numeric.";
            }
        }

        $errors = array_merge($errors, $this->validateAtc($row, $excelRow, $type, $rate));

        // Only worth checking once both sides are known to be numbers.
        if (is_numeric($rate) && (float) $rate > 0
            && is_numeric($this->text($row, 'income_payment'))
            && is_numeric($this->text($row, 'tax_withheld'))
        ) {
            $expected = round((float) $this->text($row, 'income_payment') * (float) $rate / 100, 2);
            $withheld = round((float) $this->text($row, 'tax_withheld'), 2);

            if (round(abs($expected - $withheld), 2) > 0.01) {
                $errors[] = "Row {$excelRow}: tax_withheld {$withheld} does not match income_payment "
                    . "at {$rate}% (expected {$expected}).";
            }
        }

        return $errors;
    }

    /**
     * @return string[]
     */
    private function validateAtc(array $row, int $excelRow, string $type, string $rate): array
    {
        $allowed = (array) config('bir.expanded_wtax.allowed_atc_codes', []);
        $atc = strtoupper(trim($this->text($row, 'atc_code')));

        if ($atc === '') {
            $rateLabel = is_numeric($rate) ? number_format((float) $rate, 2) : $rate;
            $typeLabel = $type === '' ? 'this payee' : "a {$type} payee";

            // The ATC now comes from the workbook's own ATC column rather than from
            // a rate-to-code mapping, so a blank cell is a gap in the upload. It is
            // not filled in here: only the taxpayer knows which schedule a payment
            // belongs on, and 10% alone cannot choose between WC139 and WI516.
            return ["Row {$excelRow}: ATC is blank for {$typeLabel} at {$rateLabel}% withheld. "
                . 'Fill the ATC column in the workbook and upload the month again.'];
        }

        if (! array_key_exists($atc, $allowed)) {
            return ["Row {$excelRow}: ATC code {$atc} is not in bir.expanded_wtax.allowed_atc_codes."];
        }

        // Guards against a hand-edited row where the code and the rate disagree,
        // which would otherwise file the right amount under the wrong schedule.
        $expectedRate = $allowed[$atc]['rate'] ?? null;

        if ($expectedRate !== null && is_numeric($rate)
            && round((float) $expectedRate, 2) !== round((float) $rate, 2)
        ) {
            return ["Row {$excelRow}: ATC code {$atc} is filed at "
                . number_format((float) $expectedRate, 2) . '% but the row rate is '
                . number_format((float) $rate, 2) . '%.'];
        }

        // Only codes that are genuinely restricted carry a payee_type; WC158 and
        // WC160 serve both kinds of payee in the reference DAT and so carry none.
        $expectedType = $allowed[$atc]['payee_type'] ?? null;

        if ($expectedType !== null && $type !== '' && $expectedType !== $type) {
            return ["Row {$excelRow}: ATC code {$atc} is for a {$expectedType} payee "
                . "but this payee is {$type}."];
        }

        return [];
    }

    private function text(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
