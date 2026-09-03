<?php

namespace Tests\Feature;

use App\Imports\UploadWorkbookTypePreflight;
use App\Models\ImportationEntry;
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
            ['', 'ABC SUPPLIER', 0, 0, 0, 1000, 0, 0, 120, 1120],
        ]);

        $path = storage_path('app/purchase-preflight-test.xlsx');
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'purchase-preflight-test.xlsx', null, null, true);
    }

    private function salesRow(array $overrides = []): SalesVatInput
    {
        return SalesVatInput::create(array_merge([
            'document_no' => 'SI#OLD',
            'document_type' => 'SI',
            'document_date' => '2026-05-01',
            'customer_name' => 'OLD CUSTOMER',
            'gross_amount' => 1120,
            'net_amount' => 1000,
            'output_vat' => 120,
            'taxable_net_of_vat' => 1000,
            'reporting_period' => '2026-05-31',
            'is_adjusted' => false,
        ], $overrides));
    }

    private function purchaseRow(array $overrides = []): VatInput
    {
        return VatInput::create(array_merge([
            'supplier_name' => 'OLD SUPPLIER',
            'tin_number' => '000-330-774-000',
            'vendor_type' => 'company',
            'company_name' => 'OLD SUPPLIER',
            'is_imported' => false,
            'exempt' => 0,
            'zero_rated' => 0,
            'purchase_imported' => 0,
            'purchase_local' => 1000,
            'services' => 0,
            'capital_goods' => 0,
            'other_than_capital_goods' => 1000,
            'taxable_net_of_vat' => 1000,
            'vat_rate' => 12,
            'input_vat' => 120,
            'total_purchases' => 1120,
            'others' => 0,
            'total' => 1120,
            'date_uploaded' => '2026-05-31',
            'is_adjusted' => false,
        ], $overrides));
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

    public function test_sales_reupload_replaces_the_selected_month_without_touching_other_months(): void
    {
        $this->salesRow();
        $this->salesRow([
            'document_no' => 'SI#JUNE',
            'customer_name' => 'JUNE CUSTOMER',
            'reporting_period' => '2026-06-30',
        ]);

        $response = $this->post('/vat-import', [
            'excel_file' => $this->simpleSalesSummaryCsv(),
            'reporting_month' => '2026-05',
            'record_type' => 'sales',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(2, SalesVatInput::count());
        $this->assertDatabaseMissing('sales_vatsinputs', ['document_no' => 'SI#OLD']);
        $this->assertDatabaseHas('sales_vatsinputs', ['document_no' => 'SI#13940']);
        $this->assertDatabaseHas('sales_vatsinputs', ['document_no' => 'SI#JUNE']);
    }

    public function test_failed_sales_preflight_does_not_delete_existing_month(): void
    {
        $this->salesRow();

        $response = $this->post('/vat-import', [
            'excel_file' => $this->salesSummaryCsv(),
            'reporting_month' => '2026-09',
            'record_type' => 'sales',
        ]);

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('sales_vatsinputs', ['document_no' => 'SI#OLD']);
        $this->assertSame(1, SalesVatInput::count());
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

    public function test_purchase_reupload_replaces_selected_month_but_keeps_importation_mirrors_and_other_months(): void
    {
        $this->purchaseRow();
        $this->purchaseRow([
            'supplier_name' => 'JUNE SUPPLIER',
            'company_name' => 'JUNE SUPPLIER',
            'date_uploaded' => '2026-06-30',
        ]);
        $mirror = $this->purchaseRow([
            'supplier_name' => 'IMPORTATION MIRROR',
            'company_name' => 'IMPORTATION MIRROR',
            'is_imported' => true,
        ]);

        ImportationEntry::create([
            'sequence_number' => 1,
            'tax_month' => '2026-05-01',
            'import_entry_no' => 'C-MIRROR',
            'assessment_date' => '2026-05-03',
            'supplier' => 'IMPORTATION MIRROR',
            'importation_date' => '2026-05-02',
            'country' => 'CHINA',
            'total_landed_cost' => 1120,
            'dutiable_value' => 1000,
            'charges' => 120,
            'exempt' => 0,
            'taxable_goods' => 1120,
            'vat_rate' => 12,
            'vat_payable' => 134.40,
            'or_number' => 'OR-MIRROR',
            'payment_date' => '2026-05-04',
            'vat_input_id' => $mirror->id,
        ]);

        $response = $this->post('/vat-import', [
            'excel_file' => $this->purchaseWorkbook(),
            'reporting_month' => '2026-05',
            'record_type' => 'purchase',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('vat_inputs', ['supplier_name' => 'OLD SUPPLIER']);
        $this->assertDatabaseHas('vat_inputs', ['supplier_name' => 'ABC SUPPLIER']);
        $this->assertDatabaseHas('vat_inputs', ['supplier_name' => 'JUNE SUPPLIER']);
        $this->assertDatabaseHas('vat_inputs', ['supplier_name' => 'IMPORTATION MIRROR']);
        $this->assertSame(3, VatInput::count());
    }

    public function test_failed_purchase_preflight_does_not_delete_existing_month(): void
    {
        $this->purchaseRow();

        $response = $this->post('/vat-import', [
            'excel_file' => $this->salesSummaryCsv(),
            'reporting_month' => '2026-05',
            'record_type' => 'purchase',
        ]);

        $response->assertSessionMissing('success');
        $response->assertSessionHas('error');

        $this->assertDatabaseHas('vat_inputs', ['supplier_name' => 'OLD SUPPLIER']);
        $this->assertSame(1, VatInput::count());
    }
}
