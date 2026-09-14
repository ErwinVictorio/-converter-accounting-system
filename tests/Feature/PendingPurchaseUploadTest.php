<?php

namespace Tests\Feature;

use App\Models\PendingPurchaseUpload;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VatInput;
use App\Services\PendingPurchaseUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PendingPurchaseUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_rejected_purchase_is_retained_privately_and_grouped_by_supplier(): void
    {
        $old = $this->oldPurchase();
        $before = $old->fresh()->getAttributes();

        $response = $this->post('/vat-import', $this->uploadPayload($this->purchaseWorkbook([
            ['123456789000', 'ABC SUPPLIER', '', '', 0, 0, 0, 1000, 0, 0, 120, 1120],
            ['123-456-789-000', 'ABC SUPPLIER', '', '', 0, 0, 0, 500, 0, 0, 60, 560],
        ])));

        $response->assertRedirect()->assertSessionHas('uploadIssueDialog');
        $pending = PendingPurchaseUpload::query()->sole();

        $this->assertSame($this->user->id, $pending->user_id);
        $this->assertSame(PendingPurchaseUpload::STATUS_PENDING, $pending->status);
        $this->assertSame(1, $pending->initial_supplier_count);
        $this->assertSame(2, $pending->initial_row_count);
        Storage::disk('local')->assertExists($pending->stored_path);
        $this->assertSame($pending->sha256, hash('sha256', Storage::disk('local')->get($pending->stored_path)));

        $queue = session('uploadIssueDialog.pending_upload');
        $this->assertSame(1, $queue['affected_suppliers']);
        $this->assertSame(2, $queue['affected_rows']);
        $this->assertSame([4, 5], $queue['items'][0]['affected_rows']);
        $this->assertSame('create', $queue['items'][0]['mode']);
        $this->assertSame('123-456-789-000', $queue['items'][0]['prefill']['tin']);
        $this->assertSame('ABC SUPPLIER', $queue['items'][0]['prefill']['name']);
        $this->assertFalse($queue['ready_to_retry']);
        $this->assertSame($before, $old->fresh()->getAttributes());
    }

    public function test_fix_url_prefills_create_and_retry_imports_the_exact_retained_workbook(): void
    {
        $this->post('/vat-import', $this->uploadPayload($this->purchaseWorkbook([
            ['123456789000', 'ABC SUPPLIER', '', '', 0, 0, 0, 1000, 0, 0, 120, 1120],
        ])))->assertSessionHas('uploadIssueDialog');

        $pending = PendingPurchaseUpload::query()->sole();
        $queue = session('uploadIssueDialog.pending_upload');
        $fixUrl = $queue['items'][0]['fix_url'];

        $this->get($fixUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ManageSupplier')
            ->where('supplierFixContext.current_item.mode', 'create')
            ->where('supplierFixContext.current_item.prefill.tin', '123-456-789-000')
            ->where('supplierFixContext.current_item.prefill.name', 'ABC SUPPLIER')
            ->where('supplierFixContext.pending_upload.remaining_suppliers', 1)
            ->where('supplierFixContext.pending_upload.ready_to_retry', false));

        $this->from($fixUrl)->post('/suppliers', [
            'tin' => '123-456-789-000',
            'name' => 'ABC SUPPLIER',
            'addr' => 'MAIN STREET',
            'city' => 'MANILA',
        ])->assertRedirect($fixUrl)->assertSessionHasNoErrors();

        $this->get($fixUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('supplierFixContext.current_item', null)
            ->where('supplierFixContext.pending_upload.remaining_suppliers', 0)
            ->where('supplierFixContext.pending_upload.ready_to_retry', true));

        $this->post(route('pending-purchase-uploads.retry', $pending))
            ->assertRedirect(route('records.purchases.index'))
            ->assertSessionHas('success');

        $pending->refresh();
        $this->assertSame(PendingPurchaseUpload::STATUS_COMPLETED, $pending->status);
        Storage::disk('local')->assertMissing($pending->stored_path);
        $this->assertDatabaseHas('vat_inputs', [
            'supplier_name' => 'ABC SUPPLIER',
            'tin_number' => '123-456-789-000',
            'address1' => 'MAIN STREET',
            'address2' => 'MANILA',
        ]);
    }

    public function test_matched_supplier_queue_opens_edit_context_and_refreshes_after_save(): void
    {
        $supplier = Supplier::create([
            'tin' => '123-456-789-000',
            'name' => 'ABC SUPPLIER',
            'addr' => 'MAIN STREET',
            'city' => '',
        ]);

        $this->post('/vat-import', $this->uploadPayload($this->purchaseWorkbook([
            ['123456789000', 'ABC SUPPLIER', '', '', 0, 0, 0, 1000, 0, 0, 120, 1120],
        ])))->assertSessionHas('uploadIssueDialog');

        $queue = session('uploadIssueDialog.pending_upload');
        $fixUrl = $queue['items'][0]['fix_url'];

        $this->assertSame('edit', $queue['items'][0]['mode']);
        $this->assertSame($supplier->id, $queue['items'][0]['supplier_id']);

        $this->get($fixUrl)->assertInertia(fn (Assert $page) => $page
            ->where('supplierFixContext.current_item.mode', 'edit')
            ->where('supplierFixContext.current_item.supplier_id', $supplier->id));

        $this->from($fixUrl)->put('/suppliers/'.$supplier->id, [
            'tin' => $supplier->tin,
            'name' => $supplier->name,
            'addr' => $supplier->addr,
            'city' => 'MANILA',
        ])->assertRedirect($fixUrl)->assertSessionHasNoErrors();

        $this->get($fixUrl)->assertInertia(fn (Assert $page) => $page
            ->where('supplierFixContext.pending_upload.ready_to_retry', true));
    }

    public function test_retry_with_unresolved_issues_preserves_existing_purchase_rows(): void
    {
        $old = $this->oldPurchase();
        $before = $old->fresh()->getAttributes();
        $this->post('/vat-import', $this->uploadPayload($this->purchaseWorkbook([
            ['123456789000', 'ABC SUPPLIER', '', '', 0, 0, 0, 1000, 0, 0, 120, 1120],
        ])));
        $pending = PendingPurchaseUpload::query()->sole();

        $this->post(route('pending-purchase-uploads.retry', $pending))
            ->assertRedirect()
            ->assertSessionHas('uploadIssueDialog');

        $this->assertSame(PendingPurchaseUpload::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame($before, $old->fresh()->getAttributes());
        Storage::disk('local')->assertExists($pending->stored_path);
    }

    public function test_pending_upload_is_private_to_its_owner_and_expired_files_are_cleaned(): void
    {
        $this->post('/vat-import', $this->uploadPayload($this->purchaseWorkbook([
            ['123456789000', 'ABC SUPPLIER', '', '', 0, 0, 0, 1000, 0, 0, 120, 1120],
        ])));
        $pending = PendingPurchaseUpload::query()->sole();

        $this->actingAs(User::factory()->create())
            ->get(route('pending-purchase-uploads.show', $pending))
            ->assertNotFound();

        $pending->forceFill(['expires_at' => now()->subMinute()])->save();
        $count = app(PendingPurchaseUploadService::class)->cleanupExpired();

        $this->assertSame(1, $count);
        $this->assertSame(PendingPurchaseUpload::STATUS_EXPIRED, $pending->fresh()->status);
        Storage::disk('local')->assertMissing($pending->stored_path);
    }

    private function uploadPayload(UploadedFile $file): array
    {
        return [
            'excel_file' => $file,
            'reporting_month' => '2026-05',
            'record_type' => 'purchase',
        ];
    }

    private function purchaseWorkbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Purchase VAT Report'],
            ['Period Covered: May 1, 2026 - May 31, 2026'],
            ['vendor_tin', 'supplier_name', 'address1', 'address2', 'exempt', 'zero_rated', 'purchase_imported', 'purchase_local', 'services', 'others', 'input_vat', 'total_purchases'],
            ...$rows,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'pending-purchase-');
        (new Xlsx($spreadsheet))->save($path);
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('purchases.xlsx', $contents);
    }

    private function oldPurchase(): VatInput
    {
        return VatInput::create([
            'tin_number' => '999-999-999-000',
            'supplier_name' => 'OLD SUPPLIER',
            'vendor_type' => 'company',
            'company_name' => 'OLD SUPPLIER',
            'address1' => 'OLD ADDRESS',
            'address2' => 'OLD CITY',
            'purchase_local' => 100,
            'services' => 0,
            'input_vat' => 12,
            'total' => 112,
            'date_uploaded' => '2026-05-31',
            'is_imported' => false,
            'is_adjusted' => false,
        ]);
    }
}
