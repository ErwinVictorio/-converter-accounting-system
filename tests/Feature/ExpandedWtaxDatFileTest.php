<?php

namespace Tests\Feature;

use App\Models\ExpandedWtaxEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the generate half of the Expanded WTAX module: the reporting-month
 * listing on /generate-datfile and the file that /download-datfile hands back.
 *
 * The 1601EQ/QAP shape lives in tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php.
 * What matters here is
 * the wiring: the right rows for the right month, consolidated the way the BIR
 * format requires, in payee order, blocked when a row is unfilable, and kept out of
 * the RELIEF schedules.
 */
class ExpandedWtaxDatFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function entry(array $overrides = []): ExpandedWtaxEntry
    {
        return ExpandedWtaxEntry::create(array_merge([
            'reporting_period' => '2026-07-31',
            'payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'payee_type' => 'company',
            'payee_tin' => '007-086-184-000',
            'payee_branch_code' => '0000',
            'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'last_name' => null,
            'first_name' => null,
            'middle_name' => null,
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 3682716.00,
            'tax_withheld' => 36827.16,
        ], $overrides));
    }

    private function individual(array $overrides = []): ExpandedWtaxEntry
    {
        return $this->entry(array_merge([
            'payee_name' => 'BANSIL ANNIE',
            'payee_type' => 'individual',
            'payee_tin' => '220-052-738-000',
            'company_name' => null,
            'last_name' => 'BANSIL',
            'first_name' => 'ANNIE',
            'middle_name' => null,
            'atc_code' => 'WI516',
            'tax_rate' => 10.00,
            'income_payment' => 5865.60,
            'tax_withheld' => 586.56,
        ], $overrides));
    }

    /** @return string[] */
    private function lines(string $content): array
    {
        return explode("\r\n", rtrim($content, "\r\n"));
    }

    public function test_the_generate_page_lists_expanded_reporting_months(): void
    {
        $this->entry();
        $this->individual();
        $this->entry(['reporting_period' => '2026-06-30']);

        $this->get('/generate-datfile?record_type=expanded')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('GenerateDatFile')
                ->where('recordType', 'expanded')
                ->has('availablePeriods', 2)
                // Newest month first, with the row count the user is about to file.
                ->where('availablePeriods.0.value', '2026-07')
                ->where('availablePeriods.0.label', 'July 2026')
                ->where('availablePeriods.0.records_count', 2)
                ->where('availablePeriods.1.value', '2026-06')
                ->where('periodIssues.2026-07.invalid_count', 0)
        );
    }

    public function test_the_generate_page_reports_unfilable_rows_for_the_month(): void
    {
        $this->entry();
        // A 15% row: the rate is real but no ATC is configured for it yet.
        $this->entry([
            'payee_name' => 'SOME PROFESSIONAL SERVICES',
            'company_name' => 'SOME PROFESSIONAL SERVICES',
            'atc_code' => null,
            'tax_rate' => 15.00,
            'income_payment' => 10000.00,
            'tax_withheld' => 1500.00,
        ]);

        $response = $this->get('/generate-datfile?record_type=expanded');

        $response->assertOk();

        $issues = $response->viewData('page')['props']['periodIssues']['2026-07'];

        $this->assertSame(1, $issues['invalid_count']);
        $this->assertStringContainsString('SOME PROFESSIONAL SERVICES', $issues['errors'][0]);
        $this->assertStringContainsString('ATC is blank', $issues['errors'][0]);
    }

    public function test_it_downloads_a_1601eq_qap_dat_for_the_selected_month(): void
    {
        $this->entry();
        $this->individual();

        $response = $this->get('/download-datfile?period=2026-07-31&record_type=expanded');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
        // Company TIN + branch + month/year + form type, the naming shape the
        // BIR 1601EQ validator requires.
        $response->assertHeader(
            'content-disposition',
            'attachment; filename="00879197600000720261601EQ.DAT"'
        );

        $lines = $this->lines($response->getContent());

        $this->assertCount(4, $lines); // header + 2 details + trailer
        $this->assertSame('HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",07/2026,045', $lines[0]);

        $first = str_getcsv($lines[1]);

        // The 14 fields the BIR-generated reference file uses, in its order.
        $this->assertCount(14, $first);
        $this->assertSame('D1', $first[0]);
        $this->assertSame('1601EQ', $first[1]);
        $this->assertSame('1', $first[2]); // sequence starts at 1
        $this->assertSame('007086184', $first[3]);
        $this->assertSame('0000', $first[4]);
        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', $first[5]);
        $this->assertSame('', $first[6]);
        $this->assertSame('', $first[7]);
        $this->assertSame('', $first[8]);
        $this->assertSame('07/2026', $first[9]);
        $this->assertSame('WC158', $first[10]);
        $this->assertSame('1.00', $first[11]);
        $this->assertSame('3682716.00', $first[12]);
        $this->assertSame('36827.16', $first[13]);

        // An individual payee fills last/first/middle instead of company_name.
        $second = str_getcsv($lines[2]);

        $this->assertCount(14, $second);
        $this->assertSame('D1', $second[0]);
        $this->assertSame('2', $second[2]);
        $this->assertSame('', $second[5]);
        $this->assertSame('BANSIL', $second[6]);
        $this->assertSame('ANNIE', $second[7]);
        $this->assertSame('', $second[8]);
        $this->assertSame('WI516', $second[10]);

        // Trailer carries the exact sum of the detail rows.
        $this->assertSame('C1,1601EQ,008791976,0000,07/2026,3688581.60,37413.72', $lines[3]);
    }

    public function test_it_downloads_expanded_dat_per_withholding_agent(): void
    {
        $this->entry();
        $this->entry([
            'withholding_agent_tin' => '123456789',
            'withholding_agent_branch_code' => '0002',
            'withholding_agent_name' => 'OTHER COMPANY INC',
            'payee_name' => 'OTHER COMPANY PAYEE',
            'company_name' => 'OTHER COMPANY PAYEE',
            'payee_tin' => '111222333',
            'income_payment' => 100000.00,
            'tax_withheld' => 1000.00,
        ]);

        $first = $this->lines($this->get(
            '/download-datfile?period=2026-07-31&record_type=expanded&withholding_agent_tin=008791976&withholding_agent_branch_code=0000'
        )->getContent());

        $secondResponse = $this->get(
            '/download-datfile?period=2026-07-31&record_type=expanded&withholding_agent_tin=123456789&withholding_agent_branch_code=0002'
        );
        $second = $this->lines($secondResponse->getContent());

        $secondResponse->assertHeader(
            'content-disposition',
            'attachment; filename="12345678900020720261601EQ.DAT"'
        );

        $this->assertSame('HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",07/2026,045', $first[0]);
        $this->assertSame('HQAP,H1601EQ,123456789,0002,"OTHER COMPANY INC",07/2026,', $second[0]);
        $this->assertStringContainsString('ACERSTEEL INDUSTRIAL SALES INC', $first[1]);
        $this->assertStringNotContainsString('OTHER COMPANY PAYEE', implode('', $first));
        $this->assertStringContainsString('OTHER COMPANY PAYEE', $second[1]);
        $this->assertStringNotContainsString('ACERSTEEL INDUSTRIAL SALES INC', implode('', $second));
    }

    public function test_the_trailer_total_nets_out_a_reversal(): void
    {
        $this->entry();
        $this->entry([
            'payee_name' => 'H M ALAPIDE GRAVEL AND SAND SUPPLIER',
            'company_name' => 'H M ALAPIDE GRAVEL AND SAND SUPPLIER',
            'payee_tin' => '302-331-355-000',
            'atc_code' => 'WC100',
            'tax_rate' => 5.00,
            'income_payment' => -51600.00,
            'tax_withheld' => -2580.00,
        ]);

        $lines = $this->lines(
            $this->get('/download-datfile?period=2026-07-31&record_type=expanded')->getContent()
        );

        // Negative amounts keep their sign and their two decimals.
        $this->assertStringContainsString(',5.00,-51600.00,-2580.00', $lines[2]);
        $this->assertSame('34247.16', str_getcsv($lines[3])[6]);
    }

    public function test_details_are_filed_in_payee_order(): void
    {
        // Inserted out of order on purpose. Every row here has a key of its own, so
        // nothing merges and the three stay three: ZENITH carries its own TIN, and
        // the two ACERSTEEL rows differ by ATC and rate.
        $this->entry([
            'payee_name' => 'ZENITH HARDWARE',
            'company_name' => 'ZENITH HARDWARE',
            'payee_tin' => '004-703-296-000',
        ]);
        $this->entry(['payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC', 'tax_rate' => 2.00, 'atc_code' => 'WC160', 'income_payment' => 1000.00, 'tax_withheld' => 20.00]);
        $this->entry(['payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC']);

        $lines = $this->lines(
            $this->get('/download-datfile?period=2026-07-31&record_type=expanded')->getContent()
        );

        $names = array_map(fn ($line) => str_getcsv($line)[5], array_slice($lines, 1, 3));
        $rates = array_map(fn ($line) => str_getcsv($line)[11], array_slice($lines, 1, 3));

        $this->assertSame([
            'ACERSTEEL INDUSTRIAL SALES INC',
            'ACERSTEEL INDUSTRIAL SALES INC',
            'ZENITH HARDWARE',
        ], $names);
        // Within one payee, the lower rate is filed first.
        $this->assertSame(['1.00', '2.00', '1.00'], $rates);
    }

    /**
     * The two PRUDENTIAL rows of the reference file, which is where the duplicate
     * this rule exists for was found: same month, TIN, ATC and rate, filed twice.
     */
    private function prudential(array $overrides = []): ExpandedWtaxEntry
    {
        return $this->entry(array_merge([
            'payee_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'company_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'payee_tin' => '000-491-813-000',
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
            'income_payment' => 219023.50,
            'tax_withheld' => 4380.47,
        ], $overrides));
    }

    public function test_matching_rows_become_one_detail_line_with_summed_amounts(): void
    {
        $this->prudential();
        $this->prudential(['income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $lines = $this->lines(
            $this->get('/download-datfile?period=2026-07-31&record_type=expanded')->getContent()
        );

        // Two stored rows, one detail line: header + 1 detail + trailer.
        $this->assertCount(3, $lines);

        $detail = str_getcsv($lines[1]);

        $this->assertSame('D1', $detail[0]);
        $this->assertSame('000491813', $detail[3]);
        $this->assertSame('PRUDENTIAL GUARANTEE AND ASSURANCE INC', $detail[5]);
        $this->assertSame('WC160', $detail[10]);
        // 219023.50 + 1988.50 and 4380.47 + 39.77, summed rather than replaced.
        $this->assertSame('2.00', $detail[11]);
        $this->assertSame('221012.00', $detail[12]);
        $this->assertSame('4420.24', $detail[13]);
    }

    public function test_consolidation_leaves_the_control_total_untouched(): void
    {
        $this->entry();
        $this->prudential();
        $this->prudential(['income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $lines = $this->lines(
            $this->get('/download-datfile?period=2026-07-31&record_type=expanded')->getContent()
        );

        // Three stored rows, two detail lines.
        $this->assertCount(4, $lines);

        // Consolidation adds rows together rather than dropping any, so the control
        // total is still the sum of every stored row: 36827.16 + 4380.47 + 39.77.
        $stored = ExpandedWtaxEntry::sum('tax_withheld');

        $this->assertEqualsWithDelta(41247.40, (float) $stored, 0.001);
        $this->assertSame('41247.40', str_getcsv($lines[3])[6]);
    }

    public function test_payee_order_survives_consolidation(): void
    {
        // Inserted out of order, with the mergeable pair split up.
        $this->entry([
            'payee_name' => 'ZENITH HARDWARE',
            'company_name' => 'ZENITH HARDWARE',
            'payee_tin' => '004-703-296-000',
        ]);
        $this->prudential();
        $this->entry();
        $this->prudential(['income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $lines = $this->lines(
            $this->get('/download-datfile?period=2026-07-31&record_type=expanded')->getContent()
        );

        $this->assertCount(5, $lines); // 4 stored rows, 3 detail lines

        $names = array_map(fn ($line) => str_getcsv($line)[5], array_slice($lines, 1, 3));

        $this->assertSame([
            'ACERSTEEL INDUSTRIAL SALES INC',
            'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'ZENITH HARDWARE',
        ], $names);
        // Sequence numbers close the gap the merge leaves behind.
        $this->assertSame(['1', '2', '3'], array_map(
            fn ($line) => str_getcsv($line)[2],
            array_slice($lines, 1, 3)
        ));
    }

    public function test_the_listed_record_count_is_the_number_of_detail_lines(): void
    {
        $this->entry();
        $this->prudential();
        $this->prudential(['income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $response = $this->get('/generate-datfile?record_type=expanded');

        // What the Generate DAT screen promises...
        $count = $response->viewData('page')['props']['availablePeriods'][0]['records_count'];

        $this->assertSame(2, $count);

        // ...is what the file delivers.
        $lines = $this->lines(
            $this->get('/download-datfile?period=2026-07-31&record_type=expanded')->getContent()
        );

        $this->assertCount($count, array_slice($lines, 1, -1));
    }

    public function test_only_the_selected_month_is_filed(): void
    {
        $this->entry();
        $this->entry(['reporting_period' => '2026-06-30', 'payee_name' => 'JUNE PAYEE INC', 'company_name' => 'JUNE PAYEE INC']);

        $lines = $this->lines(
            $this->get('/download-datfile?period=2026-07-15&record_type=expanded')->getContent()
        );

        // Any day inside the month resolves to the same month-end file.
        $this->assertCount(3, $lines);
        $this->assertSame('HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",07/2026,045', $lines[0]);
        $this->assertStringNotContainsString('JUNE PAYEE INC', implode('', $lines));
    }

    public function test_a_month_with_no_rows_is_refused(): void
    {
        $this->entry();

        $response = $this->get('/download-datfile?period=2026-05-31&record_type=expanded');

        $response->assertRedirect();
        $response->assertSessionHas('error', 'No expanded withholding tax records found for the selected company and reporting month.');
    }

    public function test_an_unfilable_row_blocks_the_download_and_names_the_payee(): void
    {
        $this->entry();
        // The gap the sample workbook actually has: a named payee with no TIN.
        $this->entry([
            'payee_name' => 'GLOBE TELECOM INC',
            'company_name' => 'GLOBE TELECOM INC',
            'payee_tin' => '',
            'tax_rate' => 2.00,
            'atc_code' => 'WC160',
            'income_payment' => 1000.00,
            'tax_withheld' => 20.00,
        ]);

        $response = $this->get('/download-datfile?period=2026-07-31&record_type=expanded');

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $error = session('error');

        $this->assertStringContainsString('Cannot generate DAT', $error);
        $this->assertStringContainsString('GLOBE TELECOM INC', $error);
        $this->assertStringContainsString('payee_tin must contain at least 9 digits', $error);
    }

    public function test_expanded_rows_are_not_filed_in_the_relief_schedules(): void
    {
        $this->entry();

        // Expanded withholding tax is a 1601EQ/QAP matter. It has no place in the
        // RELIEF purchase, sales or importation files, and no VAT rows exist to
        // put there anyway.
        foreach (['purchase', 'sales', 'importation'] as $recordType) {
            $response = $this->get("/download-datfile?period=2026-07-31&record_type={$recordType}");

            $response->assertRedirect();
            $response->assertSessionHas('error');
        }

        // The reverse direction: nothing was written to the VAT tables either.
        $this->assertSame(0, \App\Models\VatInput::count());
        $this->assertSame(0, \App\Models\SalesVatInput::count());
        $this->assertSame(1, ExpandedWtaxEntry::count());
    }

    public function test_an_unknown_record_type_is_rejected(): void
    {
        $this->get('/download-datfile?period=2026-07-31&record_type=wtax')
            ->assertSessionHasErrors('record_type');

        $this->get('/generate-datfile?record_type=wtax')
            ->assertSessionHasErrors('record_type');
    }
}
