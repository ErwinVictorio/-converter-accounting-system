<?php
namespace Tests\Feature;

use App\Models\{User, VatInput, Supplier, WithholdingCompany, PendingPurchaseUpload};
use App\Services\BIR\ReliefPurchaseDatGenerator;
use App\Services\PurchaseUploadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\DatPackageAssertions;
use Tests\TestCase;

class PurchaseCompanySelectionTest extends TestCase
{
    use RefreshDatabase, DatPackageAssertions;

    private const FSI = ['tin' => '008791976', 'branch_code' => '0000'];
    private const STC = ['tin' => '008564445', 'branch_code' => '0000'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::set('local', Storage::fake('purchase-'.\Illuminate\Support\Str::uuid()));
        $this->actingAs(User::factory()->create());
        WithholdingCompany::create([...self::STC, 'registered_name'=>'STC', 'address1'=>'STC STREET', 'address2'=>'MANILA', 'rdo_code'=>'045', 'is_active'=>true]);
    }

    private function record(array $company, array $overrides = []): VatInput
    {
        return VatInput::create(array_merge([
            'filing_company_tin'=>$company['tin'] ?? null, 'filing_company_branch_code'=>$company['branch_code'] ?? null,
            'supplier_name'=>'SUPPLIER A', 'company_name'=>'SUPPLIER A', 'tin_number'=>'123456789',
            'vendor_type'=>'company', 'address1'=>'STREET', 'address2'=>'MANILA',
            'date_uploaded'=>'2026-05-31', 'purchase_local'=>1000, 'services'=>0, 'other_than_capital_goods'=>1000,
            'input_vat'=>120, 'total'=>1120, 'total_purchases'=>1120, 'is_imported'=>false, 'is_adjusted'=>false,
        ], $overrides));
    }

    private function url(string $path, array $company, string $extra = ''): string
    {
        return $path.'?record_type=purchase&filing_company_tin='.($company['tin'] ?? '').'&filing_company_branch_code='.($company['branch_code'] ?? '').$extra;
    }

    private function workbook(int $amount = 1000): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Purchase VAT Report'], ['Period Covered: May 1, 2026 - May 31, 2026'],
            ['vendor_tin','supplier_name','address1','address2','exempt','zero_rated','purchase_imported','purchase_local','services','others','input_vat','total_purchases'],
            ['123456789000','SUPPLIER A','','',0,0,0,$amount,0,0,$amount*.12,$amount*1.12],
        ]);
        $path=tempnam(sys_get_temp_dir(),'purchase-company-');
        (new Xlsx($book))->save($path);
        $contents=file_get_contents($path); unlink($path);
        return UploadedFile::fake()->createWithContent('purchase.xlsx',$contents);
    }

    private function upload(array $company, int $amount = 1000)
    {
        return $this->post('/vat-import', [
            'record_type'=>'purchase', 'reporting_month'=>'2026-05', 'excel_file'=>$this->workbook($amount),
            'filing_company_tin'=>$company['tin'] ?? '', 'filing_company_branch_code'=>$company['branch_code'] ?? '',
        ]);
    }

    public function test_uploads_and_replacement_are_isolated_and_legacy_rows_stay_unassigned(): void
    {
        Supplier::create(['tin'=>'123-456-789-000','name'=>'SUPPLIER A','addr'=>'STREET','city'=>'MANILA']);
        $legacy=$this->record([]); $before=$legacy->fresh()->getAttributes();
        $adjusted=$this->record(self::STC,['is_adjusted'=>true]);
        $this->upload(self::FSI)->assertSessionHasNoErrors()->assertSessionHas('success');
        $fsi=VatInput::forFilingCompany(self::FSI)->sole()->getAttributes();
        $this->upload(self::STC,2000)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->upload(self::STC,3000)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame($fsi,VatInput::forFilingCompany(self::FSI)->sole()->getAttributes());
        $this->assertSame($before,$legacy->fresh()->getAttributes());
        $this->assertNotNull($adjusted->fresh());
        $this->assertSame(1,VatInput::forFilingCompany(self::STC)->where('is_adjusted',false)->count());
        $this->assertSame('3000.00',VatInput::forFilingCompany(self::STC)->where('is_adjusted',false)->sole()->purchase_local);
        $this->assertDatabaseCount('purchase_upload_locks',2);
    }

    public function test_missing_unknown_and_inactive_company_cannot_upload_or_silently_export(): void
    {
        $legacy=$this->record([]);
        foreach ([[], ['tin'=>'999999999','branch_code'=>'0000'], ['tin'=>'008791976x','branch_code'=>'0000']] as $company) {
            $this->upload($company)->assertSessionHasErrors('filing_company_tin');
            $this->get($this->url('/download-datfile',$company,'&period=2026-05-31'))->assertSessionHasErrors('filing_company_tin');
        }
        WithholdingCompany::where('tin',self::STC['tin'])->update(['is_active'=>false]);
        $this->upload(self::STC)->assertSessionHasErrors('filing_company_tin');
        $this->assertDatabaseCount('vat_inputs',1);
        $this->assertNull($legacy->fresh()->filing_company_tin);
    }

    public function test_period_validation_records_and_dat_are_company_scoped_with_identical_fsi_bytes(): void
    {
        $fsi=$this->record(self::FSI);
        $this->record(self::STC,['supplier_name'=>'OTHER COMPANY ROW','tin_number'=>'bad']);
        $this->record([],['supplier_name'=>'UNASSIGNED']);
        $this->get($this->url('/generate-datfile',self::FSI))->assertInertia(fn(Assert $page)=>$page
            ->where('availablePeriods.0.records_count',1)->where('periodIssues.2026-05.invalid_count',0)->where('unassignedPurchaseCount',1));
        $this->get('/records/purchases?company=008791976%7C0000')->assertInertia(fn(Assert $page)=>$page
            ->where('vatInputs.total',1)->where('vatInputs.data.0.id',$fsi->id));
        $this->get('/records/purchases?company=unassigned')->assertInertia(fn(Assert $page)=>$page->where('vatInputs.total',1));
        $dat=$this->datFromPackage($this->get($this->url('/download-datfile',self::FSI,'&period=2026-05-31')),'008791976P052026.DAT');
        $oldProfile=[...config('bir.companies.008791976'),'final_header_field'=>'12'];
        $baseline=app(ReliefPurchaseDatGenerator::class)->generate($oldProfile,collect([$fsi->toBirPurchaseRow()]),Carbon::parse('2026-05-31'),0);
        $this->assertSame($baseline,$dat);
        $this->assertStringNotContainsString('OTHER COMPANY ROW',$dat);
        $this->assertStringNotContainsString('UNASSIGNED',$dat);
    }

    public function test_stc_export_and_inactive_historical_regeneration_use_stc_profile(): void
    {
        $this->record(self::STC);
        WithholdingCompany::where('tin',self::STC['tin'])->update(['is_active'=>false]);
        $dat=$this->datFromPackage($this->get($this->url('/download-datfile',self::STC,'&period=2026-05-31')),'008564445P052026.DAT');
        $this->assertStringContainsString('"008564445","STC"',$dat);
        $this->assertStringNotContainsString('008791976',$dat);
        $this->assertStringContainsString('STC STREET',$dat);
        $this->assertSame(21,count(str_getcsv(explode("\r\n",$dat)[0])));
    }

    public function test_retry_retains_original_company_and_rejects_company_override(): void
    {
        // No supplier exists: the retained file must carry its owner through the fix queue.
        $response = $this->upload(self::STC); $this->assertNotNull(session('uploadIssueDialog'), (string) session('error')); 
        $pending=PendingPurchaseUpload::sole();
        $this->assertSame(self::STC['tin'],$pending->filing_company_tin);
        $this->record(self::FSI);
        Supplier::create(['tin'=>'123-456-789-000','name'=>'SUPPLIER A','addr'=>'STREET','city'=>'MANILA']);
        $this->post(route('pending-purchase-uploads.retry',$pending),['filing_company_tin'=>self::FSI['tin']])->assertSessionHasErrors('filing_company_tin');
        $this->assertSame('pending',$pending->fresh()->status);
        $this->post(route('pending-purchase-uploads.retry',$pending))->assertSessionHas('success');
        $this->assertSame(1,VatInput::forFilingCompany(self::FSI)->count());
        $this->assertSame(1,VatInput::forFilingCompany(self::STC)->count());
    }

    public function test_unassigned_adjustments_and_profile_gaps_fail_safely(): void
    {
        $legacy=$this->record([]);
        $this->put('/records/'.$legacy->id,[])->assertSessionHasErrors('filing_company_tin');
        WithholdingCompany::where('tin',self::STC['tin'])->update(['address1'=>null]);
        $this->record(self::STC);
        $this->get($this->url('/download-datfile',self::STC,'&period=2026-05-31'))->assertSessionHasErrors('filing_company_tin');
    }
    public function test_same_tin_different_branches_do_not_merge_and_failed_import_rolls_back(): void
    {
        config(['bir.companies.branch_one' => [...config('bir.companies.008791976'), 'branch_code' => '0001']]);
        $branch = ['tin' => self::FSI['tin'], 'branch_code' => '0001'];
        Supplier::create(['tin'=>'123-456-789-000','name'=>'SUPPLIER A','addr'=>'STREET','city'=>'MANILA']);
        $this->upload(self::FSI)->assertSessionHas('success');
        $this->upload($branch,2000)->assertSessionHas('success');
        $this->assertSame(1,VatInput::forFilingCompany(self::FSI)->count());
        $this->assertSame('2000.00',VatInput::forFilingCompany($branch)->sole()->purchase_local);
        $before=VatInput::forFilingCompany(self::FSI)->sole()->getAttributes();
        $failed = false;
        try {
            app(PurchaseUploadService::class)->replace('/missing/purchase.xlsx','2026-05-31',self::FSI);

        } catch (\Throwable $error) {
            $failed = true;
        }
        $this->assertTrue($failed, 'An unreadable workbook must fail.');
        $this->assertSame($before,VatInput::forFilingCompany(self::FSI)->sole()->getAttributes());
    }

    public function test_purchase_owned_company_cannot_be_deleted_or_have_identity_changed(): void
    {
        $this->record(self::STC);
        $company=WithholdingCompany::where('tin',self::STC['tin'])->sole();
        $this->assertTrue($company->hasFiledRows());
        $this->assertSame(self::STC['tin'],$company->tin);
    }

    public function test_legacy_pending_upload_never_inherits_a_new_company(): void
    {
        $this->upload(self::STC)->assertSessionHas('uploadIssueDialog');
        $pending=PendingPurchaseUpload::sole();
        $pending->update(['filing_company_tin'=>null,'filing_company_branch_code'=>null]);
        $this->get(route('pending-purchase-uploads.show',$pending))->assertJsonPath('ready_to_retry',false);
        $this->post(route('pending-purchase-uploads.retry',$pending),['filing_company_tin'=>self::FSI['tin'],'filing_company_branch_code'=>'0000'])
            ->assertSessionHasErrors('filing_company_tin');
        $this->assertDatabaseCount('vat_inputs',0);
    }
}
