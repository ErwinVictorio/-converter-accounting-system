<?php

namespace Tests\Feature;

use App\Models\ExpandedWtaxEntry;
use App\Models\User;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Covers the upload half of the Expanded WTAX module: POST /vat-import with
 * record_type=expanded reading the BIR 1601EQ Schedule 1 layout -- eleven columns,
 * headings on row 1, described in Docs/Expanded/BIR_Excel_Guide_Analysis.md.
 *
 * The rule the whole module turns on is that the workbook's amounts are already
 * computed and are stored as they stand. Nothing here derives an income payment
 * from a tax amount, a tax amount from an income payment, or an ATC from a rate,
 * and several cases below exist only to keep it that way.
 *
 * Most cases use a CSV, which the route accepts and the importer reads through the
 * same heading-row pipeline. Two run the real
 * Docs/Expanded/EXPANDED_WTAX_BIR_FORMAT_SAMPLE.xlsx end to end, because a CSV
 * cannot carry the formula that column K holds in a real workbook.
 */
class ExpandedWtaxImportTest extends TestCase
{
    use RefreshDatabase;

    private const WORKBOOK = 'Docs/Expanded/EXPANDED_WTAX_BIR_FORMAT_SAMPLE.xlsx';

    private const SYSTEM_WORKBOOK = 'Docs/Expanded/EXPANDED WTAX.xlsx';

    /** The BIR template's own headings, in the template's own order. */
    private const HEADINGS = 'Reporting_Month,Vendor_TIN,branchCode,companyName,surName,'
        . 'firstName,middleName,ATC,income_payment,ewt_rate,tax_amount';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * A workbook as a CSV: headings on row 1, no title rows above them.
     */
    private function csv(array $rows, ?string $headings = null): UploadedFile
    {
        $lines = [$headings ?? self::HEADINGS];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent(
            'expanded-wtax.csv',
            implode("\r\n", $lines) . "\r\n"
        );
    }

    private function systemCsv(array $rows): UploadedFile
    {
        $lines = ['No,Date,Supplier Name,TIN,Reference,(1%),(2%),(5%),(10%),(15%),Total'];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent(
            'expanded-wtax-system.csv',
            implode("\r\n", $lines) . "\r\n"
        );
    }

    /**
     * One company row, with only the cells a case cares about overridden.
     *
     * @param  array<int, string>  $overrides  column index (0-based) => value
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            '07/03/2026',                       // Reporting_Month
            '007086184',                        // Vendor_TIN
            '0',                                // branchCode
            'ACERSTEEL INDUSTRIAL SALES INC',   // companyName
            '',                                 // surName
            '',                                 // firstName
            '',                                 // middleName
            'WC158',                            // ATC
            '3682716.00',                       // income_payment
            '1',                                // ewt_rate
            '36827.16',                         // tax_amount
        ], $overrides);
    }

    private function storedExpanded(array $overrides = []): ExpandedWtaxEntry
    {
        return ExpandedWtaxEntry::create(array_merge([
            'reporting_period' => '2026-07-31',
            'report_type' => 'quarterly',
            'withholding_agent_tin' => '008791976',
            'withholding_agent_branch_code' => '0000',
            'withholding_agent_name' => 'FORTRESS STEEL INC.',
            'payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'payee_type' => 'company',
            'payee_tin' => '007086184',
            'payee_branch_code' => '0000',
            'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'last_name' => null,
            'first_name' => null,
            'middle_name' => null,
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 100000.00,
            'tax_withheld' => 1000.00,
        ], $overrides));
    }

    private function upload(UploadedFile $file, string $month = '2026-07'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/vat-import', [
            'excel_file' => $file,
            'reporting_month' => $month,
            'record_type' => 'expanded',
            'withholding_agent_tin' => '008791976',
            'withholding_agent_branch_code' => '0000',
        ]);
    }

    private function uploadForAgent(
        UploadedFile $file,
        string $month,
        string $tin,
        string $branch = '0000'
    ): \Illuminate\Testing\TestResponse {
        return $this->post('/vat-import', [
            'excel_file' => $file,
            'reporting_month' => $month,
            'record_type' => 'expanded',
            'withholding_agent_tin' => $tin,
            'withholding_agent_branch_code' => $branch,
        ]);
    }

    private function uploadAnnual(
        UploadedFile $file,
        string $startDate = '2026-01-01',
        string $endDate = '2026-12-31'
    ): \Illuminate\Testing\TestResponse {
        return $this->post('/vat-import', [
            'excel_file' => $file,
            'record_type' => 'expanded',
            'report_type' => 'annual',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'withholding_agent_tin' => '008791976',
            'withholding_agent_branch_code' => '0000',
        ]);
    }

    private function workbook(): UploadedFile
    {
        $path = base_path(self::WORKBOOK);

        if (! is_file($path)) {
            $this->markTestSkipped(self::WORKBOOK . ' is not present in this checkout.');
        }

        return new UploadedFile($path, 'EXPANDED_WTAX_BIR_FORMAT_SAMPLE.xlsx', null, null, true);
    }

    private function systemWorkbook(): UploadedFile
    {
        $path = base_path(self::SYSTEM_WORKBOOK);

        if (! is_file($path)) {
            $this->markTestSkipped(self::SYSTEM_WORKBOOK . ' is not present in this checkout.');
        }

        return new UploadedFile($path, 'EXPANDED WTAX.xlsx', null, null, true);
    }

    public function test_one_worksheet_row_becomes_one_stored_row_with_the_uploaded_amounts(): void
    {
        $response = $this->upload($this->csv([
            $this->row(),
            $this->row([7 => 'WC160', 8 => '100000.00', 9 => '2', 10 => '2000.00']),
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $entries = ExpandedWtaxEntry::orderBy('tax_rate')->get();

        $this->assertCount(2, $entries);

        // All three amounts exactly as the file supplied them. The 1% row in
        // particular: a derived income payment would read 3682716.00 only by
        // coincidence, and 36827.16 / 1% is what the old importer used to compute.
        $this->assertEqualsWithDelta(1.00, (float) $entries[0]->tax_rate, 0.001);
        $this->assertEqualsWithDelta(3682716.00, (float) $entries[0]->income_payment, 0.001);
        $this->assertEqualsWithDelta(36827.16, (float) $entries[0]->tax_withheld, 0.001);
        $this->assertSame('WC158', $entries[0]->atc_code);

        $this->assertEqualsWithDelta(2.00, (float) $entries[1]->tax_rate, 0.001);
        $this->assertEqualsWithDelta(100000.00, (float) $entries[1]->income_payment, 0.001);
        $this->assertEqualsWithDelta(2000.00, (float) $entries[1]->tax_withheld, 0.001);
        $this->assertSame('WC160', $entries[1]->atc_code);

        // The reporting period comes from the form, month-end, for every row.
        foreach ($entries as $entry) {
            $this->assertSame('2026-07-31', $entry->reporting_period->toDateString());
        }
    }

    public function test_an_income_payment_that_contradicts_the_tax_is_stored_as_uploaded(): void
    {
        // 1% of 100000.00 is 1000.00, not 4000.00. The row is wrong and the
        // validator says so -- but neither amount is quietly replaced, because the
        // workbook is the record of what was withheld and paid.
        $this->upload($this->csv([
            $this->row([8 => '100000.00', 9 => '1', 10 => '4000.00']),
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertEqualsWithDelta(100000.00, (float) $entry->income_payment, 0.001);
        $this->assertEqualsWithDelta(4000.00, (float) $entry->tax_withheld, 0.001);
        $this->assertEqualsWithDelta(1.00, (float) $entry->tax_rate, 0.001);

        $errors = app(BirExpandedWtaxRowValidator::class)->validate($entry->toBirExpandedRow(), 2);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not match income_payment', $errors[0]);
    }

    public function test_a_company_row_fills_the_company_name_and_leaves_the_name_parts_empty(): void
    {
        $this->upload($this->csv([$this->row()]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertSame('company', $entry->payee_type);
        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', $entry->company_name);
        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', $entry->payee_name);
        $this->assertNull($entry->last_name);
        $this->assertNull($entry->first_name);
        $this->assertNull($entry->middle_name);
        // Nine digits: the branch suffix is branchCode's own column.
        $this->assertSame('007086184', $entry->payee_tin);
    }

    public function test_an_individual_row_fills_the_three_name_columns(): void
    {
        $this->upload($this->csv([
            $this->row([
                1 => '220052738', 3 => '', 4 => 'BANSIL', 5 => 'JUAN', 6 => 'CRUZ',
                7 => 'WI010', 8 => '50000.00', 9 => '5', 10 => '2500.00',
            ]),
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertSame('individual', $entry->payee_type);
        $this->assertNull($entry->company_name);
        $this->assertSame('BANSIL', $entry->last_name);
        $this->assertSame('JUAN', $entry->first_name);
        $this->assertSame('CRUZ', $entry->middle_name);
        // A display label and a sort key that files the payee under their surname.
        // The comma is safe: payee_name is never written to the DAT.
        $this->assertSame('BANSIL, JUAN CRUZ', $entry->payee_name);
        $this->assertSame('WI010', $entry->atc_code);
    }

    public function test_the_atc_comes_from_the_column_rather_than_the_rate(): void
    {
        // 5% is WC100 for a company and WI010 for an individual, and the old
        // importer chose between them. Here the file says WC100 for one row and
        // WI010 for another at the same rate, and both are stored as written.
        $this->upload($this->csv([
            $this->row([1 => '302331355', 7 => 'wc100 ', 9 => '5', 8 => '51600.00', 10 => '2580.00']),
            $this->row([
                1 => '188291434', 3 => '', 4 => 'SY', 5 => 'JULIET', 6 => 'HUI',
                7 => 'WI010', 8 => '8000.00', 9 => '5', 10 => '400.00',
            ]),
        ]))->assertSessionHas('success');

        // Upper-cased and trimmed -- an identifier's format, not its value.
        $this->assertSame('WC100', ExpandedWtaxEntry::where('payee_tin', '302331355')->firstOrFail()->atc_code);
        $this->assertSame('WI010', ExpandedWtaxEntry::where('payee_tin', '188291434')->firstOrFail()->atc_code);
    }

    public function test_a_blank_atc_is_stored_null_and_blocks_the_dat(): void
    {
        // Storing the row keeps the money visible; inventing a code would file the
        // payment on a schedule nobody chose.
        $this->upload($this->csv([$this->row([7 => ''])]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertNull($entry->atc_code);

        $errors = app(BirExpandedWtaxRowValidator::class)->validate($entry->toBirExpandedRow(), 2);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('ATC is blank', $errors[0]);
    }

    public function test_the_branch_code_is_padded_to_four_digits(): void
    {
        $this->upload($this->csv([
            $this->row([2 => '1']),
            $this->row([1 => '004703296', 2 => '', 3 => 'OTHER COMPANY INC']),
        ]))->assertSessionHas('success');

        // The template writes a plain number; the DAT carries four digits.
        $this->assertSame('0001', ExpandedWtaxEntry::where('payee_tin', '007086184')->firstOrFail()->payee_branch_code);
        // Blank means head office, which the reference DAT files as 0000.
        $this->assertSame('0000', ExpandedWtaxEntry::where('payee_tin', '004703296')->firstOrFail()->payee_branch_code);
    }

    public function test_it_keeps_a_negative_reversal_on_both_amounts(): void
    {
        $this->upload($this->csv([
            $this->row([7 => 'WC160', 8 => '-51600.00', 9 => '2', 10 => '-1032.00']),
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertEqualsWithDelta(-51600.00, (float) $entry->income_payment, 0.001);
        $this->assertEqualsWithDelta(-1032.00, (float) $entry->tax_withheld, 0.001);

        // A reversal is a legitimate row, not an error.
        $this->assertSame([], app(BirExpandedWtaxRowValidator::class)->validate($entry->toBirExpandedRow(), 2));
    }

    public function test_it_normalises_names_to_bir_safe_text_and_truncates_at_fifty(): void
    {
        $this->upload($this->csv([
            $this->row([3 => 'h.m. alapide gravel & sand supplier incorporated and subsidiaries']),
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        // Upper-cased, "&" spelled out, periods dropped, cut to the DAT's 50
        // characters. The DAT is comma-delimited and its longest reference name is
        // exactly 50 characters wide.
        $this->assertSame('H M ALAPIDE GRAVEL AND SAND SUPPLIER INCORPORATED', $entry->company_name);
        $this->assertLessThanOrEqual(50, strlen($entry->company_name));
        $this->assertStringNotContainsString('&', $entry->company_name);
        $this->assertStringNotContainsString('.', $entry->company_name);
    }

    public function test_re_uploading_a_month_replaces_it_rather_than_doubling_the_tax(): void
    {
        $file = fn (string $income, string $withheld, string $date = '07/03/2026') => $this->csv([
            $this->row([0 => $date, 8 => $income, 10 => $withheld]),
        ]);

        $this->upload($file('3682716.00', '36827.16'))->assertSessionHas('success');
        $this->upload($file('4000000.00', '40000.00'))->assertSessionHas('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertEqualsWithDelta(40000.00, (float) ExpandedWtaxEntry::firstOrFail()->tax_withheld, 0.001);

        // A different month is untouched by the replace. Its own Reporting_Month has
        // to match, which is the pre-flight's business and is covered on its own below.
        $this->upload($file('500000.00', '5000.00', '08/04/2026'), '2026-08')->assertSessionHas('success');

        $this->assertSame(2, ExpandedWtaxEntry::count());
    }

    public function test_re_uploading_a_month_replaces_only_the_selected_withholding_agent(): void
    {
        $file = fn (string $income, string $withheld) => $this->csv([
            $this->row([8 => $income, 10 => $withheld]),
        ]);

        $this->uploadForAgent($file('100000.00', '1000.00'), '2026-07', '008791976')
            ->assertSessionHas('success');
        $this->uploadForAgent($file('200000.00', '2000.00'), '2026-07', '123456789')
            ->assertSessionHas('success');
        $this->uploadForAgent($file('300000.00', '3000.00'), '2026-07', '008791976')
            ->assertSessionHas('success');

        $this->assertSame(2, ExpandedWtaxEntry::count());
        $this->assertEqualsWithDelta(
            3000.00,
            (float) ExpandedWtaxEntry::where('withholding_agent_tin', '008791976')->firstOrFail()->tax_withheld,
            0.001
        );
        $this->assertEqualsWithDelta(
            2000.00,
            (float) ExpandedWtaxEntry::where('withholding_agent_tin', '123456789')->firstOrFail()->tax_withheld,
            0.001
        );
    }

    public function test_a_missing_column_rejects_the_upload_and_leaves_the_month_alone(): void
    {
        $this->upload($this->csv([$this->row()]))->assertSessionHas('success');

        // income_payment dropped, which is the column whose absence would otherwise
        // store a zero for every payee and look like a successful import.
        $headings = str_replace(',income_payment', '', self::HEADINGS);
        $short = fn (array $row) => array_values(array_diff_key($row, [8 => null]));

        $response = $this->upload($this->csv([$short($this->row())], $headings));

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $error = session('error');

        $this->assertStringContainsString('missing the column income_payment', $error);
        // The message names the layout, so the fix does not need a second question.
        $this->assertStringContainsString('BIR 1601EQ Schedule 1', $error);

        // The month already on file survived the rejected upload intact.
        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertEqualsWithDelta(3682716.00, (float) ExpandedWtaxEntry::firstOrFail()->income_payment, 0.001);
    }

    public function test_a_row_from_another_month_rejects_the_upload_and_names_the_row(): void
    {
        $response = $this->upload($this->csv([
            $this->row(),
            $this->row([0 => '11/28/2026', 1 => '004703296']),
        ]));

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $error = session('error');

        // Worksheet row 3: headings on row 1, so the second data row.
        $this->assertStringContainsString('Row 3: Reporting_Month is 11/28/2026', $error);
        $this->assertStringContainsString('but this upload is for July 2026', $error);

        // Nothing was stored: the whole file is one month or it is not imported.
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_same_company_name_with_different_tins_rejects_the_bir_upload_before_replacing_rows(): void
    {
        $this->upload($this->csv([$this->row()]))->assertSessionHas('success');

        $response = $this->upload($this->csv([
            $this->row(),
            $this->row([1 => '005425287']),
        ]));

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $error = session('error');

        $this->assertStringContainsString('ACERSTEEL INDUSTRIAL SALES INC has multiple TINs', $error);
        $this->assertStringContainsString('007086184, 005425287', $error);
        $this->assertStringContainsString('A company name must use one unique TIN', $error);

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame('007086184', ExpandedWtaxEntry::firstOrFail()->payee_tin);
    }

    public function test_same_company_name_with_different_tins_rejects_the_system_export_upload(): void
    {
        $response = $this->upload($this->systemCsv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007086184', 'A-1', '10224.13', '', '', '', '', '10224.13'],
            ['2', '07/04/2026', 'ACERSTEEL INDUSTRIAL SALES INC.', '005425287', 'A-2', '500.00', '', '', '', '', '500.00'],
        ]));

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $error = session('error');

        $this->assertStringContainsString('ACERSTEEL INDUSTRIAL SALES INC has multiple TINs', $error);
        $this->assertStringContainsString('007086184, 005425287', $error);
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_expanded_upload_defaults_to_quarterly_when_report_type_is_missing(): void
    {
        $this->upload($this->csv([$this->row()]))->assertSessionHas('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame('2026-07-31', ExpandedWtaxEntry::firstOrFail()->reporting_period->toDateString());
        $this->assertSame('quarterly', ExpandedWtaxEntry::firstOrFail()->report_type);
    }

    public function test_annual_upload_requires_covered_dates(): void
    {
        $response = $this->post('/vat-import', [
            'excel_file' => $this->csv([$this->row()]),
            'record_type' => 'expanded',
            'report_type' => 'annual',
            'withholding_agent_tin' => '008791976',
            'withholding_agent_branch_code' => '0000',
        ]);

        $response->assertSessionHasErrors(['start_date', 'end_date']);
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_annual_upload_rejects_an_end_date_before_the_start_date(): void
    {
        $this->uploadAnnual($this->csv([$this->row()]), '2026-12-31', '2026-01-01')
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_annual_upload_rejects_rows_outside_the_selected_date_range(): void
    {
        $response = $this->uploadAnnual($this->csv([
            $this->row([0 => '07/03/2026']),
            $this->row([0 => '11/28/2027', 1 => '004703296']),
        ]), '2026-01-01', '2026-12-31');

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $error = session('error');

        $this->assertStringContainsString('Row 3: Reporting_Month is 11/28/2027', $error);
        $this->assertStringContainsString('but this annual upload is for 01/01/2026 to 12/31/2026', $error);
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    /**
     * The taxable year cases. A 1604E is dated 12/31 of its taxable year whatever
     * covered period was selected, so anything short of one whole year is refused
     * here rather than widened into a full-year filing that is missing months.
     */
    public function test_annual_upload_accepts_one_full_taxable_year(): void
    {
        $this->uploadAnnual($this->csv([$this->row()]), '2026-01-01', '2026-12-31')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
    }

    public function test_annual_upload_rejects_a_covered_period_that_ends_before_december(): void
    {
        $this->uploadAnnual($this->csv([$this->row()]), '2026-01-01', '2026-07-31')
            ->assertSessionHasErrors([
                'end_date' => 'Annual Expanded WTAX must cover one full taxable year: '
                    . 'January 1 to December 31 of the same year.',
            ]);

        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_annual_upload_rejects_a_covered_period_that_starts_after_january(): void
    {
        $this->uploadAnnual($this->csv([$this->row()]), '2026-02-01', '2026-12-31')
            ->assertSessionHasErrors('start_date');

        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_annual_upload_rejects_a_cross_year_covered_period(): void
    {
        $this->uploadAnnual($this->csv([$this->row()]), '2026-01-01', '2027-01-31')
            ->assertSessionHasErrors('end_date');

        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    /**
     * The refusal has to come before the delete that clears the year, or a mistyped
     * end date would cost the user the annual rows already on file.
     */
    public function test_a_rejected_annual_upload_leaves_the_stored_annual_rows_alone(): void
    {
        $this->uploadAnnual($this->csv([$this->row()]), '2026-01-01', '2026-12-31')
            ->assertSessionHas('success');

        $this->uploadAnnual($this->csv([
            $this->row([1 => '004703296', 3 => 'REPLACEMENT PAYEE INC']),
        ]), '2026-01-01', '2026-07-31')->assertSessionHasErrors('end_date');

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame(
            'ACERSTEEL INDUSTRIAL SALES INC',
            ExpandedWtaxEntry::firstOrFail()->payee_name
        );
    }

    public function test_annual_upload_stores_each_row_under_its_own_reporting_month(): void
    {
        $this->uploadAnnual($this->csv([
            $this->row([0 => '07/03/2026']),
            $this->row([0 => '08/04/2026', 1 => '004703296', 3 => 'OTHER COMPANY INC', 8 => '100000.00', 10 => '1000.00']),
        ]))->assertSessionHas('success');

        $periods = ExpandedWtaxEntry::orderBy('reporting_period')
            ->pluck('reporting_period')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $this->assertSame(['2026-07-31', '2026-08-31'], $periods);
        $this->assertSame(['annual', 'annual'], ExpandedWtaxEntry::orderBy('reporting_period')->pluck('report_type')->all());
    }

    public function test_expanded_rows_stay_out_of_the_vat_tables(): void
    {
        $this->upload($this->csv([$this->row()]))->assertSessionHas('success');

        // Expanded withholding tax is not input VAT; merging the two would
        // overstate the VAT credit on the dashboard and in the RELIEF files.
        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame(0, \App\Models\VatInput::count());
        $this->assertSame(0, \App\Models\SalesVatInput::count());
    }

    public function test_bir_format_workbook_with_conflicting_company_tins_is_rejected(): void
    {
        $response = $this->upload($this->workbook(), '2025-12');

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('ACERSTEEL INDUSTRIAL SALES INC has multiple TINs', session('error'));
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_it_reads_the_system_expanded_wtax_export(): void
    {
        $this->upload($this->systemWorkbook(), '2026-07')->assertSessionHas('success');

        $entries = ExpandedWtaxEntry::orderBy('id')->get();

        $this->assertGreaterThan(0, $entries->count());

        $first = $entries->first();

        $this->assertSame('2026-07-31', $first->reporting_period->toDateString());
        $this->assertSame('PIONEER INSURANCE AND SURETY CORPORATION', $first->company_name);
        $this->assertSame('000541177', $first->payee_tin);
        $this->assertSame('WC160', $first->atc_code);
        $this->assertEqualsWithDelta(2.00, (float) $first->tax_rate, 0.001);
        $this->assertEqualsWithDelta(53.50, (float) $first->tax_withheld, 0.001);
        $this->assertEqualsWithDelta(2675.00, (float) $first->income_payment, 0.001);

        $worldBest = $entries->firstWhere('company_name', 'WORLD BEST IND L SALES INC');

        $this->assertNotNull($worldBest);
        $this->assertSame('WC158', $worldBest->atc_code);
        $this->assertEqualsWithDelta(1.00, (float) $worldBest->tax_rate, 0.001);
        $this->assertEqualsWithDelta(1340.13, (float) $worldBest->tax_withheld, 0.001);
        $this->assertEqualsWithDelta(134013.00, (float) $worldBest->income_payment, 0.001);

        $individual = $entries->firstWhere('payee_tin', '122086868');

        $this->assertNotNull($individual);
        $this->assertSame('individual', $individual->payee_type);
        $this->assertSame('RAMORES', $individual->last_name);
        $this->assertSame('CARMEN', $individual->first_name);
        $this->assertSame('WI516', $individual->atc_code);
        $this->assertEqualsWithDelta(10.00, (float) $individual->tax_rate, 0.001);
    }

    public function test_system_export_from_the_wrong_month_is_rejected_before_replacing_rows(): void
    {
        $this->upload($this->csv([$this->row()]))->assertSessionHas('success');

        $response = $this->upload($this->systemWorkbook(), '2026-08');

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $error = session('error');

        $this->assertStringContainsString('Row 4: Reporting_Month is 07/09/2026', $error);
        $this->assertStringContainsString('but this upload is for August 2026', $error);
        $this->assertSame(1, ExpandedWtaxEntry::count());
    }

    public function test_the_records_page_lists_the_consolidated_rows(): void
    {
        $this->storedExpanded();
        $this->storedExpanded(['income_payment' => 200000.00, 'tax_withheld' => 2000.00]);
        $this->storedExpanded([
            'payee_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'payee_tin' => '000491813',
            'company_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
            'income_payment' => 438047.00,
            'tax_withheld' => 4380.47,
        ]);
        $this->storedExpanded([
            'payee_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'payee_tin' => '000491813',
            'company_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
            'income_payment' => 100000.00,
            'tax_withheld' => 1000.00,
        ]);
        $this->storedExpanded([
            'payee_name' => 'BANSIL, JUAN CRUZ',
            'payee_type' => 'individual',
            'payee_tin' => '220052738',
            'company_name' => null,
            'last_name' => 'BANSIL',
            'first_name' => 'JUAN',
            'middle_name' => 'CRUZ',
            'atc_code' => 'WI010',
            'tax_rate' => 5.00,
            'income_payment' => 50000.00,
            'tax_withheld' => 2500.00,
        ]);

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Records/ExpandedWtaxRecords')
                ->has('expandedWtaxEntries.data', 3)
        );

        $this->assertSame(5, ExpandedWtaxEntry::count());

        // Search covers the payee, the TIN and the ATC code.
        $this->get('/records/expanded-wtax?search=BANSIL')->assertOk()->assertInertia(
            fn ($page) => $page->has('expandedWtaxEntries.data', 1)
        );

        $this->get('/records/expanded-wtax?search=000491813')->assertOk()->assertInertia(
            fn ($page) => $page->has('expandedWtaxEntries.data', 1)
        );

        $this->get('/records/expanded-wtax?search=WC158')->assertOk()->assertInertia(
            fn ($page) => $page->has('expandedWtaxEntries.data', 1)
        );
    }

    public function test_the_records_page_flags_a_merged_group_whose_tins_disagree(): void
    {
        $this->storedExpanded();
        $this->storedExpanded([
            'payee_tin' => '009999999',
            'income_payment' => 200000.00,
            'tax_withheld' => 2000.00,
        ]);
        $this->storedExpanded([
            'payee_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'payee_tin' => '000491813',
            'company_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
        ]);
        $this->storedExpanded([
            'payee_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'payee_tin' => '000491813',
            'company_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
        ]);

        $rows = collect(
            $this->get('/records/expanded-wtax')->viewData('page')['props']['expandedWtaxEntries']['data']
        );

        // Historical rows can still exist under two TINs at the same 1% rate. The
        // group keeps the first and says so, rather than filing the payee twice.
        $acersteel = $rows->firstWhere('atc_code', 'WC158');

        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', $acersteel['company_name']);
        $this->assertSame(2, $acersteel['merged_rows']);
        $this->assertSame('007086184', $acersteel['payee_tin']);
        $this->assertTrue($acersteel['has_multiple_payee_tins']);
        $this->assertSame(['007086184', '009999999'], $acersteel['distinct_payee_tins']);

        // The PRUDENTIAL pair agrees on its TIN, so it merges unflagged.
        $prudential = $rows->firstWhere('payee_tin', '000491813');

        $this->assertSame(2, $prudential['merged_rows']);
        $this->assertFalse($prudential['has_multiple_payee_tins']);
    }
}
