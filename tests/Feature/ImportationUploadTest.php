<?php

namespace Tests\Feature;

use App\Models\ImportationEntry;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ImportationUploadTest extends TestCase
{
    use RefreshDatabase;

    private const WORKBOOK = 'Docs/Importaion/Importation_Upload_Template_Updated.xlsx';

    private const HEADINGS = 'Tax Month,Import Entry No.,Name of Seller,Assessment / Release Date,'
        .'Date of Importation,Country of Origin,VAT Rate,Total Landed Cost,Dutiable Value,'
        .'Exempt,OR Number,Date of VAT Payment';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function workbook(): UploadedFile
    {
        $path = base_path(self::WORKBOOK);

        if (! is_file($path)) {
            $this->markTestSkipped(self::WORKBOOK.' is not present in this checkout.');
        }

        return new UploadedFile($path, 'Importation_Upload_Template_Updated.xlsx', null, null, true);
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    private function csv(array $rows, ?string $headings = null): UploadedFile
    {
        $lines = [$headings ?? self::HEADINGS];

        foreach ($rows as $row) {
            $lines[] = implode(',', $row);
        }

        return UploadedFile::fake()->createWithContent(
            'importation-upload.csv',
            implode("\r\n", $lines)."\r\n"
        );
    }

    /**
     * @param  array<int, string>  $overrides
     * @return array<int, string>
     */
    private function row(array $overrides = []): array
    {
        return array_replace([
            'July 2026',
            'C-12345',
            'ABC Global Trading Co.',
            '07/08/2026',
            '07/05/2026',
            'CHINA',
            '0.12',
            '250000',
            '200000',
            '0',
            'OR-2026-00125',
            '07/10/2026',
        ], $overrides);
    }

    private function upload(UploadedFile $file): TestResponse
    {
        return $this->post('/importation/upload', [
            'excel_file' => $file,
        ]);
    }

    public function test_user_can_download_the_importation_upload_template(): void
    {
        $response = $this->get('/importation/template');

        $response->assertOk();
        $response->assertHeader(
            'content-disposition',
            'attachment; filename=Importation_Upload_Template_Updated.xlsx'
        );
    }

    public function test_uploading_the_final_template_creates_entries_and_computed_amounts(): void
    {
        $response = $this->upload($this->workbook());

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $entries = ImportationEntry::orderBy('sequence_number')->get();

        $this->assertCount(3, $entries);
        $this->assertSame(3, VatInput::count());

        $first = $entries[0];
        $this->assertSame('C-12345', $first->import_entry_no);
        $this->assertSame('ABC GLOBAL TRADING CO.', $first->supplier);
        $this->assertSame('2026-07-01', $first->tax_month->toDateString());
        $this->assertEqualsWithDelta(250000.00, (float) $first->total_landed_cost, 0.001);
        $this->assertEqualsWithDelta(200000.00, (float) $first->dutiable_value, 0.001);
        $this->assertEqualsWithDelta(50000.00, (float) $first->charges, 0.001);
        $this->assertEqualsWithDelta(250000.00, (float) $first->taxable_goods, 0.001);
        $this->assertEqualsWithDelta(12.00, (float) $first->vat_rate, 0.001);
        $this->assertEqualsWithDelta(30000.00, (float) $first->vat_payable, 0.001);
        $this->assertSame('OR-2026-00125', $first->or_number);

        $second = $entries[1];
        $this->assertEqualsWithDelta(30000.00, (float) $second->charges, 0.001);
        $this->assertEqualsWithDelta(160000.00, (float) $second->taxable_goods, 0.001);
        $this->assertEqualsWithDelta(19200.00, (float) $second->vat_payable, 0.001);

        $third = $entries[2];
        $this->assertEqualsWithDelta(50000.00, (float) $third->charges, 0.001);
        $this->assertEqualsWithDelta(270000.00, (float) $third->taxable_goods, 0.001);
        $this->assertEqualsWithDelta(32400.00, (float) $third->vat_payable, 0.001);
    }

    public function test_upload_rejects_duplicate_entries_inside_the_file(): void
    {
        $response = $this->upload($this->csv([
            $this->row(),
            $this->row([2 => 'Pacific Parts Ltd.']),
        ]));

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('Row 3:', session('error'));
        $this->assertStringContainsString('is repeated for the same tax month', session('error'));
        $this->assertSame(0, ImportationEntry::count());
        $this->assertSame(0, VatInput::count());
    }

    public function test_upload_replaces_existing_month_entries_and_their_synced_rows(): void
    {
        $this->upload($this->csv([
            $this->row([2 => 'Old Supplier']),
        ]))->assertSessionHas('success');

        $oldEntry = ImportationEntry::firstOrFail();
        $oldVatInputId = $oldEntry->vat_input_id;

        $response = $this->upload($this->csv([
            $this->row([2 => 'Corrected Supplier', 7 => '300000', 8 => '210000']),
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(1, ImportationEntry::count());
        $this->assertSame(1, VatInput::count());
        $this->assertDatabaseMissing('vat_inputs', ['id' => $oldVatInputId]);

        $entry = ImportationEntry::firstOrFail();
        $this->assertSame('CORRECTED SUPPLIER', $entry->supplier);
        $this->assertSame('C-12345', $entry->import_entry_no);
        $this->assertSame(1, $entry->sequence_number);
        $this->assertEqualsWithDelta(300000.00, (float) $entry->total_landed_cost, 0.001);
        $this->assertEqualsWithDelta(90000.00, (float) $entry->charges, 0.001);
        $this->assertSame('CORRECTED SUPPLIER', $entry->vatInput->supplier_name);
    }

    public function test_upload_replaces_only_months_present_in_the_file(): void
    {
        $this->upload($this->csv([
            $this->row(),
            $this->row([0 => 'August 2026', 1 => 'C-88888', 2 => 'August Supplier']),
        ]))->assertSessionHas('success');

        $response = $this->upload($this->csv([
            $this->row([2 => 'July Replacement']),
        ]));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(2, ImportationEntry::count());
        $this->assertSame(2, VatInput::count());
        $this->assertDatabaseHas('importation_entries', [
            'tax_month' => '2026-07-01',
            'supplier' => 'JULY REPLACEMENT',
        ]);
        $this->assertDatabaseHas('importation_entries', [
            'tax_month' => '2026-08-01',
            'supplier' => 'AUGUST SUPPLIER',
        ]);
    }

    public function test_upload_rejects_missing_required_headers(): void
    {
        $headings = str_replace(',Dutiable Value', '', self::HEADINGS);
        $row = array_values(array_diff_key($this->row(), [8 => null]));

        $response = $this->upload($this->csv([$row], $headings));

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('missing the column Dutiable Value', session('error'));
        $this->assertSame(0, ImportationEntry::count());
    }

    public function test_upload_reports_invalid_row_numbers(): void
    {
        $response = $this->upload($this->csv([
            $this->row([8 => '300000']),
        ]));

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('Row 2:', session('error'));
        $this->assertStringContainsString('Dutiable value cannot be more than the total landed cost', session('error'));
        $this->assertSame(0, ImportationEntry::count());
    }

    public function test_failed_importation_upload_does_not_delete_existing_month(): void
    {
        $this->upload($this->csv([
            $this->row([2 => 'Existing Supplier']),
        ]))->assertSessionHas('success');

        $response = $this->upload($this->csv([
            $this->row([2 => 'Broken Supplier', 8 => '300000']),
        ]));

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertSame(1, ImportationEntry::count());
        $this->assertSame(1, VatInput::count());
        $this->assertDatabaseHas('importation_entries', ['supplier' => 'EXISTING SUPPLIER']);
    }

    public function test_uploaded_entries_are_included_in_importation_dat_and_excluded_from_purchase_dat(): void
    {
        $this->upload($this->workbook())->assertSessionHas('success');

        $purchase = $this->get('/download-datfile?period=2026-07-31&record_type=purchase');
        $purchase->assertRedirect();
        $purchase->assertSessionHas('error');

        $importation = $this->get('/download-datfile?period=2026-07-31&record_type=importation');
        $importation->assertOk();
        $importation->assertHeader('content-disposition', 'attachment; filename="008791976I072026.DAT"');

        $lines = explode("\r\n", trim($importation->getContent()));
        $this->assertCount(4, $lines);
        $this->assertSame('C-12345', str_getcsv($lines[1])[2]);
        $this->assertSame('C-12346', str_getcsv($lines[2])[2]);
        $this->assertSame('C-12347', str_getcsv($lines[3])[2]);
    }
}
