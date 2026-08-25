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
            $this->row([1 => '004703296', 2 => '']),
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

    public function test_expanded_rows_stay_out_of_the_vat_tables(): void
    {
        $this->upload($this->csv([$this->row()]))->assertSessionHas('success');

        // Expanded withholding tax is not input VAT; merging the two would
        // overstate the VAT credit on the dashboard and in the RELIEF files.
        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame(0, \App\Models\VatInput::count());
        $this->assertSame(0, \App\Models\SalesVatInput::count());
    }

    public function test_it_reads_the_bir_format_workbook_and_resolves_its_formulas(): void
    {
        $this->upload($this->workbook(), '2025-12')->assertSessionHas('success');

        $entries = ExpandedWtaxEntry::orderBy('id')->get();

        // Seven worksheet rows, seven stored rows.
        $this->assertCount(7, $entries);

        /*
         * Column K of the fixture is the template's own formula,
         * =ROUND(I*J/100,2). Stored as the value it computes -- had the formula text
         * been read instead, its digits would have been scraped into a nonsense
         * amount, which is what these three figures pin down.
         */
        $this->assertEqualsWithDelta(36827.16, (float) $entries[0]->tax_withheld, 0.001);
        $this->assertEqualsWithDelta(4380.47, (float) $entries[2]->tax_withheld, 0.001);
        $this->assertEqualsWithDelta(-1032.00, (float) $entries[5]->tax_withheld, 0.001);

        // Leading zeros survive: 000491813 is a real TIN, not 491813.
        $this->assertSame('000491813', $entries[2]->payee_tin);
        $this->assertSame('0000', $entries[2]->payee_branch_code);
        $this->assertSame('2025-12-31', $entries[0]->reporting_period->toDateString());

        // The individual row fills the name columns; the six company rows do not.
        $this->assertSame('individual', $entries[4]->payee_type);
        $this->assertSame('BANSIL', $entries[4]->last_name);
        $this->assertSame(6, $entries->where('payee_type', 'company')->count());

        // Every row is filable exactly as imported.
        $validator = app(BirExpandedWtaxRowValidator::class);

        foreach ($entries as $index => $entry) {
            $this->assertSame(
                [],
                $validator->validate($entry->toBirExpandedRow(), $index + 2),
                "Worksheet row {$index} of the fixture is unfilable as imported."
            );
        }
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
        $this->upload($this->workbook(), '2025-12')->assertSessionHas('success');

        // Seven stored rows, five listed. Two pairs merge: the two PRUDENTIAL rows,
        // and the two ACERSTEEL WC158 rows at 1% whose TINs disagree -- one payee
        // billed at one rate is one filing line either way.
        $this->get('/records')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('RecordEntry')
                ->has('expandedWtaxEntries.data', 5)
        );

        $this->assertSame(7, ExpandedWtaxEntry::count());

        // Search covers the payee, the TIN and the ATC code.
        $this->get('/records?search=BANSIL')->assertOk()->assertInertia(
            fn ($page) => $page->has('expandedWtaxEntries.data', 1)
        );

        $this->get('/records?search=000491813')->assertOk()->assertInertia(
            fn ($page) => $page->has('expandedWtaxEntries.data', 1)
        );

        $this->get('/records?search=WC158')->assertOk()->assertInertia(
            fn ($page) => $page->has('expandedWtaxEntries.data', 1)
        );
    }

    public function test_the_records_page_flags_a_merged_group_whose_tins_disagree(): void
    {
        $this->upload($this->workbook(), '2025-12')->assertSessionHas('success');

        $rows = collect(
            $this->get('/records')->viewData('page')['props']['expandedWtaxEntries']['data']
        );

        // The workbook files ACERSTEEL under two TINs at the same 1% rate. The
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
