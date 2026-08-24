<?php

namespace Tests\Unit;

use App\Services\BIR\BirExpandedWtaxRowValidator;
use Tests\TestCase;

/**
 * The validator is the only thing standing between a messy spreadsheet row and a
 * 1604E the BIR will bounce, so the cases below are drawn from the reference file
 * Docs/Expanded/0087919760000123120251604E.dat -- both the rows it proves are
 * legal (a blank middle name, a negative reversal) and the ones it proves are not.
 *
 * Extends Tests\TestCase rather than PHPUnit's own base class because the ATC
 * checks read config('bir.expanded_wtax'), which needs the framework booted. No
 * database is touched.
 */
class BirExpandedWtaxRowValidatorTest extends TestCase
{
    private BirExpandedWtaxRowValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new BirExpandedWtaxRowValidator();
    }

    /**
     * Detail row 1 of the reference file, which the generator reproduces verbatim.
     */
    private function companyRow(array $overrides = []): array
    {
        return array_merge([
            'payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'payee_type' => 'company',
            'payee_tin' => '007-086-184',
            'payee_branch_code' => '0000',
            'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'last_name' => null,
            'first_name' => null,
            'middle_name' => null,
            'atc_code' => 'WC158',
            'income_payment' => 3682716.00,
            'tax_rate' => 1.00,
            'tax_withheld' => 36827.16,
        ], $overrides);
    }

    /**
     * Detail row 7 of the reference file: an individual with no middle name.
     */
    private function individualRow(array $overrides = []): array
    {
        return array_merge([
            'payee_name' => 'BANSIL ANNIE',
            'payee_type' => 'individual',
            'payee_tin' => '220-052-738',
            'payee_branch_code' => '0000',
            'company_name' => null,
            'last_name' => 'BANSIL',
            'first_name' => 'ANNIE',
            'middle_name' => null,
            'atc_code' => 'WI516',
            'income_payment' => 5865.60,
            'tax_rate' => 10.00,
            'tax_withheld' => 586.56,
        ], $overrides);
    }

    private function assertHasError(array $errors, string $needle): void
    {
        $matches = array_filter($errors, fn ($error) => str_contains($error, $needle));

        $this->assertNotEmpty(
            $matches,
            "Expected an error containing \"{$needle}\". Got: " . json_encode($errors)
        );
    }

    public function test_a_company_row_from_the_reference_file_passes(): void
    {
        $this->assertSame([], $this->validator->validate($this->companyRow(), 4));
    }

    public function test_an_individual_row_passes_without_a_middle_name(): void
    {
        // Three of the twelve individual payees in the reference file have none,
        // so requiring it would block a file the BIR already accepted.
        $this->assertSame([], $this->validator->validate($this->individualRow(), 10));
    }

    public function test_a_negative_reversal_passes(): void
    {
        // Detail row 19 of the reference file.
        $errors = $this->validator->validate($this->companyRow([
            'company_name' => 'H M ALAPIDE GRAVEL AND SAND SUPPLIER',
            'payee_tin' => '302-331-355',
            'atc_code' => 'WC100',
            'income_payment' => -51600.00,
            'tax_rate' => 5.00,
            'tax_withheld' => -2580.00,
        ]), 22);

        $this->assertSame([], $errors);
    }

    public function test_it_rejects_a_tin_with_fewer_than_nine_digits(): void
    {
        $errors = $this->validator->validate($this->companyRow(['payee_tin' => '007-086']), 4);

        $this->assertHasError($errors, 'payee_tin must contain at least 9 digits');
    }

    public function test_it_rejects_a_zero_tin(): void
    {
        $errors = $this->validator->validate($this->companyRow(['payee_tin' => '000-000-000']), 4);

        $this->assertHasError($errors, 'payee_tin cannot be 000000000');
    }

    public function test_it_requires_a_branch_code(): void
    {
        $errors = $this->validator->validate($this->companyRow(['payee_branch_code' => '']), 4);

        $this->assertHasError($errors, 'payee_branch_code is required');
    }

    public function test_it_rejects_an_unknown_payee_type(): void
    {
        $errors = $this->validator->validate($this->companyRow(['payee_type' => 'partnership']), 4);

        $this->assertHasError($errors, 'payee_type must be company or individual');
    }

    public function test_it_requires_a_company_name_for_a_company_payee(): void
    {
        $errors = $this->validator->validate($this->companyRow(['company_name' => '']), 4);

        $this->assertHasError($errors, 'company_name is required for a company payee');
    }

    public function test_it_requires_last_and_first_name_for_an_individual_payee(): void
    {
        $errors = $this->validator->validate($this->individualRow([
            'last_name' => '',
            'first_name' => '',
        ]), 10);

        $this->assertHasError($errors, 'last_name is required for an individual payee');
        $this->assertHasError($errors, 'first_name is required for an individual payee');
    }

    public function test_it_rejects_commas_and_ampersands_in_name_fields(): void
    {
        // Both would break the comma-delimited, unescaped-quote DAT layout.
        $comma = $this->validator->validate($this->companyRow([
            'company_name' => 'ACERSTEEL, INC',
        ]), 4);

        $ampersand = $this->validator->validate($this->companyRow([
            'company_name' => 'ALAPIDE GRAVEL & SAND',
        ]), 4);

        $this->assertHasError($comma, 'company_name cannot contain a comma or ampersand');
        $this->assertHasError($ampersand, 'company_name cannot contain a comma or ampersand');
    }

    public function test_it_rejects_a_non_positive_or_non_numeric_rate(): void
    {
        $zero = $this->validator->validate($this->companyRow([
            'tax_rate' => 0,
            'tax_withheld' => 0,
        ]), 4);

        $text = $this->validator->validate($this->companyRow(['tax_rate' => 'one percent']), 4);

        $this->assertHasError($zero, 'tax_rate must be greater than 0');
        $this->assertHasError($text, 'tax_rate must be numeric');
    }

    public function test_it_rejects_non_numeric_amounts(): void
    {
        $errors = $this->validator->validate($this->companyRow([
            'income_payment' => '3,682,716.00',
            'tax_withheld' => 'n/a',
        ]), 4);

        $this->assertHasError($errors, 'income_payment must be numeric');
        $this->assertHasError($errors, 'tax_withheld must be numeric');
    }

    public function test_it_rejects_a_withheld_amount_that_does_not_match_the_rate(): void
    {
        $errors = $this->validator->validate($this->companyRow([
            'tax_withheld' => 36000.00,
        ]), 4);

        $this->assertHasError($errors, 'does not match income_payment');
    }

    public function test_it_tolerates_a_one_centavo_rounding_difference(): void
    {
        // Rate columns are keyed by hand, so a centavo of drift is normal and
        // must not stop a month's filing.
        $errors = $this->validator->validate($this->companyRow([
            'tax_withheld' => 36827.17,
        ]), 4);

        $this->assertSame([], $errors);
    }

    public function test_a_missing_atc_names_the_rate_the_payee_type_and_where_to_fix_it(): void
    {
        // The upload stores an unmappable row rather than failing, so this message
        // is the only place the user learns what to configure.
        $errors = $this->validator->validate($this->companyRow([
            'atc_code' => null,
            'tax_rate' => 15.00,
            'tax_withheld' => 552407.40,
        ]), 4);

        $this->assertHasError($errors, 'no ATC code could be resolved for 15.00% withheld from a company payee');
        $this->assertHasError($errors, 'config bir.expanded_wtax');
    }

    public function test_it_rejects_an_atc_code_that_is_not_configured(): void
    {
        $errors = $this->validator->validate($this->companyRow(['atc_code' => 'WC999']), 4);

        $this->assertHasError($errors, 'ATC code WC999 is not in bir.expanded_wtax.allowed_atc_codes');
    }

    public function test_it_rejects_an_atc_code_whose_rate_disagrees_with_the_row(): void
    {
        // WC158 is a 1% code; filing 10% under it would report the right money on
        // the wrong schedule, which no other check would catch.
        $errors = $this->validator->validate($this->companyRow([
            'atc_code' => 'WC158',
            'tax_rate' => 10.00,
            'tax_withheld' => 368271.60,
        ]), 4);

        $this->assertHasError($errors, 'ATC code WC158 is filed at 1.00% but the row rate is 10.00%');
    }

    public function test_it_rejects_an_individual_code_used_for_a_company_payee(): void
    {
        // WI516 is the individual side of the 10% pair. Filing a company under it
        // is the mirror of the rate mismatch: right money, wrong schedule.
        $errors = $this->validator->validate($this->companyRow([
            'atc_code' => 'WI516',
            'tax_rate' => 10.00,
            'tax_withheld' => 368271.60,
        ]), 4);

        $this->assertHasError($errors, 'ATC code WI516 is for a individual payee but this payee is company');
    }

    public function test_it_rejects_a_company_code_used_for_an_individual_payee(): void
    {
        $errors = $this->validator->validate($this->individualRow([
            'atc_code' => 'WC139',
        ]), 10);

        $this->assertHasError($errors, 'ATC code WC139 is for a company payee but this payee is individual');
    }

    public function test_the_1_and_2_percent_codes_serve_both_payee_types(): void
    {
        // The reference DAT files three individual payees under WC158 and two under
        // WC160, so those codes must not be restricted to companies.
        $this->assertSame([], $this->validator->validate($this->individualRow([
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 5865.60,
            'tax_withheld' => 58.66,
        ]), 10));

        $this->assertSame([], $this->validator->validate($this->individualRow([
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
            'income_payment' => 5865.60,
            'tax_withheld' => 117.31,
        ]), 10));
    }

    public function test_every_configured_default_code_is_also_an_allowed_code(): void
    {
        // The importer resolves from default_rate_codes and the validator checks
        // against allowed_atc_codes; a code in one list and not the other would
        // make every row of that rate unfilable.
        $allowed = config('bir.expanded_wtax.allowed_atc_codes');

        foreach (config('bir.expanded_wtax.default_rate_codes') as $rate => $mapping) {
            foreach ((array) $mapping as $type => $code) {
                $this->assertArrayHasKey(
                    $code,
                    $allowed,
                    "Default code {$code} for {$rate}% ({$type}) is missing from allowed_atc_codes."
                );
                $this->assertEqualsWithDelta(
                    (float) $rate,
                    (float) $allowed[$code]['rate'],
                    0.001,
                    "Default code {$code} is mapped to {$rate}% but allowed_atc_codes files it elsewhere."
                );

                // A default that its own payee_type restriction would reject would
                // make that rate unfilable for that kind of payee.
                if (isset($allowed[$code]['payee_type'])) {
                    $this->assertSame(
                        $type,
                        $allowed[$code]['payee_type'],
                        "Default code {$code} is used for {$type} payees but is restricted to "
                            . "{$allowed[$code]['payee_type']} payees."
                    );
                }
            }
        }
    }
}
