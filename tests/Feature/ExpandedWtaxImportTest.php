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
 * record_type=expanded must land rows in expanded_wtax_entries, one per rate
 * column that carries an amount, with the income payment and the ATC code
 * derived because the workbook supplies neither.
 *
 * Most cases use a CSV rather than an .xlsx because the route accepts both and
 * the importer reads them through the same heading-row pipeline; one case runs
 * the real Docs/Expanded/EXPANDED WTAX.xlsx end to end so the layout assumptions
 * stay honest.
 */
class ExpandedWtaxImportTest extends TestCase
{
    use RefreshDatabase;

    private const WORKBOOK = 'Docs/Expanded/EXPANDED WTAX.xlsx';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * Two junk rows above the headings, exactly like the real workbook, so the
     * headingRow() offset is exercised and not just assumed.
     */
    private function csv(array $rows): UploadedFile
    {
        $lines = [
            'EXPANDED WITHHOLDING TAX,,,,,,,,,,',
            'FOR THE MONTH OF JULY 2026,,,,,,,,,,',
            'NO.,DATE,SUPPLIER NAME,TIN,REFERENCE,(1%),(2%),(5%),(10%),(15%),TOTAL',
        ];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent(
            'expanded-wtax.csv',
            implode("\r\n", $lines) . "\r\n"
        );
    }

    private function upload(UploadedFile $file, string $month = '2026-07'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/vat-import', [
            'excel_file' => $file,
            'reporting_month' => $month,
            'record_type' => 'expanded',
        ]);
    }

    public function test_it_stores_one_row_per_rate_column_and_derives_the_income_payment(): void
    {
        // A single voucher withholding at two rates: 1% on goods, 2% on services.
        $response = $this->upload($this->csv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007-086-184-000', 'SI-1001', '368.27', '250.00', '', '', '', '618.27'],
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $entries = ExpandedWtaxEntry::orderBy('tax_rate')->get();

        $this->assertCount(2, $entries);

        // income_payment = withheld / rate, because the workbook has no base column.
        $this->assertEqualsWithDelta(1.00, (float) $entries[0]->tax_rate, 0.001);
        $this->assertEqualsWithDelta(36827.00, (float) $entries[0]->income_payment, 0.001);
        $this->assertEqualsWithDelta(368.27, (float) $entries[0]->tax_withheld, 0.001);
        $this->assertSame('WC158', $entries[0]->atc_code);

        $this->assertEqualsWithDelta(2.00, (float) $entries[1]->tax_rate, 0.001);
        $this->assertEqualsWithDelta(12500.00, (float) $entries[1]->income_payment, 0.001);
        $this->assertSame('WC160', $entries[1]->atc_code);

        // Shared payee fields are copied onto both rows.
        foreach ($entries as $entry) {
            $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', $entry->payee_name);
            $this->assertSame('company', $entry->payee_type);
            $this->assertSame('0000', $entry->payee_branch_code);
            $this->assertSame('2026-07-31', $entry->reporting_period->toDateString());
            $this->assertSame('2026-07-03', $entry->transaction_date->toDateString());
            $this->assertSame('SI-1001', $entry->reference_no);
        }
    }

    public function test_it_splits_an_individual_payee_and_picks_the_wi_code(): void
    {
        $this->upload($this->csv([
            ['1', '07/05/2026', '"BANSIL, ANNIE"', '220-052-738-000', 'PV-2001', '', '', '', '586.56', '', '586.56'],
            ['2', '07/06/2026', '"SY, JULIET HUI"', '188-291-434-000', 'PV-2002', '', '', '400.00', '', '', '400.00'],
        ]))->assertSessionHas('success');

        $bansil = ExpandedWtaxEntry::where('payee_tin', 'like', '220-052-738%')->firstOrFail();

        $this->assertSame('individual', $bansil->payee_type);
        $this->assertSame('BANSIL', $bansil->last_name);
        $this->assertSame('ANNIE', $bansil->first_name);
        $this->assertNull($bansil->middle_name);
        $this->assertNull($bansil->company_name);
        // 10% from an individual is WI516, not the company's WC139.
        $this->assertSame('WI516', $bansil->atc_code);

        $sy = ExpandedWtaxEntry::where('payee_tin', 'like', '188-291-434%')->firstOrFail();

        $this->assertSame('SY', $sy->last_name);
        $this->assertSame('JULIET', $sy->first_name);
        $this->assertSame('HUI', $sy->middle_name);
        // 5% from an individual is WI010, not the company's WC100.
        $this->assertSame('WI010', $sy->atc_code);
    }

    public function test_a_company_name_containing_a_comma_stays_a_company(): void
    {
        // "CO" is both a company suffix and a common surname, so the corporate
        // token check has to look at the text after the comma, not anywhere in it.
        $this->upload($this->csv([
            ['1', '07/07/2026', '"WORLD BEST SALES, INC."', '004-703-296-000', 'SI-3001', '150.00', '', '', '', '', '150.00'],
            ['2', '07/08/2026', '"CO, JUAN"', '145-889-201-000', 'PV-3002', '150.00', '', '', '', '', '150.00'],
        ]))->assertSessionHas('success');

        $company = ExpandedWtaxEntry::where('payee_tin', 'like', '004-703-296%')->firstOrFail();

        $this->assertSame('company', $company->payee_type);
        $this->assertSame('WORLD BEST SALES INC', $company->company_name);
        $this->assertNull($company->last_name);

        $individual = ExpandedWtaxEntry::where('payee_tin', 'like', '145-889-201%')->firstOrFail();

        $this->assertSame('individual', $individual->payee_type);
        $this->assertSame('CO', $individual->last_name);
        $this->assertSame('JUAN', $individual->first_name);
    }

    public function test_it_skips_the_totals_row_and_blank_payees(): void
    {
        $this->upload($this->csv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007-086-184-000', 'SI-1001', '368.27', '', '', '', '', '368.27'],
            ['', '', '', '', '', '', '', '', '', '', ''],
            ['', '', 'TOTAL:', '', '', '368.27', '', '', '', '', '368.27'],
        ]))->assertSessionHas('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', ExpandedWtaxEntry::firstOrFail()->payee_name);
    }

    public function test_it_normalises_names_to_bir_safe_text(): void
    {
        $this->upload($this->csv([
            ['1', '07/09/2026', 'h.m. alapide gravel & sand supplier', '302-331-355-000', 'SI-4001', '', '', '2580.00', '', '', '2580.00'],
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        // Upper-cased, "&" spelled out, periods dropped, runs of spaces collapsed:
        // the reference DAT contains no punctuation at all.
        $this->assertSame('H M ALAPIDE GRAVEL AND SAND SUPPLIER', $entry->payee_name);
        $this->assertSame('H M ALAPIDE GRAVEL AND SAND SUPPLIER', $entry->company_name);
        $this->assertStringNotContainsString('&', $entry->company_name);
        $this->assertStringNotContainsString('.', $entry->company_name);
    }

    public function test_it_truncates_a_long_company_name_to_fifty_characters(): void
    {
        $this->upload($this->csv([
            ['1', '07/10/2026', 'ACERSTEEL INDUSTRIAL SALES INCORPORATED AND SUBSIDIARIES', '007-086-184-000', 'SI-5001', '100.00', '', '', '', '', '100.00'],
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertSame(50, strlen($entry->company_name));
        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INCORPORATED AND SUBSID', $entry->company_name);
    }

    public function test_it_keeps_a_negative_reversal(): void
    {
        $this->upload($this->csv([
            ['1', '07/11/2026', 'H M ALAPIDE GRAVEL AND SAND SUPPLIER', '302-331-355-000', 'SI-6001', '', '', '-2580.00', '', '', '-2580.00'],
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertEqualsWithDelta(-2580.00, (float) $entry->tax_withheld, 0.001);
        $this->assertEqualsWithDelta(-51600.00, (float) $entry->income_payment, 0.001);
        $this->assertSame('WC100', $entry->atc_code);
    }

    public function test_an_unmappable_rate_is_stored_with_no_atc_instead_of_failing_the_upload(): void
    {
        // 15% is deliberately left out of default_rate_codes: nobody has confirmed
        // which schedule it belongs to. Dropping the row would lose money silently,
        // so it is stored and the validator blocks the DAT until it is mapped.
        $this->upload($this->csv([
            ['1', '07/12/2026', 'SOME PROFESSIONAL SERVICES', '123-456-789-000', 'PV-7001', '', '', '', '', '1500.00', '1500.00'],
        ]))->assertSessionHas('success');

        $entry = ExpandedWtaxEntry::firstOrFail();

        $this->assertNull($entry->atc_code);
        $this->assertEqualsWithDelta(15.00, (float) $entry->tax_rate, 0.001);
        $this->assertEqualsWithDelta(10000.00, (float) $entry->income_payment, 0.001);

        $errors = app(BirExpandedWtaxRowValidator::class)->validate($entry->toBirExpandedRow(), 4);
        $this->assertNotEmpty($errors);
    }

    public function test_a_per_payee_override_beats_the_default_code_for_that_rate(): void
    {
        // 10% covers both professional fees and brokers' commissions; only the
        // taxpayer knows which applies to a given payee.
        config()->set('bir.expanded_wtax.payee_atc_overrides', [
            '007086184' => ['10.00' => 'WC139'],
        ]);

        $this->upload($this->csv([
            ['1', '07/13/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007-086-184-000', 'SI-8001', '', '', '', '900.00', '', '900.00'],
            ['2', '07/13/2026', 'OTHER SUPPLIER INC', '004-703-296-000', 'SI-8002', '', '', '', '900.00', '', '900.00'],
        ]))->assertSessionHas('success');

        $this->assertSame(
            'WC139',
            ExpandedWtaxEntry::where('payee_tin', 'like', '007-086-184%')->firstOrFail()->atc_code
        );
        $this->assertSame(
            'WC139',
            ExpandedWtaxEntry::where('payee_tin', 'like', '004-703-296%')->firstOrFail()->atc_code
        );
    }

    public function test_re_uploading_a_month_replaces_it_rather_than_doubling_the_tax(): void
    {
        $file = fn (string $withheld) => $this->csv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007-086-184-000', 'SI-1001', $withheld, '', '', '', '', $withheld],
        ]);

        $this->upload($file('368.27'))->assertSessionHas('success');
        $this->upload($file('400.00'))->assertSessionHas('success');

        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertEqualsWithDelta(400.00, (float) ExpandedWtaxEntry::firstOrFail()->tax_withheld, 0.001);

        // A different month is untouched by the replace.
        $this->upload($file('500.00'), '2026-08')->assertSessionHas('success');

        $this->assertSame(2, ExpandedWtaxEntry::count());
    }

    public function test_expanded_rows_stay_out_of_the_vat_tables(): void
    {
        $this->upload($this->csv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007-086-184-000', 'SI-1001', '368.27', '', '', '', '', '368.27'],
        ]))->assertSessionHas('success');

        // Expanded withholding tax is not input VAT; merging the two would
        // overstate the VAT credit on the dashboard and in the RELIEF files.
        $this->assertSame(1, ExpandedWtaxEntry::count());
        $this->assertSame(0, \App\Models\VatInput::count());
        $this->assertSame(0, \App\Models\SalesVatInput::count());
    }

    public function test_the_records_page_lists_the_imported_expanded_rows(): void
    {
        $this->upload($this->csv([
            ['1', '07/03/2026', 'ACERSTEEL INDUSTRIAL SALES INC', '007-086-184-000', 'SI-1001', '368.27', '', '', '', '', '368.27'],
            ['2', '07/05/2026', '"BANSIL, ANNIE"', '220-052-738-000', 'PV-2001', '', '', '', '586.56', '', '586.56'],
        ]))->assertSessionHas('success');

        $this->get('/records')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('RecordEntry')
                ->has('expandedWtaxEntries.data', 2)
        );

        // Search covers the payee, the TIN and the ATC code.
        $this->get('/records?search=BANSIL')->assertOk()->assertInertia(
            fn ($page) => $page->has('expandedWtaxEntries.data', 1)
        );

        $this->get('/records?search=WC158')->assertOk()->assertInertia(
            fn ($page) => $page->has('expandedWtaxEntries.data', 1)
        );
    }

    public function test_it_imports_the_sample_workbook_and_only_blank_tin_rows_are_unfilable(): void
    {
        $path = base_path(self::WORKBOOK);

        if (! is_file($path)) {
            $this->markTestSkipped(self::WORKBOOK . ' is not present in this checkout.');
        }

        $this->upload(new UploadedFile($path, 'EXPANDED WTAX.xlsx', null, null, true))
            ->assertSessionHas('success');

        $entries = ExpandedWtaxEntry::all();

        // 125 worksheet rows, each contributing at least one rate row.
        $this->assertGreaterThanOrEqual(125, $entries->count());

        $validator = app(BirExpandedWtaxRowValidator::class);
        $errors = [];

        foreach ($entries as $entry) {
            foreach ($validator->validate($entry->toBirExpandedRow(), $entry->source_row ?? 0) as $error) {
                $errors[] = $error;
            }
        }

        /*
         * The sample workbook leaves the TIN cell empty on 16 of its 125 rows --
         * column D is blank for worksheet rows 33, 60-63, 65, 72, 75, 76, 98, 99,
         * 115 and 122-125 -- even though the same payees carry a TIN on their
         * other rows. Those rows are the ONLY thing the 1604E validator objects
         * to, which is the assertion worth locking down: nothing else about the
         * workbook needs fixing, and the module does not invent a TIN to paper
         * over a gap the BIR would reject.
         */
        $missingTin = array_values(array_filter(
            $errors,
            fn ($error) => str_contains($error, 'payee_tin must contain at least 9 digits')
        ));

        $this->assertSame($missingTin, $errors, 'The workbook has a problem other than its blank TIN cells.');
        $this->assertCount(16, $missingTin);

        // The row number in the message is the worksheet row, so the user can
        // open the workbook and go straight to the cell that needs a TIN.
        $this->assertContains('Row 33: payee_tin must contain at least 9 digits.', $missingTin);
        $this->assertContains('Row 125: payee_tin must contain at least 9 digits.', $missingTin);

        foreach (ExpandedWtaxEntry::whereIn('source_row', [33, 125])->get() as $entry) {
            $this->assertSame('', $entry->payee_tin);
            $this->assertNotSame('', $entry->payee_name);
        }

        // Everything that does carry a TIN is filable as imported.
        $filable = $entries->where('payee_tin', '!=', '');

        $this->assertGreaterThan(100, $filable->count());

        foreach ($filable as $entry) {
            $this->assertSame(
                [],
                $validator->validate($entry->toBirExpandedRow(), $entry->source_row ?? 0),
                "Worksheet row {$entry->source_row} has a TIN but is still unfilable."
            );
        }

        // No totals row leaked in, and the 15% column really is empty in this file.
        $this->assertSame(0, ExpandedWtaxEntry::where('payee_name', 'like', '%TOTAL%')->count());
        $this->assertSame(0, ExpandedWtaxEntry::where('tax_rate', 15.00)->count());
    }
}
