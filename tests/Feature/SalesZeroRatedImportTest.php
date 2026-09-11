<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SalesVatInput;
use App\Models\User;
use App\Services\BIR\DatAttachmentReportBuilder;
use App\Services\BIR\SalesSiCmConsolidator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatPackageAssertions;
use Tests\TestCase;

class SalesZeroRatedImportTest extends TestCase
{
    use DatPackageAssertions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Customer::create(['name' => 'ZERO CUSTOMER', 'name_key' => Customer::normalizeName('ZERO CUSTOMER'), 'tin' => '123456789000', 'addr' => 'MAIN STREET', 'city' => 'MANILA']);
    }

    private function upload(array $rows, bool $summary = false, bool $explicitColumn = true)
    {
        $headings = $summary
            ? 'Document No,Date,Terms,Days,Due Date,Agent,Customer Name,SO/DR/SI,Gross Amount,Discount,Charges,Net Amount,VAT,Net of VAT,Zero Rated Sales'
            : 'CLIENT TIN,Company Name,Last Name,First Name,Middle Name,Address1,Address2,Exempt Sales,Zero Rated Sales,Taxable Sales,Total Sales,Output VAT,Net Amount,Gross Amount';

        if ($summary && ! $explicitColumn) {
            $headings = str_replace(',Zero Rated Sales', '', $headings);
        }

        return $this->post('/vat-import', [
            'excel_file' => UploadedFile::fake()->createWithContent('sales.csv', implode("\r\n", [$headings, ...$rows])."\r\n"),
            'reporting_month' => '2026-07',
            'record_type' => 'sales',
        ]);
    }

    public static function pureRows(): array
    {
        return [
            'BIR erroneous VAT' => [false, '123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,0,1000,0,1000,120,1000,1000'],
            'Summary erroneous VAT' => [true, 'SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,120,0,1000'],
        ];
    }

    #[DataProvider('pureRows')]
    public function test_pure_zero_rated_upload_reaches_dat_and_attachment_with_zero_vat(bool $summary, string $row): void
    {
        $this->upload([$row], $summary)->assertSessionHas('success');
        $sale = SalesVatInput::sole();
        $this->assertSame(! $summary, $sale->s_zero_rated);
        $this->assertEquals(1000, $sale->zero_rated_sales);
        $this->assertEquals(0, $sale->taxable_net_of_vat);
        $this->assertEquals(0, $sale->output_vat);
        $this->assertEquals(1000, $sale->net_amount);

        $this->get('/records/sales?period=2026-07')->assertInertia(fn (Assert $page) => $page
            ->component('Records/SalesRecords')
            ->where('salesVatInputs.data.0.zero_rated_sales', 1000)
            ->where('salesVatInputs.data.0.taxable_net_of_vat', 0)
            ->where('salesVatInputs.data.0.output_vat', 0));

        $dat = $this->datFromPackage($this->get('/download-datfile?period=2026-07-31&record_type=sales'), '008791976S072026.DAT');
        [$header, $detail] = array_map('str_getcsv', explode("\r\n", trim($dat)));
        $this->assertCount(17, $header);
        $this->assertCount(15, $detail);
        $this->assertSame('0.00', $header[13]);
        $this->assertSame('1000.00', $detail[10]);
        $this->assertSame('0', $detail[11]);
        $this->assertSame('0', $detail[12]);
        $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $dat));

        $groups = app(SalesSiCmConsolidator::class)->consolidate(SalesVatInput::all());
        $report = app(DatAttachmentReportBuilder::class)->build('sales', $groups, [], Carbon::parse('2026-07-31'));
        $this->assertSame(['1000.00', '0.00', '1000.00', '0.00', '0.00', '0.00'], array_slice($report['rows'][0], 4));
    }

    public function test_summary_zero_rated_and_taxable_si_cm_net_only_taxable_vat(): void
    {
        $this->upload([
            'SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,120,0,1000',
            'CM#1,07/01/2026,,,,,ZERO CUSTOMER,,-200,0,0,-200,-24,0,-200',
            'SI#2,07/01/2026,,,,,ZERO CUSTOMER,,1120,0,0,1120,120,1000,0',
            'CM#2,07/01/2026,,,,,ZERO CUSTOMER,,224,0,0,224,24,200,0',
            'DM#1,07/01/2026,,,,,ZERO CUSTOMER,,999,0,0,999,0,0,999',
        ], true)->assertSessionHas('success')->assertSessionHas('warning');
        $this->assertSame(4, SalesVatInput::count());
        $group = app(SalesSiCmConsolidator::class)->consolidate(SalesVatInput::all())->sole();
        $this->assertEquals(800, $group['zero_rated_sales']);
        $this->assertEquals(800, $group['taxable_sales']);
        $this->assertEquals(96, $group['output_vat']);
    }

    public function test_mixed_bir_row_preserves_taxable_portion(): void
    {
        $this->upload(['123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,0,500,1000,1500,120,1620,1620'])->assertSessionHas('success');
        $this->assertFalse(SalesVatInput::sole()->s_zero_rated);
        $group = app(SalesSiCmConsolidator::class)->consolidate(SalesVatInput::all())->sole();
        $this->assertEquals(500, $group['zero_rated_sales']);
        $this->assertEquals(1000, $group['taxable_sales']);
        $this->assertEquals(120, $group['output_vat']);
    }

    public function test_summary_formula_amounts_match_preflight_and_saved_rows(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Document No', 'Date', 'Terms', 'Days', 'Due Date', 'Agent', 'Customer Name', 'SO/DR/SI', 'Gross Amount', 'Discount', 'Charges', 'Net Amount', 'VAT', 'Net of VAT', 'Zero Rated Sales'],
            ['SI#FORMULA', '07/01/2026', '', '', '', '', 'ZERO CUSTOMER', '', 1620, 0, 0, '=I2', 120, 1000, '=L2-M2-N2'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'sales-zero-');
        try {
            (new Xlsx($book))->save($path);
            $this->post('/vat-import', [
                'excel_file' => new UploadedFile($path, 'sales.xlsx', null, null, true),
                'reporting_month' => '2026-07',
                'record_type' => 'sales',
            ])->assertSessionHas('success');
            $this->assertEquals(500, SalesVatInput::sole()->zero_rated_sales);
            $this->assertEquals(1620, SalesVatInput::sole()->net_amount);
            $this->assertEquals(120, SalesVatInput::sole()->output_vat);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public static function invalidRows(): array
    {
        return [
            'bucket only missing net' => [false, '123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,0,1000,0,0,0,0,0'],
            'exempt bucket only missing net' => [false, '123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,1000,0,0,0,0,0,0'],
            'conflicting pure' => [false, '123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,0,1000,0,1000,120,1120,1120'],
            'conflicting mixed' => [false, '123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,0,500,1000,1500,120,1120,1120'],
            'invalid amount' => [false, '123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,0,oops,0,0,0,0,0'],
            'invalid TIN' => [false, '000000000,UNMATCHED CUSTOMER,,,,MAIN STREET,MANILA,0,1000,0,1000,0,1000,1000'],
            'summary conflict' => [true, 'SI#2,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,120,1000,1000'],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_preflight_rejects_conflicts_before_replacing_month(bool $summary, string $row): void
    {
        $this->upload(['123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,0,1000,0,1000,0,1000,1000'])->assertSessionHas('success');
        $before = SalesVatInput::sole()->getAttributes();
        $response = $this->upload([$row], $summary)->assertSessionHas('uploadIssueDialog');
        $this->assertNotEmpty($response->getSession()->get('uploadIssueDialog.issues'));
        $this->assertSame($before, SalesVatInput::sole()->getAttributes());
    }

    public function test_bir_exempt_stays_exempt_and_summary_zero_vat_is_zero_rated(): void
    {
        $this->upload(['123456789,ZERO CUSTOMER,,,,MAIN STREET,MANILA,1000,0,0,1000,0,1000,1000'])->assertSessionHas('success');
        $this->assertEquals(0, SalesVatInput::sole()->zero_rated_sales);
        $this->assertEquals(1000, SalesVatInput::sole()->exempt_sales);
        $this->assertFalse(SalesVatInput::sole()->s_zero_rated);
        $this->upload(['SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,0,1000,'], true)->assertSessionHas('success');
        $this->assertEquals(1000, SalesVatInput::sole()->zero_rated_sales);
        $this->assertTrue(SalesVatInput::sole()->s_zero_rated);
    }

    public static function vatCells(): array
    {
        return [[''], ['0'], ['0.00'], ['   ']];
    }

    #[DataProvider('vatCells')]
    public function test_original_summary_columns_classify_zero_vat_through_dat(string $vat): void
    {
        $this->upload(["SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,{$vat},1000"], true, false)->assertSessionHas('success');
        $sale = SalesVatInput::sole();
        $this->assertTrue($sale->s_zero_rated);
        $this->assertEquals(1000, $sale->zero_rated_sales);
        $this->assertEquals(0, $sale->taxable_net_of_vat);
        $this->assertEquals(0, $sale->output_vat);
        $dat = $this->datFromPackage($this->get('/download-datfile?period=2026-07-31&record_type=sales'), '008791976S072026.DAT');
        [$header, $detail] = array_map('str_getcsv', explode("\r\n", trim($dat)));
        $this->assertSame('0.00', $header[13]);
        $this->assertSame('1000.00', $detail[10]);
        $this->assertSame('0', $detail[12]);
        $this->get('/records/sales?period=2026-07')->assertInertia(fn (Assert $page) => $page
            ->where('salesVatInputs.data.0.output_vat', 0));
    }

    public function test_reupload_changes_flag_and_mixed_customer_keeps_taxable_vat(): void
    {
        $this->upload(['SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,,1000'], true, false)->assertSessionHas('success');
        $this->assertTrue(SalesVatInput::sole()->s_zero_rated);
        $this->upload([
            'SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1120,0,0,1120,120,1000',
            'SI#2,07/01/2026,,,,,ZERO CUSTOMER,,500,0,0,500,,500',
            'CM#2,07/01/2026,,,,,ZERO CUSTOMER,,-200,0,0,-200,0,-200',
        ], true, false)->assertSessionHas('success');
        $this->assertFalse(SalesVatInput::where('document_no', 'SI#1')->sole()->s_zero_rated);
        $group = app(SalesSiCmConsolidator::class)->consolidate(SalesVatInput::all())->sole();
        $this->assertEquals(300, $group['zero_rated_sales']);
        $this->assertEquals(1000, $group['taxable_sales']);
        $this->assertEquals(120, $group['output_vat']);
    }

    public static function invalidSummaryRows(): array
    {
        return [
            ['SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,oops,1000'],
            ['SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,#DIV/0!,1000'],
            ['SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,,,1000'],
            ['SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,,oops'],
            ['SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,0,1000,500'],
        ];
    }

    #[DataProvider('invalidSummaryRows')]
    public function test_invalid_summary_does_not_replace_existing_rows(string $row): void
    {
        $this->upload(['SI#1,07/01/2026,,,,,ZERO CUSTOMER,,1000,0,0,1000,,1000'], true)->assertSessionHas('success');
        $before = SalesVatInput::sole()->getAttributes();
        $this->upload([$row], true)->assertSessionHas('uploadIssueDialog');
        $this->assertSame($before, SalesVatInput::sole()->getAttributes());
    }

    public static function zeroVatFormulas(): array
    {
        return [['=0'], ['=""']];
    }

    #[DataProvider('zeroVatFormulas')]
    public function test_zero_vat_formula_without_extra_column(string $formula): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Document No', 'Date', 'Terms', 'Days', 'Due Date', 'Agent', 'Customer Name', 'SO/DR/SI', 'Gross Amount', 'Discount', 'Charges', 'Net Amount', 'VAT', 'Net of VAT'],
            ['SI#FORMULA', '07/01/2026', '', '', '', '', 'ZERO CUSTOMER', '', 1000, 0, 0, '=I2', $formula, '=L2'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'sales-zero-');
        try {
            (new Xlsx($book))->save($path);
            $this->post('/vat-import', [
                'excel_file' => new UploadedFile($path, 'sales.xlsx', null, null, true),
                'reporting_month' => '2026-07', 'record_type' => 'sales',
            ])->assertSessionHas('success');
            $this->assertTrue(SalesVatInput::sole()->s_zero_rated);
            $this->assertEquals(1000, SalesVatInput::sole()->zero_rated_sales);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_migration_default_and_rollback_preserve_sales_rows(): void
    {
        $sale = SalesVatInput::create(['document_no' => 'LEGACY', 'customer_name' => 'ZERO CUSTOMER', 'reporting_period' => '2026-07-31', 'net_amount' => 1000]);
        $this->assertFalse($sale->fresh()->s_zero_rated);
        $migration = require database_path('migrations/2026_09_11_000000_add_s_zero_rated_to_sales_vatsinputs_table.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('sales_vatsinputs', 's_zero_rated'));
        $this->assertEquals(1000, $sale->fresh()->net_amount);
        $migration->up();
        $this->assertFalse($sale->fresh()->s_zero_rated);
    }
}
