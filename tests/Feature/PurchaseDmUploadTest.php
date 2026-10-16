<?php

namespace Tests\Feature;

use App\Imports\PurchaseDmReader;
use App\Models\Brokers;
use App\Models\ImportationEntry;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VatInput;
use App\Services\PurchaseDmReferenceResetService;
use App\Services\PurchaseUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PurchaseDmUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function record(array $overrides = []): VatInput
    {
        return VatInput::create(array_replace([
            'filing_company_tin' => '008791976', 'filing_company_branch_code' => '0000',
            'supplier_name' => 'SUPPLIER A', 'company_name' => 'SUPPLIER A', 'tin_number' => '123456789000', 'vendor_type' => 'company',
            'address1' => 'STREET', 'address2' => 'MANILA', 'date_uploaded' => '2026-09-30', 'is_imported' => false, 'is_adjusted' => false,
            'uses_vat_bucket_amounts' => true, 'vat_rate' => 12, 'exempt' => 0, 'zero_rated' => 0,
            'purchase_local' => 100000, 'purchase_local_vat_amount' => 12000, 'purchase_imported' => 0, 'purchase_imported_vat_amount' => 0,
            'services' => 0, 'services_vat_amount' => 0, 'others' => 0, 'others_vat_amount' => 0,
            'capital_goods' => 0, 'other_than_capital_goods' => 100000, 'taxable_net_of_vat' => 100000, 'input_vat' => 12000, 'total' => 100000, 'total_purchases' => 112000,
        ], $overrides));
    }

    private function file(array $rows, string $type = 'local'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['ACCOUNT DETAILS'], ['Period Covered: September 1, 2026 - September 30, 2026'],
            ['Date', 'Reference', 'Particulars', 'Other Info', 'Debit', 'Credit', 'Balance'],
            ['152201A VAT INPUT TAX - '.PurchaseDmReader::TYPES[$type], null, null, null, null, 'BEGINNING BALANCE...', 0], ...$rows,
        ], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'purchase-dm');
        try {
            (new Xlsx($book))->save($path);

            return UploadedFile::fake()->createWithContent('dm.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    private function row(string $reference = 'DM (REG)#001', mixed $credit = 1200, string $supplier = 'SUPPLIER A'): array
    {
        return ['09/09/2026', $reference, $supplier, null, null, $credit];
    }

    private function payload(array $rows = [], string $type = 'local'): array
    {
        return ['excel_file' => $this->file($rows ?: [$this->row()], $type), 'purchase_type' => $type,
            'filing_company_tin' => '008791976', 'filing_company_branch_code' => '0000', 'reporting_month' => '2026-09'];
    }

    private function apply(string $token, array $extra = [])
    {
        return $this->postJson('/purchase-dm/apply', array_replace(['token' => $token, 'review_version' => 1, 'acknowledge_skipped' => true, 'acknowledge_warnings' => true], $extra));
    }

    public function test_review_then_apply_updates_paired_amounts_and_retry_is_idempotent(): void
    {
        $record = $this->record();
        $before = $record->fresh()->getAttributes();
        $review = $this->postJson('/purchase-dm/review', $this->payload([$this->row(), $this->row('PV#009', 99999)]))->assertOk()
            ->assertJsonPath('summary.eligible_total', '1200.00')->assertJsonPath('excluded_count', 1)->assertJsonPath('targets.0.metrics.visible_total.after', '90000.00');
        $this->assertSame($before, $record->fresh()->getAttributes());
        $token = $review->json('token');
        $this->apply($token)->assertOk();
        $this->apply($token)->assertOk();
        $record->refresh();
        $this->assertSame('10800.00', $record->purchase_local_vat_amount);
        $this->assertSame('90000.00', $record->purchase_local);
        $this->assertSame('90000.00', $record->total);
        $this->assertSame('100800.00', $record->total_purchases);
        $this->assertSame('90000.00', $record->other_than_capital_goods);
        $this->assertDatabaseCount('purchase_dm_applications', 1);
        $this->assertDatabaseCount('purchase_dm_sources', 1);
        $this->postJson('/purchase-dm/review', $this->payload())->assertOk()->assertJsonPath('entries.0.status', 'already_applied')->assertJsonPath('token', null);
        $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM (REG)#001', 1201)]))->assertJsonPath('entries.0.status', 'conflicting_reference');
    }

    public function test_all_four_buckets_update_the_right_categories_and_preserve_other_fields(): void
    {
        foreach (['local' => 'purchase_local', 'imported' => 'purchase_imported', 'services' => 'services', 'others' => 'others'] as $type => $base) {
            VatInput::query()->delete();
            $r = $this->record(['purchase_local' => 0, 'purchase_local_vat_amount' => 0, $base => 100000, $base.'_vat_amount' => 12000,
                'is_imported' => $type === 'imported', 'capital_goods' => $type === 'imported' ? 100000 : 0,
                'other_than_capital_goods' => in_array($type, ['local', 'others']) ? 100000 : 0]);
            $response = $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM#'.(array_search($type, ['local', 'imported', 'services', 'others']) + 10))], $type))->assertOk()->assertJsonPath('summary.eligible_count', 1);
            $this->apply($response->json('token'))->assertOk();
            $r->refresh();
            $this->assertSame('90000.00', $r->{$base});
            $this->assertSame('10800.00', $r->{$base.'_vat_amount'});
            $this->assertSame('90000.00', $r->taxable_net_of_vat);
            $this->assertSame('0.00', $r->exempt);
            $category = $type === 'imported' ? 'capital_goods' : ($type === 'services' ? 'services' : 'other_than_capital_goods');
            $this->assertSame('90000.00', $r->{$category});
        }
    }

    public function test_eligible_subset_skips_invalid_unmatched_and_non_dm_rows(): void
    {
        $this->record();
        $p = $this->payload([$this->row(), $this->row('DM#002', 30, 'MISSING'), $this->row('DM#003', '=1+1'), $this->row('DM#004', 0), $this->row('PV#002', 77)]);
        $r = $this->postJson('/purchase-dm/review', $p)->assertOk()->assertJsonPath('summary.eligible_count', 1)->assertJsonPath('summary.skipped_count', 3)->assertJsonPath('summary.skipped_unknown_count', 1)->assertJsonPath('excluded_count', 1);
        $this->apply($r->json('token'), ['acknowledge_skipped' => false])->assertUnprocessable();
        $this->apply($r->json('token'))->assertOk();
        $this->assertDatabaseCount('purchase_dm_references', 1);
    }

    public function test_parser_rejects_wrong_type_period_and_duplicate_reference(): void
    {
        $this->record();
        $p = $this->payload();
        $p['purchase_type'] = 'services';
        $this->postJson('/purchase-dm/review', $p)->assertUnprocessable();
        $p = $this->payload();
        $p['reporting_month'] = '2026-08';
        $this->postJson('/purchase-dm/review', $p)->assertUnprocessable();
        $this->postJson('/purchase-dm/review', $this->payload([$this->row(), $this->row('dm (reg) #001')]))->assertUnprocessable();
        $this->assertDatabaseCount('purchase_dm_applications', 0);
    }

    public function test_missing_ambiguous_and_unsupported_targets_are_not_guessed(): void
    {
        $r = $this->record(['filing_company_tin' => '999999999']);
        $this->postJson('/purchase-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'no_matching_record');
        $r->update(['filing_company_tin' => '008791976', 'uses_vat_bucket_amounts' => false]);
        $this->postJson('/purchase-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'unsupported_record');
        $r->update(['uses_vat_bucket_amounts' => true]);
        $other = $this->record(['tin_number' => '987654321000']);
        $this->postJson('/purchase-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'ambiguous_identity');
        $other->delete();
        $r->update(['total' => 1]);
        $this->postJson('/purchase-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'inconsistent_amounts');
    }

    public function test_negative_balances_require_acknowledgment_and_preserve_cents(): void
    {
        $r = $this->record();
        $review = $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM#01', 12000.06)]))->assertOk();
        $this->assertNotEmpty($review->json('warnings'));
        $this->apply($review->json('token'), ['acknowledge_warnings' => false])->assertUnprocessable();
        $this->apply($review->json('token'))->assertOk();
        $this->assertSame('-0.06', $r->fresh()->purchase_local_vat_amount);
        $this->assertSame('-0.50', $r->fresh()->purchase_local);
    }

    public function test_group_allocation_rounds_cumulatively(): void
    {
        $r = $this->record(['purchase_local' => '0.08', 'purchase_local_vat_amount' => '.01', 'other_than_capital_goods' => '.08', 'taxable_net_of_vat' => '.08', 'input_vat' => '.01', 'total' => '.08', 'total_purchases' => '.09']);
        $r2 = $this->record();
        $review = $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM#01', .06)]))->assertOk();
        $this->apply($review->json('token'))->assertOk();
        $this->assertSame('0.00', $r->fresh()->purchase_local);
        $this->assertSame('99999.58', $r2->fresh()->purchase_local);
    }

    public function test_changed_snapshot_expired_token_and_wrong_user_cannot_apply(): void
    {
        $r = $this->record();
        $token = $this->postJson('/purchase-dm/review', $this->payload())->json('token');
        $r->update(['address1' => 'NEW STREET']);
        $this->apply($token)->assertUnprocessable();
        $token = $this->postJson('/purchase-dm/review', $this->payload())->json('token');
        $this->actingAs(User::factory()->create());
        $this->apply($token)->assertForbidden();
        Cache::forget('purchase-dm:'.$token);
        $this->apply($token)->assertUnprocessable();
        $this->assertDatabaseCount('purchase_dm_applications', 0);
    }

    public function test_competing_reviews_cannot_double_deduct(): void
    {
        $this->record();
        $one = $this->postJson('/purchase-dm/review', $this->payload())->json('token');
        $two = $this->postJson('/purchase-dm/review', $this->payload())->json('token');
        $this->apply($one)->assertOk();
        $this->apply($two)->assertUnprocessable();
        $this->assertDatabaseCount('purchase_dm_references', 1);
    }

    public function test_scope_reset_retires_only_replaced_sources_and_invalidates_retries(): void
    {
        $r = $this->record();
        $token = $this->postJson('/purchase-dm/review', $this->payload())->json('token');
        $this->apply($token)->assertOk();
        $scope = $this->payload();
        unset($scope['excel_file']);
        DB::transaction(function () use ($scope, $r) {
            $s = app(PurchaseDmReferenceResetService::class);
            $s->lock($scope);
            $s->reset($scope, [$r->id]);
            $r->delete();
        });
        $this->record();
        $this->apply($token)->assertUnprocessable();
        $this->assertDatabaseCount('purchase_dm_references', 0);
        $this->assertDatabaseCount('purchase_dm_sources', 1);
        $this->assertNotNull(DB::table('purchase_dm_sources')->first()->retired_at);
        $this->postJson('/purchase-dm/review', $this->payload())->assertJsonPath('summary.eligible_count', 1);
    }

    public function test_transaction_rolls_back_reference_and_balance_on_save_failure(): void
    {
        $r = $this->record();
        $token = $this->postJson('/purchase-dm/review', $this->payload())->json('token');
        VatInput::updating(function () {
            throw new \RuntimeException('simulated write failure');
        });
        try {
            $this->apply($token)->assertStatus(500);
        } finally {
            VatInput::flushEventListeners();
        }
        $this->assertDatabaseCount('purchase_dm_applications', 0);
        $this->assertDatabaseCount('purchase_dm_references', 0);
        $this->assertSame('12000.00', $r->fresh()->purchase_local_vat_amount);
    }

    public function test_real_normal_reupload_resets_only_its_scope_and_failed_import_rolls_back(): void
    {
        $r = $this->record();
        $other = $this->record(['filing_company_branch_code' => '0001']);
        $token = $this->postJson('/purchase-dm/review', $this->payload())->json('token');
        $this->apply($token)->assertOk();
        $otherBefore = $other->fresh()->getAttributes();
        $service = app(PurchaseUploadService::class);
        $company = ['tin' => '008791976', 'branch_code' => '0000'];
        try {
            $service->replace('/missing-purchase-file.xlsx', '2026-09-30', $company);
            $this->fail('Expected import failure');
        } catch (\Throwable $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertDatabaseCount('purchase_dm_references', 1);
        $this->assertSame('10800.00', $r->fresh()->purchase_local_vat_amount);
        Supplier::create(['tin' => '123-456-789-000', 'name' => 'SUPPLIER A', 'addr' => 'STREET', 'city' => 'MANILA']);
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['Purchase VAT Report'], ['Period Covered: September 1, 2026 - September 30, 2026'],
            ['vendor_tin', 'supplier_name', 'address1', 'address2', 'exempt', 'zero_rated', 'purchase_imported', 'purchase_local', 'services', 'others', 'total_purchases'],
            ['123456789000', 'SUPPLIER A', '', '', 0, 0, 0, 12000, 0, 0, 12000],
        ], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'purchase-reset');
        try {
            (new Xlsx($book))->save($path);
            $file = UploadedFile::fake()->createWithContent('purchase.xlsx', file_get_contents($path));
            $this->post('/vat-import', ['record_type' => 'purchase', 'reporting_month' => '2026-09', 'excel_file' => $file,
                'filing_company_tin' => '008791976', 'filing_company_branch_code' => '0000'])->assertSessionHasNoErrors()->assertSessionHas('success');
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
        $this->assertDatabaseCount('purchase_dm_references', 0);
        $this->assertNotNull(DB::table('purchase_dm_applications')->first()->superseded_at);
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        $this->apply($token)->assertUnprocessable();
        $review = $this->postJson('/purchase-dm/review', $this->payload())->assertOk()->assertJsonPath('summary.eligible_count', 1);
        $this->apply($review->json('token'))->assertOk();
    }

    public function test_active_dm_blocks_identity_edits_and_broker_transfer_to_target(): void
    {
        $r = $this->record();
        $token = $this->postJson('/purchase-dm/review', $this->payload())->json('token');
        $this->apply($token)->assertOk();
        $this->putJson('/records/'.$r->id.'/bir-info', ['vendor_type' => 'company', 'company_name' => 'NEW NAME', 'tin_number' => '123456789000', 'address1' => 'STREET', 'address2' => 'MANILA'])->assertUnprocessable()->assertJsonValidationErrors('purchase_dm');
        Brokers::create(['broker_name' => 'BROKER', 'tin_number' => '111111111000']);
        $broker = $this->record(['supplier_name' => 'BROKER', 'company_name' => 'BROKER', 'tin_number' => '111111111000']);
        $this->putJson('/records/'.$broker->id, ['vendor_type' => 'company', 'company_name' => 'SUPPLIER A', 'tin_number' => '123456789000',
            'address1' => 'STREET', 'address2' => 'MANILA', 'is_imported' => false, 'purchase_local_vat_amount' => 120])->assertUnprocessable()->assertJsonValidationErrors('purchase_dm');
        $this->assertSame('10800.00', $r->fresh()->purchase_local_vat_amount);
    }

    public function test_broker_adjusted_importation_and_legacy_records_are_excluded(): void
    {
        $r = $this->record(['is_adjusted' => true]);
        $this->postJson('/purchase-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'unsupported_record');
        $r->update(['is_adjusted' => false, 'purchase_local_vat_amount' => null]);
        $this->postJson('/purchase-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'unsupported_record');
        $r->update(['purchase_local_vat_amount' => 12000]);
        Brokers::create(['broker_name' => 'SUPPLIER A', 'tin_number' => '123456789000']);
        $this->postJson('/purchase-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'unsupported_record');
    }

    public function test_overflow_and_zero_credit_cannot_create_active_references(): void
    {
        $this->record();
        $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM#001', '9999999999.99')]))->assertJsonPath('entries.0.status', 'amount_out_of_range')->assertJsonPath('token', null);
        $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM#002', 0)]))->assertJsonPath('entries.0.status', 'invalid_dm')->assertJsonPath('token', null);
        $this->assertDatabaseCount('purchase_dm_references', 0);
    }

    public function test_importation_mirrors_are_never_purchase_dm_targets(): void
    {
        $record = $this->record();
        ImportationEntry::create([
            'tax_month' => '2026-09-01', 'import_entry_no' => 'TEST-DM-MIRROR', 'assessment_date' => '2026-09-01',
            'supplier' => 'SUPPLIER A', 'importation_date' => '2026-09-01', 'country' => 'CHINA', 'or_number' => 'TEST-OR',
            'payment_date' => '2026-09-01', 'vat_input_id' => $record->id,
        ]);
        $this->postJson('/purchase-dm/review', $this->payload())->assertOk()->assertJsonPath('entries.0.status', 'no_matching_record')->assertJsonPath('token', null);
        $this->assertSame('12000.00', $record->fresh()->purchase_local_vat_amount);
    }

    public function test_zero_original_record_remains_matchable_with_negative_acknowledgment(): void
    {
        $r = $this->record(['purchase_local' => 0, 'purchase_local_vat_amount' => 0, 'other_than_capital_goods' => 0,
            'taxable_net_of_vat' => 0, 'input_vat' => 0, 'total' => 0, 'total_purchases' => 0]);
        $review = $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM#ZERO', .06)]));
        // Malformed references still skip, even when a target exists.
        $review->assertJsonPath('entries.0.status', 'invalid_dm');
        $review = $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM#006', .06)]))->assertOk()->assertJsonPath('summary.eligible_count', 1);
        $this->apply($review->json('token'), ['acknowledge_warnings' => false])->assertUnprocessable();
        $this->apply($review->json('token'))->assertOk();
        $this->assertSame('-0.06', $r->fresh()->input_vat);
        $this->assertSame('-0.50', $r->fresh()->total);
    }

    public function test_partial_source_replacement_cannot_release_active_references(): void
    {
        $one = $this->record();
        $two = $this->record(['date_uploaded' => '2026-09-15']);
        $token = $this->postJson('/purchase-dm/review', $this->payload([$this->row('DM#001', 13000)]))->json('token');
        $this->apply($token)->assertOk();
        $scope = $this->payload();
        unset($scope['excel_file']);
        try {
            DB::transaction(function () use ($scope, $one) {
                $reset = app(PurchaseDmReferenceResetService::class);
                $reset->lock($scope);
                $reset->reset($scope, [$one->id]);
            });
            $this->fail('Partial replacement must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('purchase_dm', $error->errors());
        }
        $this->assertDatabaseCount('purchase_dm_references', 1);
        $this->assertSame(2, DB::table('purchase_dm_sources')->whereNull('retired_at')->count());
        $this->assertNotNull($two->fresh());
    }

    public function test_supplied_workbook_has_only_four_dm_credit_deductions(): void
    {
        $path = base_path('Docs/purchase/ACCOUNT DETAILS (1).xlsx');
        if (! is_file($path)) {
            $this->markTestSkipped('Local user-supplied workbook not present.');
        }
        $parsed = app(PurchaseDmReader::class)->read($path, '2026-09', 'local');
        $this->assertCount(4, $parsed['items']);
        $this->assertSame(3450782, array_sum(array_column($parsed['items'], 'credit_cents')));
        $this->assertGreaterThan(0, $parsed['excluded_count']);
    }
}
