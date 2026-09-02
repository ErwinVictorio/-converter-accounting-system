<?php

namespace Tests\Feature;

use App\Imports\UploadWorkbookTypePreflight;
use App\Models\SalesVatInput;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class UploadWorkbookTypePreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function csv(string $name, array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            implode("\r\n", $lines) . "\r\n"
        );
    }

    private function salesSummaryCsv(): UploadedFile
    {
        return $this->csv('sales-summary.csv', [
            ',,,,,,SALES SUMMARY BY DOCUMENT NUMBER',
            ',,,,,,"Period Covered: May 1, 2026 - May 30, 2026"',
            'Document No,Date,Terms,Days,Due Date,Agent,Customer Name,SO/DR/SI,Gross Amount,Discount,Charges,Net Amount,Output VAT,Taxable Net of VAT',
            'SI#13940,05/04/2026,CASH-IBD-BP,0,05/04/2026,FERDIE HUI,SECOND SONS CONSTRUCTION,012396,1120,0,0,1000,120,1000',
        ]);
    }

    private function simpleSalesSummaryCsv(): UploadedFile
    {
        return $this->csv('sales-summary.csv', [
            'Document No,Date,Terms,Days,Due Date,Agent,Customer Name,SO/DR/SI,Gross Amount,Discount,Charges,Net Amount,Output VAT,Taxable Net of VAT',
            'SI#13940,05/04/2026,CASH-IBD-BP,0,05/04/2026,FERDIE HUI,SECOND SONS CONSTRUCTION,012396,1120,0,0,1000,120,1000',
        ]);
    }

    private function salesSummaryWithDocumentTypesCsv(): UploadedFile
    {
        return $this->csv('sales-summary.csv', [
            'Document No,Date,Terms,Days,Due Date,Agent,Customer Name,SO/DR/SI,Gross Amount,Discount,Charges,Net Amount,Output VAT,Taxable Net of VAT',
            'SI#13940,05/04/2026,CASH-IBD-BP,0,05/04/2026,FERDIE HUI,SECOND SONS CONSTRUCTION,012396,1120,0,0,1000,120,1000',
            'CM#00001,05/05/2026,CASH-IBD-BP,0,05/05/2026,FERDIE HUI,SECOND SONS CONSTRUCTION,012397,224,0,0,200,24,200',
            'DM#00001,05/06/2026,CASH-IBD-BP,0,05/06/2026,FERDIE HUI,SECOND SONS CONSTRUCTION,012398,336,0,0,300,36,300',
        ]);
    }

    private function salesSummaryWithOnlyDebitMemosCsv(): UploadedFile
    {
        return $this->csv('sales-summary.csv', [
            'Document No,Date,Terms,Days,Due Date,Agent,Customer Name,SO/DR/SI,Gross Amount,Discount,Charges,Net Amount,Output VAT,Taxable Net of VAT',
            'DM#00001,05/06/2026,CASH-IBD-BP,0,05/06/2026,FERDIE HUI,SECOND SONS CONSTRUCTION,012398,336,0,0,300,36,300',
        ]);
    }

    private function purchaseCsv(): UploadedFile
    {
        return $this->csv('purchase.csv', [
            'Purchase VAT Report',
            'For May 2026',
            'vendor_tin,supplier_name,exempt,zero_rated,purchase_imported,purchase_local,services,others,input_vat,total_purchases',
            '000330774000,ABC SUPPLIER,0,0,0,1000,0,0,120,1120',
        ]);
    }

    private function purchaseWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Purchase VAT Report'],
            ['For May 2026'],
            ['vendor_tin', 'supplier_name', 'exempt', 'zero_rated', 'purchase_imported', 'purchase_local', 'services', 'others', 'input_vat', 'total_purchases'],
            ['000330774000', 'ABC SUPPLIER', 0, 0, 0, 1000, 0, 0, 120, 1120],
        ]);

        $path = storage_path('app/purchase-preflight-test.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'purchase-preflight-test.xlsx', null, null, true);
    }

    public function test_sales_workbook_selected_as_purchase_is_rejected_before_storage(): void
    {
        $response = $this->post('/vat-import', [
            'excel_file' => $this->salesSummaryCsv(),
            'reporting_month' => '2026-05',
            'record_type' => 'purchase',
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('selected file type is Purchase', session('error'));
        $this->assertStringContainsString('workbook appears to be a Sales file', session('error'));
        $this->assertSame(0, VatInput::count());
        $this->assertSame(0, SalesVatInput::count());
    }

    public function test_purchase_workbook_selected_as_sales_is_rejected_before_storage(): void
    {
        $response = $this->post('/vat-import', [
            'excel_file' => $this->purchaseCsv(),
            'reporting_month' => '2026-05',
            'record_type' => 'sales',
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('selected file type is Sales', session('error'));
        $this->assertStringContainsString('workbook appears to be a Purchase file', session('error'));
        $this->assertSame(0, VatInput::count());
        $this->assertSame(0, SalesVatInput::count());
    }

    public function test_sales_workbook_period_must_match_selected_reporting_month(): void
    {
        $response = $this->post('/vat-import', [
            'excel_file' => $this->salesSummaryCsv(),
            'reporting_month' => '2026-09',
            'record_type' => 'sales',
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('workbook period is May 2026', session('error'));
        $this->assertStringContainsString('selected reporting month is September 2026', session('error'));
        $this->assertSame(0, SalesVatInput::count());
    }

    public function test_matching_sales_workbook_type_and_period_still_imports(): void
    {
        $response = $this->post('/vat-import', [
            'excel_file' => $this->simpleSalesSummaryCsv(),
            'reporting_month' => '2026-05',
            'record_type' => 'sales',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(1, SalesVatInput::count());
        $this->assertSame('2026-05-31', SalesVatInput::first()->reporting_period->toDateString());
    }

    public function test_sales_import_stores_si_and_cm_but_skips_dm_with_warning(): void
    {
        $response = $this->post('/vat-import', [
            'excel_file' => $this->salesSummaryWithDocumentTypesCsv(),
            'reporting_month' => '2026-05',
            'record_type' => 'sales',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionHas('warning');

        $this->assertStringContainsString('1 DM row(s) were skipped', session('warning'));
        $this->assertSame(2, SalesVatInput::count());
        $this->assertSame(['CM', 'SI'], SalesVatInput::query()->orderBy('document_type')->pluck('document_type')->all());
        $this->assertDatabaseMissing('sales_vatsinputs', ['document_no' => 'DM#00001']);
    }

    public function test_sales_upload_with_only_dm_rows_is_not_reported_as_normal_success(): void
    {
        $response = $this->post('/vat-import', [
            'excel_file' => $this->salesSummaryWithOnlyDebitMemosCsv(),
            'reporting_month' => '2026-05',
            'record_type' => 'sales',
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertStringContainsString('No importable SI/CM Sales rows were found', session('error'));
        $this->assertSame(0, SalesVatInput::count());
    }

    public function test_matching_purchase_workbook_type_passes_preflight(): void
    {
        $issues = (new UploadWorkbookTypePreflight)->check(
            $this->purchaseWorkbook(),
            'purchase',
            '2026-05-31'
        );

        $this->assertSame([], $issues);
    }
}
