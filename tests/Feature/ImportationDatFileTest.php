<?php

namespace Tests\Feature;

use App\Models\ImportationEntry;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportationDatFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The whole app sits behind the "auth" middleware now.
        $this->actingAs(User::factory()->create());
    }

    /**
     * Mirrors row 1 of the reference file: charges are 0 there, so the landed
     * cost equals the dutiable value and the derived taxable goods match.
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'tax_month' => '2026-07',
            'import_entry_no' => 'C2051',
            'assessment_date' => '2026-07-14',
            'supplier' => 'Dao Fortune Co Limited',
            'importation_date' => '2026-06-10',
            'country' => 'China',
            'total_landed_cost' => '8094234.19',
            'dutiable_value' => '8094234.19',
            'exempt' => '0.00',
            'vat_rate' => '12.00',
            'vat_payable' => '971308.10',
            'or_number' => '000',
            'payment_date' => '2026-07-31',
        ], $overrides);
    }

    public function test_importation_dat_downloads_for_the_selected_month(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();
        $this->post('/importation', $this->payload([
            'import_entry_no' => 'C2077',
            'supplier' => 'Sanyve International Trading Co Limited',
            'total_landed_cost' => '11953318.58',
            'dutiable_value' => '11953318.58',
            'vat_payable' => '1434398.23',
        ]))->assertSessionHasNoErrors();

        $response = $this->get('/download-datfile?period=2026-07-31&record_type=importation');

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="008791976I072026.DAT"');

        $lines = explode("\r\n", trim($response->getContent()));
        $this->assertCount(3, $lines); // 1 header + 2 details

        $header = str_getcsv($lines[0]);
        $this->assertCount(18, $header);
        $this->assertSame('H', $header[0]);
        $this->assertSame('I', $header[1]);
        $this->assertSame('20047552.77', $header[10]); // total dutiable value
        $this->assertSame('2405706.33', $header[14]);  // total VAT payable
        $this->assertSame('07/31/2026', $header[16]);
        $this->assertSame('12', $header[17]);

        $detail = str_getcsv($lines[1]);
        $this->assertCount(16, $detail);
        $this->assertSame('C2051', $detail[2]);
        $this->assertSame('07/14/2026', $detail[3]);
        $this->assertSame('DAO FORTUNE CO LIMITED', $detail[4]);
        $this->assertSame('06/10/2026', $detail[5]);
        $this->assertSame('CHINA', $detail[6]);
        $this->assertSame('971308.10', $detail[11]);
        $this->assertSame('000', $detail[12]); // OR number keeps its leading zeros
        $this->assertSame('008791976', $detail[14]);
    }

    public function test_entries_are_ordered_by_sequence_number(): void
    {
        $this->post('/importation', $this->payload(['import_entry_no' => 'C2200']))->assertSessionHasNoErrors();
        $this->post('/importation', $this->payload(['import_entry_no' => 'C2051']))->assertSessionHasNoErrors();

        $lines = explode("\r\n", trim(
            $this->get('/download-datfile?period=2026-07-31&record_type=importation')->getContent()
        ));

        $this->assertSame('C2200', str_getcsv($lines[1])[2]);
        $this->assertSame('C2051', str_getcsv($lines[2])[2]);
    }

    public function test_month_with_no_entries_returns_an_error(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();

        $response = $this->get('/download-datfile?period=2026-09-30&record_type=importation');

        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_an_inconsistent_row_blocks_generation(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();

        // charges and taxable_goods are derived by the form, but VAT is still
        // keyed, so a typo can be saved. The DAT validator must stop it.
        ImportationEntry::query()->firstOrFail()->forceFill([
            'vat_payable' => '123.45',
        ])->save();

        $response = $this->get('/download-datfile?period=2026-07-31&record_type=importation');

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('vat_payable', session('error'));
    }

    public function test_importation_rows_are_excluded_from_the_purchase_dat(): void
    {
        $this->post('/importation', $this->payload())->assertSessionHasNoErrors();

        // The entry did sync into vat_inputs for internal reporting...
        $this->assertSame(1, VatInput::count());
        $this->assertNotNull(ImportationEntry::firstOrFail()->vat_input_id);

        // ...but it must not be reported again in the Purchase schedule.
        $response = $this->get('/download-datfile?period=2026-07-31&record_type=purchase');

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('No VAT input records', session('error'));
    }
}
