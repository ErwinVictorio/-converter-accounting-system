<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class TemporaryPurchaseSupplierTotalReportTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_routes_require_authentication(): void
    {
        $this->get('/temporary/purchase-supplier-totals')->assertRedirect('/login');
        $this->post('/temporary/purchase-supplier-totals')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_open_the_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/temporary/purchase-supplier-totals')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Temporary/PurchaseSupplierTotals'));
    }

    public function test_it_reads_only_sheet2_even_when_sheet1_is_active_and_downloads_grouped_totals(): void
    {
        $this->actingAs(User::factory()->create());

        $workbook = $this->workbook([
            ['  ACME   SUPPLY  ', 100.10],
            ['acme supply', 20.20],
            ['BETA, INC.', -5.00],
            ['ZERO TRADING', 0],
        ], [
            ['SHOULD NOT APPEAR', 999999],
        ]);

        $response = $this->post('/temporary/purchase-supplier-totals', [
            'excel_file' => $workbook,
        ]);

        $response->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('content-disposition');

        $report = $this->readDownload($response->streamedContent());
        $sheet = $report->getSheetByName('Supplier Totals');

        $this->assertNotNull($sheet);
        $this->assertSame('Sheet2', $sheet->getCell('B3')->getValue());
        $this->assertSame('ACME SUPPLY', $sheet->getCell('B8')->getValue());
        $this->assertSame(2, $sheet->getCell('C8')->getValue());
        $this->assertEqualsWithDelta(120.30, (float) $sheet->getCell('D8')->getValue(), 0.001);
        $this->assertSame('BETA, INC.', $sheet->getCell('B9')->getValue());
        $this->assertSame('ZERO TRADING', $sheet->getCell('B10')->getValue());
        $this->assertSame('GRAND TOTAL', $sheet->getCell('A11')->getValue());
        $this->assertSame(4, $sheet->getCell('C11')->getValue());
        $this->assertEqualsWithDelta(115.30, (float) $sheet->getCell('D11')->getValue(), 0.001);
        $report->disconnectWorksheets();
        $this->assertSame(0, VatInput::count());
        $this->assertSame(0, Supplier::count());
    }

    public function test_it_keeps_punctuation_different_supplier_names_separate(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post('/temporary/purchase-supplier-totals', [
            'excel_file' => $this->workbook([
                ['ABC INC.', 10],
                ['ABC, INC.', 20],
            ]),
        ]);

        $report = $this->readDownload($response->streamedContent());
        $sheet = $report->getActiveSheet();

        $this->assertSame('ABC INC.', $sheet->getCell('B8')->getValue());
        $this->assertSame('ABC, INC.', $sheet->getCell('B9')->getValue());
        $this->assertSame('GRAND TOTAL', $sheet->getCell('A10')->getValue());
        $report->disconnectWorksheets();
    }

    public function test_it_uses_another_sheet_when_sheet2_is_missing_and_detects_shifted_columns(): void
    {
        $this->actingAs(User::factory()->create());

        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Purchases');
        $sheet->setCellValue('A1', 'PURCHASES SUMMARY');
        $sheet->fromArray(['No', 'Supplier Name', 'Particulars', 'Amount'], null, 'A5');
        $sheet->fromArray(['PV-1', 'SHIFTED SUPPLIER', 'Purchase', 125.50], null, 'A6');

        $response = $this->post('/temporary/purchase-supplier-totals', [
            'excel_file' => $this->uploadedWorkbook($workbook),
        ]);

        $response->assertOk();
        $report = $this->readDownload($response->streamedContent());
        $this->assertSame('Purchases', $report->getActiveSheet()->getCell('B3')->getValue());
        $this->assertSame('SHIFTED SUPPLIER', $report->getActiveSheet()->getCell('B8')->getValue());
        $this->assertEqualsWithDelta(125.50, (float) $report->getActiveSheet()->getCell('D8')->getValue(), 0.001);
        $report->disconnectWorksheets();
    }

    public function test_it_rejects_wrong_headings_blank_supplier_and_nonnumeric_amount(): void
    {
        $this->actingAs(User::factory()->create());

        $wrongHeading = $this->workbook([['ACME', 10]], headings: ['No', 'Date', 'Vendor', 'Particulars', 'Value']);
        $this->post('/temporary/purchase-supplier-totals', ['excel_file' => $wrongHeading])
            ->assertSessionHasErrors('excel_file');

        $blankSupplier = $this->workbook([['', 10]]);
        $this->post('/temporary/purchase-supplier-totals', ['excel_file' => $blankSupplier])
            ->assertSessionHasErrors([
                'excel_file' => 'Sheet2 row 4: Supplier Name is required.',
            ]);

        $nonnumeric = $this->workbook([['ACME', 'ten pesos']]);
        $this->post('/temporary/purchase-supplier-totals', ['excel_file' => $nonnumeric])
            ->assertSessionHasErrors([
                'excel_file' => 'Sheet2 row 4: Amount must be numeric.',
            ]);

        $formulaError = $this->workbook([['ACME', '=1/0']]);
        $this->post('/temporary/purchase-supplier-totals', ['excel_file' => $formulaError])
            ->assertSessionHasErrors([
                'excel_file' => 'Sheet2 row 4: Amount must be numeric.',
            ]);
    }

    public function test_it_skips_a_total_footer_instead_of_counting_it_as_a_purchase(): void
    {
        $this->actingAs(User::factory()->create());

        $spreadsheet = $this->baseWorkbook();
        $sheet = $spreadsheet->getSheetByName('Sheet2');
        $sheet->fromArray(['PV-1', '09/15/2026', 'ACME', 'Purchase', 25], null, 'A4');
        $sheet->fromArray([null, null, null, 'TOTAL:', 25], null, 'A5');

        $response = $this->post('/temporary/purchase-supplier-totals', [
            'excel_file' => $this->uploadedWorkbook($spreadsheet),
        ]);

        $report = $this->readDownload($response->streamedContent());
        $this->assertSame(1, $report->getActiveSheet()->getCell('C9')->getValue());
        $this->assertEqualsWithDelta(25, (float) $report->getActiveSheet()->getCell('D9')->getValue(), 0.001);
        $report->disconnectWorksheets();
    }

    public function test_the_supplied_sample_generates_from_sheet2(): void
    {
        $path = base_path('Docs/Temporary/PURCHASES SUMMARY.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('The supplied Purchases Summary workbook is not present.');
        }

        $this->actingAs(User::factory()->create());
        $response = $this->post('/temporary/purchase-supplier-totals', [
            'excel_file' => new UploadedFile($path, 'PURCHASES SUMMARY.xlsx', null, null, true),
        ]);

        $response->assertOk();
        $report = $this->readDownload($response->streamedContent());
        $sheet = $report->getActiveSheet();

        $this->assertSame('Sheet2', $sheet->getCell('B3')->getValue());
        $this->assertSame(1812, $sheet->getCell('C132')->getValue());
        $this->assertEqualsWithDelta(1830486051.13, (float) $sheet->getCell('D132')->getValue(), 0.001);
        $report->disconnectWorksheets();
    }

    public function test_the_shifted_column_stc_sample_generates_successfully(): void
    {
        $path = base_path('Docs/Temporary/PURCHASES SUMMARY (1) - STC.xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('The supplied STC Purchases Summary workbook is not present.');
        }

        $this->actingAs(User::factory()->create());
        $response = $this->post('/temporary/purchase-supplier-totals', [
            'excel_file' => new UploadedFile($path, 'PURCHASES SUMMARY (1) - STC.xlsx', null, null, true),
        ]);

        $response->assertOk();
        $report = $this->readDownload($response->streamedContent());
        $sheet = $report->getActiveSheet();
        $grandTotalRow = $sheet->getHighestDataRow();

        $this->assertSame('Sheet2', $sheet->getCell('B3')->getValue());
        $this->assertSame('GRAND TOTAL', $sheet->getCell('A'.$grandTotalRow)->getValue());
        $this->assertGreaterThan(0, $sheet->getCell('C'.$grandTotalRow)->getValue());
        $report->disconnectWorksheets();
    }

    /**
     * @param  array<int, array{0: string, 1: int|float|string}>  $sheet2Rows
     * @param  array<int, array{0: string, 1: int|float|string}>  $sheet1Rows
     * @param  array<int, string>  $headings
     */
    private function workbook(array $sheet2Rows, array $sheet1Rows = [], array $headings = ['No', 'Date', 'Supplier Name', 'Particulars', 'Amount']): UploadedFile
    {
        $spreadsheet = $this->baseWorkbook($headings);

        foreach ($sheet1Rows as $index => [$supplier, $amount]) {
            $row = $index + 4;
            $spreadsheet->getSheetByName('Sheet1')->fromArray(['PV-S1', '09/15/2026', $supplier, 'Purchase'], null, 'A'.$row);
            $spreadsheet->getSheetByName('Sheet1')->setCellValue('E'.$row, $amount);
        }

        foreach ($sheet2Rows as $index => [$supplier, $amount]) {
            $row = $index + 4;
            $spreadsheet->getSheetByName('Sheet2')->fromArray(['PV-S2', '09/15/2026', $supplier, 'Purchase'], null, 'A'.$row);
            $spreadsheet->getSheetByName('Sheet2')->setCellValue('E'.$row, $amount);
        }

        return $this->uploadedWorkbook($spreadsheet);
    }

    /** @param array<int, string> $headings */
    private function baseWorkbook(array $headings = ['No', 'Date', 'Supplier Name', 'Particulars', 'Amount']): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Sheet1');
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Sheet2');
        $spreadsheet->setActiveSheetIndex(0);

        foreach ([$sheet1, $sheet2] as $sheet) {
            $sheet->setCellValue('A1', 'PURCHASES SUMMARY');
            $sheet->setCellValue('A2', 'Period Covered: September 2026');
            $sheet->fromArray($headings, null, 'A3');
        }

        return $spreadsheet;
    }

    private function uploadedWorkbook(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'supplier-total-').'.xlsx';
        $this->temporaryFiles[] = $path;
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'purchases.xlsx', null, null, true);
    }

    private function readDownload(string $contents): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'supplier-total-report-').'.xlsx';
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $contents);

        return IOFactory::load($path);
    }
}
