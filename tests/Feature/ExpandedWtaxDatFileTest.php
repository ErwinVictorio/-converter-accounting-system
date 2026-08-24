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
 * The byte-for-byte comparison against Docs/Expanded/0087919760000123120251604E.dat
 * lives in tests/Unit/ReliefExpandedWtaxDatGeneratorTest.php. What matters here is
 * the wiring: the right rows for the right month, in payee order, blocked when a
 * row is unfilable, and kept out of the RELIEF schedules.
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
            'transaction_date' => '2026-07-03',
            'source_no' => '1',
            'reference_no' => 'SI-1001',
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
            'source_row' => 4,
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
        $this->assertStringContainsString('no ATC code could be resolved', $issues['errors'][0]);
    }

    public function test_it_downloads_a_1604e_dat_for_the_selected_month(): void
    {
        $this->entry();
        $this->individual();

        $response = $this->get('/download-datfile?period=2026-07-31&record_type=expanded');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
        // Company TIN + branch + month end + form type, the same shape as the
        // reference file 0087919760000123120251604E.dat.
        $response->assertHeader(
            'content-disposition',
            'attachment; filename="0087919760000073120261604E.dat"'
        );

        $lines = $this->lines($response->getContent());

        $this->assertCount(4, $lines); // header + 2 details + trailer
        $this->assertSame('H1604E,008791976,0000,07/31/2026', $lines[0]);

        $first = str_getcsv($lines[1]);

        $this->assertSame('D3', $first[0]);
        $this->assertSame('1604E', $first[1]);
        $this->assertSame('008791976', $first[2]);
        $this->assertSame('07/31/2026', $first[4]);
        $this->assertSame('1', $first[5]); // sequence starts at 1
        $this->assertSame('007086184', $first[6]);
        $this->assertSame('0000', $first[7]);
        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', $first[8]);
        $this->assertSame('WC158', $first[12]);
        $this->assertSame('3682716.00', $first[13]);
        $this->assertSame('1.00', $first[14]);
        $this->assertSame('36827.16', $first[15]);

        // An individual payee fills last/first/middle instead of company_name.
        $second = str_getcsv($lines[2]);

        $this->assertSame('2', $second[5]);
        $this->assertSame('', $second[8]);
        $this->assertSame('BANSIL', $second[9]);
        $this->assertSame('ANNIE', $second[10]);
        $this->assertSame('', $second[11]);
        $this->assertSame('WI516', $second[12]);

        // Trailer carries the exact sum of the detail rows.
        $this->assertSame('C3,1604E,008791976,0000,07/31/2026,37413.72', $lines[3]);
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
        $this->assertStringContainsString(',-51600.00,5.00,-2580.00', $lines[2]);
        $this->assertSame('34247.16', str_getcsv($lines[3])[5]);
    }

    public function test_details_are_filed_in_payee_order(): void
    {
        // Inserted out of order on purpose.
        $this->entry(['payee_name' => 'ZENITH HARDWARE', 'company_name' => 'ZENITH HARDWARE']);
        $this->entry(['payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC', 'tax_rate' => 2.00, 'atc_code' => 'WC160', 'income_payment' => 1000.00, 'tax_withheld' => 20.00]);
        $this->entry(['payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC']);

        $lines = $this->lines(
            $this->get('/download-datfile?period=2026-07-31&record_type=expanded')->getContent()
        );

        $names = array_map(fn ($line) => str_getcsv($line)[8], array_slice($lines, 1, 3));
        $rates = array_map(fn ($line) => str_getcsv($line)[14], array_slice($lines, 1, 3));

        $this->assertSame([
            'ACERSTEEL INDUSTRIAL SALES INC',
            'ACERSTEEL INDUSTRIAL SALES INC',
            'ZENITH HARDWARE',
        ], $names);
        // Within one payee, the lower rate is filed first.
        $this->assertSame(['1.00', '2.00', '1.00'], $rates);
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
        $this->assertSame('H1604E,008791976,0000,07/31/2026', $lines[0]);
        $this->assertStringNotContainsString('JUNE PAYEE INC', implode('', $lines));
    }

    public function test_a_month_with_no_rows_is_refused(): void
    {
        $this->entry();

        $response = $this->get('/download-datfile?period=2026-05-31&record_type=expanded');

        $response->assertRedirect();
        $response->assertSessionHas('error', 'No expanded withholding tax records found for the selected reporting month.');
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

        // Expanded withholding tax is a 1604E matter. It has no place in the
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
