<?php

namespace Tests\Feature;

use App\Models\PendingSalesUpload;
use App\Models\SalesVatInput;
use App\Models\User;
use App\Services\PendingSalesUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PendingSalesUploadTest extends TestCase
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

    public function test_customer_only_rejection_is_retained_and_grouped_without_replacing_sales(): void
    {
        $old = $this->oldSales();
        $before = $old->fresh()->getAttributes();

        $this->post('/vat-import', $this->payload($this->summary([
            $this->summaryRow('SI#100'),
            $this->summaryRow('SI#101'),
        ])))->assertSessionHas('uploadIssueDialog');

        $pending = PendingSalesUpload::query()->sole();
        $queue = session('uploadIssueDialog.pending_upload');
        $this->assertSame(1, $pending->initial_customer_count);
        $this->assertSame(2, $pending->initial_row_count);
        $this->assertSame(1, $queue['affected_customers']);
        $this->assertSame([2, 3], $queue['items'][0]['affected_rows']);
        $this->assertSame('create', $queue['items'][0]['mode']);
        $this->assertSame('SECOND SONS CONSTRUCTION', $queue['items'][0]['prefill']['name']);
        $this->assertSame('', $queue['items'][0]['prefill']['tin']);
        $this->assertFalse($queue['ready_to_retry']);
        Storage::disk('local')->assertExists($pending->stored_path);
        $this->assertSame($before, $old->fresh()->getAttributes());
    }

    public function test_customer_create_refreshes_queue_and_retry_imports_retained_workbook(): void
    {
        $this->post('/vat-import', $this->payload($this->summary([$this->summaryRow('SI#100')])));
        $pending = PendingSalesUpload::query()->sole();
        $fixUrl = session('uploadIssueDialog.pending_upload.items.0.fix_url');

        $this->get($fixUrl)->assertInertia(fn (Assert $page) => $page
            ->component('ManageCustomer')
            ->where('customerFixContext.current_item.mode', 'create')
            ->where('customerFixContext.current_item.prefill.name', 'SECOND SONS CONSTRUCTION')
            ->where('customerFixContext.pending_upload.ready_to_retry', false));

        $this->from($fixUrl)->post('/customers', [
            'tin' => '111-222-333-000',
            'name' => 'SECOND SONS CONSTRUCTION',
            'addr' => 'MAIN STREET',
            'city' => 'MANILA',
        ])->assertRedirect($fixUrl)->assertSessionHasNoErrors();

        $this->get($fixUrl)->assertInertia(fn (Assert $page) => $page
            ->where('customerFixContext.current_item', null)
            ->where('customerFixContext.pending_upload.ready_to_retry', true));

        $this->post(route('pending-sales-uploads.retry', $pending))
            ->assertRedirect(route('records.sales.index'))
            ->assertSessionHas('success');

        $this->assertSame(PendingSalesUpload::STATUS_COMPLETED, $pending->fresh()->status);
        Storage::disk('local')->assertMissing($pending->stored_path);
        $this->assertDatabaseHas('sales_vatsinputs', [
            'document_no' => 'SI#100',
            'customer_tin' => '111-222-333-000',
            'address1' => 'MAIN STREET',
            'address2' => 'MANILA',
        ]);
    }

    public function test_workbook_or_mixed_issues_do_not_create_pending_queue(): void
    {
        $file = $this->csv('sales.csv', [
            'CLIENT TIN,Company Name,Last Name,First Name,Middle Name,Address1,Address2,Exempt Sales,Zero Rated Sales,Taxable Sales,Total Sales,Output VAT,Net Amount,Gross Amount',
            '111222333,SECOND SONS CONSTRUCTION,,,,,,0,oops,0,0,0,0,0',
        ]);

        $this->post('/vat-import', $this->payload($file))
            ->assertSessionHas('uploadIssueDialog')
            ->assertSessionMissing('success');

        $this->assertDatabaseCount('pending_sales_uploads', 0);
        $this->assertNull(session('uploadIssueDialog.pending_upload'));
        $this->assertNotEmpty(session('uploadIssueDialog.issues'));
    }

    public function test_repeated_same_file_reuses_the_active_pending_upload(): void
    {
        $this->post('/vat-import', $this->payload($this->summary([$this->summaryRow('SI#100')])));
        $first = PendingSalesUpload::query()->sole();

        $this->post('/vat-import', $this->payload($this->summary([$this->summaryRow('SI#100')])));

        $this->assertDatabaseCount('pending_sales_uploads', 1);
        $this->assertSame($first->token, PendingSalesUpload::query()->sole()->token);
    }

    public function test_checksum_failure_blocks_retry_without_replacing_sales(): void
    {
        $old = $this->oldSales();
        $before = $old->fresh()->getAttributes();
        $this->post('/vat-import', $this->payload($this->summary([$this->summaryRow('SI#100')])));
        $pending = PendingSalesUpload::query()->sole();
        Storage::disk('local')->put($pending->stored_path, 'changed bytes');

        $this->post(route('pending-sales-uploads.retry', $pending))->assertSessionHasErrors('pending_upload');

        $this->assertSame(PendingSalesUpload::STATUS_PENDING, $pending->fresh()->status);
        $this->assertSame($before, $old->fresh()->getAttributes());
    }

    public function test_unresolved_retry_preserves_rows_and_cross_user_is_hidden(): void
    {
        $old = $this->oldSales();
        $before = $old->fresh()->getAttributes();
        $this->post('/vat-import', $this->payload($this->summary([$this->summaryRow('SI#100')])));
        $pending = PendingSalesUpload::query()->sole();

        $this->post(route('pending-sales-uploads.retry', $pending))->assertSessionHas('uploadIssueDialog');
        $this->assertSame($before, $old->fresh()->getAttributes());
        $this->assertSame(PendingSalesUpload::STATUS_PENDING, $pending->fresh()->status);

        $this->actingAs(User::factory()->create())
            ->get(route('pending-sales-uploads.show', $pending))
            ->assertNotFound();
    }

    public function test_expired_pending_file_is_cleaned(): void
    {
        $this->post('/vat-import', $this->payload($this->summary([$this->summaryRow('SI#100')])));
        $pending = PendingSalesUpload::query()->sole();
        $pending->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertSame(1, app(PendingSalesUploadService::class)->cleanupExpired());
        $this->assertSame(PendingSalesUpload::STATUS_EXPIRED, $pending->fresh()->status);
        Storage::disk('local')->assertMissing($pending->stored_path);
    }

    private function payload(UploadedFile $file): array
    {
        return ['excel_file' => $file, 'reporting_month' => '2026-05', 'record_type' => 'sales'];
    }

    private function summary(array $rows): UploadedFile
    {
        return $this->csv('sales-summary.csv', [
            'Document No,Date,Terms,Days,Due Date,Agent,Customer Name,SO/DR/SI,Gross Amount,Discount,Charges,Net Amount,Output VAT,Taxable Net of VAT',
            ...$rows,
        ]);
    }

    private function summaryRow(string $document): string
    {
        return "{$document},05/04/2026,CASH,0,05/04/2026,AGENT,SECOND SONS CONSTRUCTION,012396,1120,0,0,1000,120,1000";
    }

    private function csv(string $name, array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, implode("\r\n", $lines)."\r\n");
    }

    private function oldSales(): SalesVatInput
    {
        return SalesVatInput::create([
            'document_no' => 'SI#OLD', 'document_type' => 'SI', 'document_date' => '2026-05-01',
            'customer_name' => 'OLD CUSTOMER', 'gross_amount' => 1120, 'net_amount' => 1000,
            'output_vat' => 120, 'taxable_net_of_vat' => 1000, 'reporting_period' => '2026-05-31',
            'is_adjusted' => false,
        ]);
    }
}
