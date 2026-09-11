<?php

namespace Tests\Feature;

use App\Models\ExpandedWtaxEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The BIR row rules, applied at upload rather than at Generate DAT.
 *
 * A payee TIN eight digits long used to import cleanly and then block the DAT weeks
 * later, with the offending row named by its stored id rather than by the worksheet
 * row the accountant is looking at. These cases cover the other end of that: the
 * upload is refused, the worksheet row is named, and the month already on file is
 * untouched.
 *
 * Two things every case here leans on:
 *
 * - The row numbers are worksheet positions. Headings sit on row 1, so the first
 *   data row is row 2 -- the number the person fixing the file sees in Excel.
 * - Nothing is written when a row fails. Not the good rows of the same file, not
 *   the rows already stored for that month.
 *
 * ExpandedWtaxImportTest still covers what a clean upload stores. The DAT-side
 * validator keeps its own coverage, because rows that predate this check still
 * exist and other entry paths still reach the table.
 */
class ExpandedWtaxUploadBirInfoValidationTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM_WORKBOOK = 'Docs/Expanded/EXPANDED WTAX.xlsx';

    private const HEADINGS = 'Reporting_Month,Vendor_TIN,branchCode,companyName,surName,'
        . 'firstName,middleName,ATC,income_payment,ewt_rate,tax_amount';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = [self::HEADINGS];

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
     * One valid company row, with only the cells a case cares about overridden.
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
            '100000.00',                        // income_payment
            '1',                                // ewt_rate
            '1000.00',                          // tax_amount
        ], $overrides);
    }

    /** A valid individual row, so the name-side rules have something to work on. */
    private function individualRow(array $overrides = []): array
    {
        return array_replace([
            '07/03/2026',
            '220052738',
            '0',
            '',
            'BANSIL',
            'JUAN',
            'CRUZ',
            'WI010',
            '50000.00',
            '5',
            '2500.00',
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

    private function systemWorkbook(): UploadedFile
    {
        $path = base_path(self::SYSTEM_WORKBOOK);

        if (! is_file($path)) {
            $this->markTestSkipped(self::SYSTEM_WORKBOOK . ' is not present in this checkout.');
        }

        return new UploadedFile($path, 'EXPANDED WTAX.xlsx', null, null, true);
    }

    /** The rejected upload's dialog payload. */
    private function dialog(\Illuminate\Testing\TestResponse $response): array
    {
        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');
        $response->assertSessionHas('uploadIssueDialog');

        return session('uploadIssueDialog');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function issues(\Illuminate\Testing\TestResponse $response): array
    {
        return $this->dialog($response)['issues'];
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

    // --- The TIN, which is what started all this ---------------------------------

    public function test_a_payee_tin_with_eight_digits_rejects_the_upload_at_the_named_worksheet_row(): void
    {
        // Row 2 valid, row 3 eight digits.
        $issues = $this->issues($this->upload($this->csv([
            $this->row(),
            $this->row([1 => '00708618', 3 => 'OTHER COMPANY INC']),
        ])));

        $this->assertCount(1, $issues);
        $this->assertSame(3, $issues[0]['row']);
        $this->assertSame('OTHER COMPANY INC', $issues[0]['name']);
        $this->assertSame('expanded', $issues[0]['record_type']);
        $this->assertSame('payee_tin', $issues[0]['field']);
        $this->assertStringContainsString('Vendor_TIN must contain at least 9 digits', $issues[0]['problem']);
        $this->assertSame(['Vendor_TIN'], $issues[0]['needed_fields']);
        $this->assertSame('worksheet row 3', $issues[0]['match_basis']);

        // The valid row on line 2 is not imported either: the file is rejected whole.
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_a_blank_payee_tin_rejects_the_upload(): void
    {
        $issues = $this->issues($this->upload($this->csv([$this->row([1 => ''])])));

        $this->assertSame(2, $issues[0]['row']);
        $this->assertSame('payee_tin', $issues[0]['field']);
        $this->assertStringContainsString('at least 9 digits', $issues[0]['problem']);
    }

    public function test_an_all_zero_payee_tin_rejects_the_upload(): void
    {
        $issues = $this->issues($this->upload($this->csv([$this->row([1 => '000000000'])])));

        $this->assertSame('payee_tin', $issues[0]['field']);
        $this->assertStringContainsString('cannot be 000000000', $issues[0]['problem']);
    }

    // --- Identity and names ------------------------------------------------------

    public function test_a_row_that_fills_both_name_sides_rejects_the_upload(): void
    {
        $issues = $this->issues($this->upload($this->csv([
            $this->row([4 => 'BANSIL', 5 => 'JUAN']),
        ])));

        $problems = array_column($issues, 'problem');

        $this->assertNotEmpty(array_filter(
            $problems,
            fn ($problem) => str_contains($problem, 'is filled alongside')
        ));
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_an_individual_row_missing_a_first_name_rejects_the_upload(): void
    {
        $issues = $this->issues($this->upload($this->csv([
            $this->individualRow([5 => '']),
        ])));

        $fields = array_column($issues, 'field');

        $this->assertContains('first_name', $fields);

        $firstName = $issues[array_search('first_name', $fields, true)];

        // Named as the workbook's own heading, not as the stored column.
        $this->assertSame(['firstName'], $firstName['needed_fields']);
        $this->assertStringContainsString('firstName is required', $firstName['problem']);
    }

    /**
     * A middle name is optional even for an individual -- three of the twelve
     * individual payees in the reference DAT leave it blank -- so a blank one is
     * not turned into a required field by this check.
     */
    public function test_a_blank_middle_name_is_accepted_for_an_individual(): void
    {
        $this->upload($this->csv([$this->individualRow([6 => ''])]))
            ->assertSessionHas('success');

        $this->assertNull(ExpandedWtaxEntry::firstOrFail()->middle_name);
    }

    // --- ATC ---------------------------------------------------------------------

    public function test_an_atc_outside_the_allowed_list_rejects_the_upload(): void
    {
        $issues = $this->issues($this->upload($this->csv([$this->row([7 => 'WC999'])])));

        $this->assertSame('atc_code', $issues[0]['field']);
        $this->assertStringContainsString('WC999', $issues[0]['problem']);
        $this->assertSame(['ATC'], $issues[0]['needed_fields']);
    }

    public function test_an_atc_filed_at_another_rate_rejects_the_upload(): void
    {
        // WC158 is a 1% code; this row withholds at 2%.
        $issues = $this->issues($this->upload($this->csv([
            $this->row([7 => 'WC158', 9 => '2', 10 => '2000.00']),
        ])));

        $this->assertSame('atc_code', $issues[0]['field']);
        $this->assertStringContainsString('WC158 is filed at 1.00%', $issues[0]['problem']);
        $this->assertStringContainsString('the row rate is 2.00%', $issues[0]['problem']);
    }

    public function test_an_atc_for_the_wrong_payee_type_rejects_the_upload(): void
    {
        // WI010 is an individual code, on a company row at the same 5% rate.
        $issues = $this->issues($this->upload($this->csv([
            $this->row([7 => 'WI010', 9 => '5', 10 => '5000.00']),
        ])));

        $this->assertSame('atc_code', $issues[0]['field']);
        $this->assertStringContainsString('WI010 is for a individual payee', $issues[0]['problem']);
    }

    // --- Rates and amounts -------------------------------------------------------

    public function test_a_zero_rate_rejects_the_upload(): void
    {
        $issues = $this->issues($this->upload($this->csv([
            $this->row([9 => '0', 10 => '0.00']),
        ])));

        $fields = array_column($issues, 'field');

        $this->assertContains('tax_rate', $fields);
        $this->assertStringContainsString(
            'ewt_rate must be greater than 0',
            $issues[array_search('tax_rate', $fields, true)]['problem']
        );
    }

    public function test_amounts_that_disagree_with_the_rate_reject_the_upload(): void
    {
        $issues = $this->issues($this->upload($this->csv([
            $this->row([8 => '100000.00', 9 => '1', 10 => '4000.00']),
        ])));

        $this->assertSame('tax_withheld', $issues[0]['field']);
        $this->assertStringContainsString('does not match income_payment', $issues[0]['problem']);
        $this->assertSame(['tax_amount'], $issues[0]['needed_fields']);
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    /**
     * A centavo either way is rounding in the workbook, not a wrong figure. The
     * validator's own one-centavo tolerance is what keeps a real file filable, and
     * moving the check to upload must not tighten it.
     */
    public function test_a_one_centavo_rounding_difference_is_accepted(): void
    {
        $this->upload($this->csv([
            $this->row([8 => '3682716.00', 9 => '1', 10 => '36827.15']),
        ]))->assertSessionHas('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
    }

    public function test_a_negative_reversal_is_accepted(): void
    {
        $this->upload($this->csv([
            $this->row([7 => 'WC160', 8 => '-51600.00', 9 => '2', 10 => '-1032.00']),
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertEqualsWithDelta(-51600.00, (float) $entry->income_payment, 0.001);
        $this->assertEqualsWithDelta(-1032.00, (float) $entry->tax_withheld, 0.001);
    }

    // --- Raw numeric cells -------------------------------------------------------

    /**
     * The importer strips a numeric cell down to digits, a dot and a minus, so
     * "1.234,56" would read as 1.23 and "(1,000.00)" would lose its sign. Both are
     * reported rather than converted: the arithmetic is left alone and only the raw
     * text is judged.
     */
    public function test_a_bracketed_negative_rejects_the_upload_rather_than_losing_its_sign(): void
    {
        $issues = $this->issues($this->upload($this->csv([
            $this->row([8 => '100000.00', 9 => '1', 10 => '(1000.00)']),
        ])));

        $problems = array_column($issues, 'problem');

        $this->assertNotEmpty(array_filter(
            $problems,
            fn ($problem) => str_contains($problem, 'is not a readable number')
        ));
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_a_currency_symbol_in_an_amount_rejects_the_upload(): void
    {
        $issues = $this->issues($this->upload($this->csv([
            $this->row([8 => 'PHP 100000.00']),
        ])));

        $problems = implode(' ', array_column($issues, 'problem'));

        $this->assertStringContainsString('income_payment is not a readable number', $problems);
    }

    public function test_a_thousands_separator_is_still_accepted(): void
    {
        // A comma-formatted cell is a formatting slip, not a different amount --
        // and in a CSV it has to be quoted to survive the delimiter.
        $file = UploadedFile::fake()->createWithContent(
            'expanded-wtax.csv',
            self::HEADINGS . "\r\n"
                . '07/03/2026,007086184,0,ACERSTEEL INDUSTRIAL SALES INC,,,,WC158,'
                . '"100,000.00",1,"1,000.00"' . "\r\n"
        );

        $this->upload($file)->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertEqualsWithDelta(100000.00, (float) $entry->income_payment, 0.001);
        $this->assertEqualsWithDelta(1000.00, (float) $entry->tax_withheld, 0.001);
    }

    // --- The system export layout ------------------------------------------------

    public function test_a_short_tin_in_a_system_export_rejects_the_upload_at_the_worksheet_row(): void
    {
        $issues = $this->issues($this->upload($this->systemCsv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007086184', 'A-1', '1000.00', '', '', '', '', '1000.00'],
            ['2', '07/04/2026', 'OTHER COMPANY INC', '00542528', 'A-2', '', '500.00', '', '', '', '500.00'],
        ])));

        $this->assertCount(1, $issues);
        $this->assertSame(3, $issues[0]['row']);
        $this->assertSame('OTHER COMPANY INC', $issues[0]['name']);
        $this->assertSame('payee_tin', $issues[0]['field']);
        $this->assertSame(['TIN'], $issues[0]['needed_fields']);
        // The identity is one cell shared by every rate column on the line, so the
        // message does not pretend the problem belongs to (2%).
        $this->assertSame('worksheet row 3', $issues[0]['match_basis']);
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    /**
     * A system-export line becomes one entry per rate column, so a bad TIN would
     * otherwise be reported once per column it was withheld in.
     */
    public function test_an_identity_error_is_reported_once_for_a_line_with_several_rate_columns(): void
    {
        $issues = $this->issues($this->upload($this->systemCsv([
            ['1', '07/03/2026', 'OTHER COMPANY INC', '00542528', 'A-1', '1000.00', '500.00', '', '', '', '1500.00'],
        ])));

        $this->assertCount(1, $issues);
        $this->assertSame('payee_tin', $issues[0]['field']);
    }

    public function test_a_blank_rate_column_in_a_system_export_is_not_made_required(): void
    {
        // Only (1%) is filled; the other four are blank and stay optional.
        $this->upload($this->systemCsv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007086184', 'A-1', '1000.00', '', '', '', '', '1000.00'],
        ]))->assertSessionHas('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertEqualsWithDelta(1.00, (float) ExpandedWtaxEntry::firstOrFail()->tax_rate, 0.001);
    }

    public function test_a_malformed_rate_column_in_a_system_export_names_that_column(): void
    {
        $issues = $this->issues($this->upload($this->systemCsv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007086184', 'A-1', '(1000.00)', '', '', '', '', '1000.00'],
        ])));

        $this->assertSame(2, $issues[0]['row']);
        $this->assertStringContainsString('(1%) is not a readable number', $issues[0]['problem']);
        $this->assertSame('worksheet row 2, column (1%)', $issues[0]['match_basis']);
    }

    /**
     * The in-house export in Docs/Expanded has blank TIN cells, which is the usual
     * reason its DAT would not generate. That gap is now caught at upload, on the
     * worksheet rows that carry it.
     */
    public function test_the_real_system_export_is_rejected_for_its_blank_tins(): void
    {
        $issues = $this->issues($this->upload($this->systemWorkbook(), '2026-07'));

        $tinIssues = array_values(array_filter(
            $issues,
            fn ($issue) => $issue['field'] === 'payee_tin'
        ));

        $this->assertNotEmpty($tinIssues);
        $this->assertStringContainsString('at least 9 digits', $tinIssues[0]['problem']);

        // Every issue points at the workbook, because that is where an Expanded
        // payee's details live -- there is no Customers or Suppliers page to send
        // the user to, so no fix_route is offered.
        foreach ($issues as $issue) {
            $this->assertArrayNotHasKey('fix_route', $issue);
            $this->assertStringContainsString('uploaded workbook', $issue['fix_location']);
        }

        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    // --- The dialog payload ------------------------------------------------------

    public function test_the_dialog_counts_issues_and_affected_rows_separately(): void
    {
        // Row 2: two problems on one row (short TIN and a blank ATC).
        // Row 3: one problem.
        $dialog = $this->dialog($this->upload($this->csv([
            $this->row([1 => '00708618', 7 => '']),
            $this->row([1 => '00470329', 3 => 'OTHER COMPANY INC']),
        ])));

        $this->assertSame('expanded', $dialog['record_type']);
        $this->assertGreaterThan(2, count($dialog['issues']));
        $this->assertStringContainsString('across 2 worksheet row(s)', $dialog['summary']);
        $this->assertStringContainsString('No records were imported or replaced', $dialog['summary']);
    }

    /**
     * Every failing row is reported, not just the first. Truncating would send the
     * user round the upload loop once per bad row.
     */
    public function test_every_failing_row_is_reported_rather_than_only_the_first(): void
    {
        $rows = [];

        for ($i = 0; $i < 12; $i++) {
            $rows[] = $this->row([1 => '00708618', 3 => 'COMPANY ' . $i]);
        }

        $issues = $this->issues($this->upload($this->csv($rows)));

        $this->assertCount(12, $issues);
        $this->assertSame(range(2, 13), array_column($issues, 'row'));
    }

    public function test_the_worksheet_row_counts_a_title_row_above_the_headings(): void
    {
        // A guide row above the headings, as the BIR template ships. The data row is
        // worksheet row 3, and that is the number reported.
        $file = UploadedFile::fake()->createWithContent(
            'expanded-wtax.csv',
            "SCHEDULE 1 -- EXPANDED WITHHOLDING TAX\r\n"
                . self::HEADINGS . "\r\n"
                . implode(',', $this->row([1 => '00708618'])) . "\r\n"
        );

        $issues = $this->issues($this->upload($file));

        $this->assertSame(3, $issues[0]['row']);
        $this->assertSame('worksheet row 3', $issues[0]['match_basis']);
    }

    // --- Atomicity ---------------------------------------------------------------

    public function test_a_rejected_upload_leaves_the_stored_month_exactly_as_it_was(): void
    {
        $this->upload($this->csv([$this->row()]))->assertSessionHas('success');

        $before = ExpandedWtaxEntry::firstOrFail();
        $beforeAttributes = $before->only([
            'payee_name', 'payee_tin', 'atc_code', 'tax_rate', 'income_payment', 'tax_withheld',
        ]);

        $this->upload($this->csv([
            $this->row([1 => '00708618', 3 => 'REPLACEMENT PAYEE INC']),
        ]))->assertSessionMissing('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());

        $after = ExpandedWtaxEntry::firstOrFail();

        // The same row, not a re-inserted copy: nothing was deleted and re-imported.
        $this->assertSame($before->id, $after->id);
        $this->assertSame($beforeAttributes, $after->only(array_keys($beforeAttributes)));
    }

    public function test_a_rejected_upload_leaves_other_months_agents_and_report_types_alone(): void
    {
        $august = $this->storedExpanded(['reporting_period' => '2026-08-31']);
        $otherAgent = $this->storedExpanded(['withholding_agent_tin' => '123456789']);
        $otherBranch = $this->storedExpanded(['withholding_agent_branch_code' => '0001']);
        $annual = $this->storedExpanded(['report_type' => 'annual', 'reporting_period' => '2026-12-31']);
        $july = $this->storedExpanded();

        $this->upload($this->csv([$this->row([1 => '00708618'])]))
            ->assertSessionMissing('success');

        foreach ([$august, $otherAgent, $otherBranch, $annual, $july] as $entry) {
            $this->assertDatabaseHas('expanded_wtax_entries', ['id' => $entry->id]);
        }

        $this->assertSame(5, ExpandedWtaxEntry::count());
    }

    public function test_the_corrected_file_uploads_and_replaces_only_its_own_scope(): void
    {
        $otherAgent = $this->storedExpanded(['withholding_agent_tin' => '123456789']);
        $august = $this->storedExpanded(['reporting_period' => '2026-08-31']);
        $july = $this->storedExpanded(['tax_withheld' => 1000.00]);

        $this->upload($this->csv([$this->row([1 => '00708618'])]))
            ->assertSessionMissing('success');

        // The same file with the TIN corrected: accepted, and it replaces the July
        // row for this agent only.
        $this->upload($this->csv([
            $this->row([8 => '300000.00', 9 => '1', 10 => '3000.00']),
        ]))->assertSessionHas('success');

        $this->assertDatabaseMissing('expanded_wtax_entries', ['id' => $july->id]);
        $this->assertDatabaseHas('expanded_wtax_entries', ['id' => $august->id]);
        $this->assertDatabaseHas('expanded_wtax_entries', ['id' => $otherAgent->id]);

        $replacement = ExpandedWtaxEntry::query()
            ->where('report_type', 'quarterly')
            ->where('reporting_period', '2026-07-31')
            ->where('withholding_agent_tin', '008791976')
            ->sole();

        $this->assertEqualsWithDelta(3000.00, (float) $replacement->tax_withheld, 0.001);
    }

    public function test_a_rejected_upload_for_one_agent_does_not_touch_another_agents_month(): void
    {
        $this->uploadForAgent($this->csv([$this->row()]), '2026-07', '123456789')
            ->assertSessionHas('success');

        $this->uploadForAgent(
            $this->csv([$this->row([1 => '00708618'])]),
            '2026-07',
            '008791976'
        )->assertSessionMissing('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame('123456789', ExpandedWtaxEntry::firstOrFail()->withholding_agent_tin);
    }

    // --- Annual ------------------------------------------------------------------

    public function test_an_annual_upload_is_checked_row_by_row_too(): void
    {
        $issues = $this->issues($this->uploadAnnual($this->csv([
            $this->row([0 => '07/03/2026']),
            $this->row([0 => '08/04/2026', 1 => '00470329', 3 => 'OTHER COMPANY INC']),
        ])));

        $this->assertCount(1, $issues);
        $this->assertSame(3, $issues[0]['row']);
        $this->assertSame('payee_tin', $issues[0]['field']);
        $this->assertStringContainsString('annual upload rejected', session('error'));
        $this->assertSame(0, ExpandedWtaxEntry::count());
    }

    public function test_a_rejected_annual_upload_leaves_the_stored_year_alone(): void
    {
        $this->uploadAnnual($this->csv([$this->row()]))->assertSessionHas('success');

        $before = ExpandedWtaxEntry::firstOrFail();

        $this->uploadAnnual($this->csv([
            $this->row([1 => '00708618', 3 => 'REPLACEMENT PAYEE INC']),
        ]))->assertSessionMissing('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame($before->id, ExpandedWtaxEntry::firstOrFail()->id);
    }

    public function test_an_annual_upload_keeps_each_rows_own_reporting_month_after_the_check(): void
    {
        // The check runs with the annual mapping, so it must not report a row for
        // the month it was written in.
        $this->uploadAnnual($this->csv([
            $this->row([0 => '02/10/2026']),
            $this->row([0 => '11/05/2026', 1 => '004703296', 3 => 'OTHER COMPANY INC']),
        ]))->assertSessionHas('success');

        $periods = ExpandedWtaxEntry::orderBy('reporting_period')
            ->pluck('reporting_period')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $this->assertSame(['2026-02-28', '2026-11-30'], $periods);
    }

    // --- What a clean file still does --------------------------------------------

    public function test_a_valid_file_still_imports_with_its_amounts_and_identifiers_intact(): void
    {
        $this->upload($this->csv([
            $this->row([2 => '1', 8 => '3682716.00', 9 => '1', 10 => '36827.16']),
            $this->individualRow(),
        ]))->assertSessionHas('success')->assertSessionMissing('uploadIssueDialog');

        $company = ExpandedWtaxEntry::where('payee_tin', '007086184')->firstOrFail();

        $this->assertEqualsWithDelta(3682716.00, (float) $company->income_payment, 0.001);
        $this->assertEqualsWithDelta(36827.16, (float) $company->tax_withheld, 0.001);
        $this->assertSame('WC158', $company->atc_code);
        $this->assertSame('0001', $company->payee_branch_code);
        $this->assertSame('company', $company->payee_type);

        $individual = ExpandedWtaxEntry::where('payee_tin', '220052738')->firstOrFail();

        $this->assertSame('individual', $individual->payee_type);
        $this->assertSame('BANSIL', $individual->last_name);
        $this->assertSame('WI010', $individual->atc_code);
        $this->assertEqualsWithDelta(2500.00, (float) $individual->tax_withheld, 0.001);
    }
}
