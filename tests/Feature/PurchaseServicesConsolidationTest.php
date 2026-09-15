<?php

namespace Tests\Feature;

use App\Imports\UploadBirInfoPreflight;
use App\Imports\VatInputImport;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PurchaseServicesConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '2026-07-31';

    /** @var string[] */
    private array $workbookPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        foreach ($this->workbookPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_matching_rows_sum_all_raw_local_vat_buckets_before_one_visible_total_calculation(): void
    {
        Supplier::create([
            'name' => 'A-ZINC INDUSTRIAL GALVANIZING PHILIPPINES',
            'tin' => '236-791-864-000',
            'addr' => 'INDUSTRIAL ROAD',
            'city' => 'MANILA',
        ]);

        $path = $this->vatBucketWorkbook([
            ['PV#1', '07/01/2026', 'A-ZINC INDUSTRIAL GALVANIZING PHILIPPINES', '236-791-864-000', 'SI#1', '', 100.00, 100.00, 10.00, 210.00],
            ['PV#2', '07/02/2026', 'A-ZINC INDUSTRIAL GALVANIZING PHILIPPINES', '236-791-864-000', 'SI#2', '', 200.00, 200.00, 20.00, 420.00],
            ['PV#3', '07/03/2026', 'A-ZINC INDUSTRIAL GALVANIZING PHILIPPINES', '236-791-864-000', 'SI#3', '', 300.00, 300.00, 30.00, 630.00],
            ['PV#4', '07/04/2026', 'A-ZINC INDUSTRIAL GALVANIZING PHILIPPINES', '236-791-864-000', 'SI#4', '', '', 377.39, '', 377.39],
        ]);

        Excel::import(new VatInputImport(self::PERIOD), $path);

        $record = VatInput::query()->sole();

        $this->assertTrue($record->uses_vat_bucket_amounts);
        $this->assertSame('600.00', $record->purchase_local_vat_amount);
        $this->assertSame('977.39', $record->services_vat_amount);
        $this->assertSame('60.00', $record->others_vat_amount);
        $this->assertSame('5000.00', $record->purchase_local);
        $this->assertSame('8144.92', $record->services);
        $this->assertSame('500.00', $record->others);
        $this->assertSame('1637.39', $record->input_vat);

        $this->get('/records/purchases?period=2026-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('vatInputs.data', 1)
                ->where('vatInputs.data.0.display_purchase_local_amount', 600)
                ->where('vatInputs.data.0.display_services_amount', 977.39)
                ->where('vatInputs.data.0.display_others_amount', 60)
                ->where('vatInputs.data.0.display_calculated_total', 13644.92));

        $this->getJson("/view-info/purchase/{$record->id}")
            ->assertOk()
            ->assertJsonPath('sections.1.fields.4.value', 600)
            ->assertJsonPath('sections.1.fields.5.value', 977.39)
            ->assertJsonPath('sections.1.fields.8.value', 60)
            ->assertJsonPath('sections.1.fields.14.value', 13644.92);
    }

    public function test_conflicting_tins_in_one_supplier_group_are_rejected_before_consolidation(): void
    {
        Supplier::create([
            'name' => 'CONFLICTING SUPPLIER',
            'tin' => '111-111-111-000',
            'addr' => 'FIRST ROAD',
            'city' => 'MANILA',
        ]);
        Supplier::create([
            'name' => 'CONFLICTING SUPPLIER',
            'tin' => '222-222-222-000',
            'addr' => 'SECOND ROAD',
            'city' => 'MANILA',
        ]);

        $path = $this->vatBucketWorkbook([
            ['PV#1', '07/01/2026', 'CONFLICTING SUPPLIER', '111-111-111-000', 'SI#1', '', '', 100.00, '', 100.00],
            ['PV#2', '07/02/2026', 'CONFLICTING SUPPLIER', '222-222-222-000', 'SI#2', '', '', 200.00, '', 200.00],
        ]);

        $issues = (new UploadBirInfoPreflight)->checkPurchase($path, self::PERIOD);
        $consolidationIssues = array_values(array_filter(
            $issues,
            fn (array $issue) => $issue['field'] === 'consolidation'
        ));

        $this->assertCount(2, $consolidationIssues);
        $this->assertSame([4, 5], array_column($consolidationIssues, 'row'));
        $this->assertStringContainsString('conflicting base TINs', $consolidationIssues[0]['problem']);
        $this->assertSame(0, VatInput::count());
    }

    public function test_explicit_taxable_base_rows_are_not_divided_again_for_display(): void
    {
        VatInput::create([
            'supplier_name' => 'EXPLICIT BASE SUPPLIER',
            'tin_number' => '333-333-333-000',
            'vendor_type' => 'company',
            'company_name' => 'EXPLICIT BASE SUPPLIER',
            'is_imported' => false,
            'uses_vat_bucket_amounts' => false,
            'exempt' => 0,
            'zero_rated' => 0,
            'purchase_imported' => 0,
            'purchase_local' => 1000,
            'services' => 200,
            'capital_goods' => 0,
            'other_than_capital_goods' => 1050,
            'taxable_net_of_vat' => 1250,
            'vat_rate' => 12,
            'input_vat' => 150,
            'total_purchases' => 1250,
            'others' => 50,
            'total' => 1250,
            'date_uploaded' => self::PERIOD,
            'is_adjusted' => false,
        ]);

        $this->get('/records/purchases?period=2026-07')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('vatInputs.data.0.display_purchase_local_amount', 1000)
                ->where('vatInputs.data.0.display_services_amount', 200)
                ->where('vatInputs.data.0.display_others_amount', 50)
                ->where('vatInputs.data.0.display_calculated_total', 1250)
                ->where('vatInputs.data.0.display_amounts_inferred', false));
    }

    public function test_mixed_vat_bucket_and_taxable_base_rows_are_rejected_before_consolidation(): void
    {
        Supplier::create([
            'name' => 'MIXED FORMAT SUPPLIER',
            'tin' => '444-444-444-000',
            'addr' => 'FORMAT ROAD',
            'city' => 'MANILA',
        ]);

        $path = $this->workbook(
            ['No', 'Date', 'Supplier Name', 'TIN', 'Reference', 'PurchaseImported', 'PurchaseLocal', 'Services', 'Others', 'Input VAT', 'TOTAL'],
            [
                ['PV#1', '07/01/2026', 'MIXED FORMAT SUPPLIER', '444-444-444-000', 'SI#1', '', '', 100.00, '', '', 100.00],
                ['PV#2', '07/02/2026', 'MIXED FORMAT SUPPLIER', '444-444-444-000', 'SI#2', '', '', 200.00, '', 24.00, 200.00],
            ]
        );

        $issues = (new UploadBirInfoPreflight)->checkPurchase($path, self::PERIOD);
        $consolidationIssues = array_values(array_filter(
            $issues,
            fn (array $issue) => $issue['field'] === 'consolidation'
        ));

        $this->assertCount(2, $consolidationIssues);
        $this->assertStringContainsString('VAT-bucket and taxable-base', $consolidationIssues[0]['problem']);
        $this->assertSame(0, VatInput::count());
    }

    private function vatBucketWorkbook(array $rows): string
    {
        return $this->workbook(
            ['No', 'Date', 'Supplier Name', 'TIN', 'Reference', 'PurchaseImported', 'PurchaseLocal', 'Services', 'Others', 'TOTAL'],
            $rows
        );
    }

    private function workbook(array $headings, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['VAT INPUT REPORT'],
            ['Period Covered: July 1, 2026 - July 31, 2026'],
            $headings,
            ...$rows,
        ]);

        $path = storage_path('app/purchase-services-consolidation-'.uniqid().'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $this->workbookPaths[] = $path;

        return $path;
    }
}
