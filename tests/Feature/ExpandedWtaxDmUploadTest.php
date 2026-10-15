<?php

namespace Tests\Feature;

use App\Imports\ExpandedWtaxDmReader;
use App\Models\ExpandedWtaxEntry;
use App\Models\User;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use App\Services\ExpandedWtaxDmAdjustmentService;
use App\Services\ExpandedWtaxDmReferenceResetService;
use App\Services\ExpandedWtaxDmReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExpandedWtaxDmUploadTest extends TestCase
{
    use RefreshDatabase;

    private function entry(array $values = []): ExpandedWtaxEntry
    {
        return ExpandedWtaxEntry::create(array_replace([
            'reporting_period' => '2026-09-30', 'report_type' => 'quarterly',
            'withholding_agent_tin' => '008791976', 'withholding_agent_branch_code' => '0000',
            'withholding_agent_name' => 'TEST AGENT', 'payee_type' => 'company',
            'payee_name' => 'WORLD BEST IND L SALES INC', 'company_name' => 'WORLD BEST IND L SALES INC',
            'payee_tin' => '000227599', 'payee_branch_code' => '0000', 'atc_code' => 'WC158',
            'tax_rate' => '1.00', 'income_payment' => '1068486.00', 'tax_withheld' => '10684.86',
        ], $values));
    }

    private function workbook(array $extra = [], bool $dmOnly = false): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['ACCOUNT DETAILS'], ['Period Covered: September 1, 2026 - September 30, 2026'],
            ['Date', 'Reference', 'Particulars', 'Other Info', 'Debit', 'Credit', 'Balance'],
            ...($dmOnly ? [] : [
                ['221101A WITHHOLDING TAX PAYABLE - EXPANDED 1%', null, null, null, null, 'BEGINNING BALANCE...', 0],
                ['09/08/2026', 'PV (REG)#006689', 'BUREAU OF INTERNAL REVENUE', null, 119648.62],
            ]),
            ['09/09/2026', 'DM (REG)#000252', "WORLD BEST IND'L. SALES, INC.", null, 903.28],
            ['09/30/2026', 'DM (REG)#000290', "WORLD BEST IND'L. SALES, INC.", null, 258.08],
            ...$extra,
        ], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'dm-test');
        try {
            (new Xlsx($book))->save($path);

            return UploadedFile::fake()->createWithContent('account-details.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['excel_file' => $this->workbook(), 'reporting_month' => '2026-09', 'report_type' => 'quarterly',
            'withholding_agent_tin' => '008791976', 'withholding_agent_branch_code' => '0000', 'percentage' => '1'], $overrides);
    }

    private function regularUpload(bool $invalid = false): TestResponse
    {
        $csv = "Reporting_Month,Vendor_TIN,branchCode,companyName,surName,firstName,middleName,ATC,income_payment,ewt_rate,tax_amount\n"
            .($invalid ? '08/30/2026' : '09/30/2026').",000227599,0000,WORLD BEST IND L SALES INC,,,,WC158,1068486,1,10684.86\n";

        return $this->post('/vat-import', ['record_type' => 'expanded', 'report_type' => 'quarterly',
            'reporting_month' => '2026-09', 'withholding_agent_tin' => '008791976', 'withholding_agent_branch_code' => '0000',
            'excel_file' => UploadedFile::fake()->createWithContent('regular.csv', $csv)]);
    }

    public function test_regular_reupload_resets_references_preserves_history_and_allows_new_dm(): void
    {
        $this->actingAs(User::factory()->create());
        $this->entry();
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true])->assertOk();
        $this->regularUpload()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseCount('expanded_dm_references', 0);
        $this->assertDatabaseCount('expanded_dm_applications', 1);
        $this->assertNotNull(DB::table('expanded_dm_applications')->first()->superseded_at);
        $this->assertSame('10684.86', ExpandedWtaxEntry::first()->tax_withheld);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true])->assertUnprocessable();
        $new = $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('issues', [])->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $new, 'acknowledge_warnings' => true])->assertOk();
        $this->assertSame('9523.50', ExpandedWtaxEntry::first()->tax_withheld);
        $this->assertSame('952350.00', ExpandedWtaxEntry::first()->income_payment);
        $this->assertDatabaseCount('expanded_dm_applications', 2);
        $this->assertDatabaseCount('expanded_dm_references', 2);
        $this->postJson('/expanded-dm/review', $this->payload())->assertJsonPath('token', null);
    }

    public function test_failed_regular_validation_keeps_active_references(): void
    {
        $this->actingAs(User::factory()->create());
        $this->entry();
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true])->assertOk();
        $this->regularUpload(true)->assertSessionHas('error');
        $this->assertDatabaseCount('expanded_dm_references', 2);
        $this->assertNull(DB::table('expanded_dm_applications')->first()->superseded_at);
        $this->assertSame('9523.50', ExpandedWtaxEntry::first()->tax_withheld);
    }

    public function test_reset_failure_rolls_back_regular_import_and_history(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true])->assertOk();
        $this->app->instance(ExpandedWtaxDmReferenceResetService::class, new class extends ExpandedWtaxDmReferenceResetService
        {
            public function reset(array $scope): int
            {
                parent::reset($scope);
                throw new \RuntimeException('Simulated reset failure');
            }
        });
        $this->regularUpload()->assertSessionHas('error');
        $this->assertSame('9523.50', $entry->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_references', 2);
        $this->assertNull(DB::table('expanded_dm_applications')->first()->superseded_at);
    }

    public function test_identical_regular_replacement_invalidates_pending_preview(): void
    {
        $this->actingAs(User::factory()->create());
        $this->entry();
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->regularUpload()->assertSessionHas('success');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true])->assertUnprocessable();
        $this->assertSame('10684.86', ExpandedWtaxEntry::first()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_applications', 0);
    }

    public function test_replacement_resets_all_rates_only_inside_exact_scope(): void
    {
        $this->actingAs(User::factory()->create());
        $this->entry();
        $scope = ['withholding_agent_tin' => '008791976', 'withholding_agent_branch_code' => '0000', 'report_type' => 'quarterly', 'reporting_month' => '2026-09', 'percentage' => '1.00'];
        $ids = [];
        foreach ([[], ['percentage' => '2.00'], ['withholding_agent_tin' => '123456789'], ['withholding_agent_branch_code' => '0001'], ['report_type' => 'annual'], ['reporting_month' => '2026-08']] as $index => $overrides) {
            $ids[] = $id = DB::table('expanded_dm_applications')->insertGetId([
                'token' => (string) Str::uuid(), 'user_id' => auth()->id(), 'file_digest' => str_repeat('a', 64),
                'scope' => json_encode(array_replace($scope, $overrides)), 'details' => json_encode(['original' => $index]),
                'total_deduction' => 10, 'created_at' => now(),
            ]);
            DB::table('expanded_dm_references')->insert(['application_id' => $id, 'identity' => hash('sha256', (string) $index), 'reference' => 'DM#'.$index]);
        }
        $this->regularUpload()->assertSessionHas('success');
        $this->assertDatabaseCount('expanded_dm_applications', 6);
        $this->assertDatabaseCount('expanded_dm_references', 4);
        foreach ($ids as $index => $id) {
            $application = DB::table('expanded_dm_applications')->find($id);
            $this->assertSame($index < 2, $application->superseded_at !== null);
            $this->assertSame(['original' => $index], json_decode($application->details, true));
        }
    }

    public function test_dm_only_file_uses_modal_rate_and_applies_both_amounts(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry(['tax_rate' => '2.00', 'atc_code' => 'WC160', 'income_payment' => '534243.00']);
        $review = $this->postJson('/expanded-dm/review', $this->payload([
            'excel_file' => $this->workbook([], true), 'percentage' => '2',
        ]))->assertOk()->assertJsonPath('issues', [])->assertJsonPath('warnings', [])
            ->assertJsonPath('excluded_count', 0)->assertJsonPath('targets.0.items.0.row', 4)
            ->assertJsonPath('targets.0.income_deduction', '58068.00');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $review->json('token')])->assertOk();
        $this->assertSame('9523.50', $entry->fresh()->tax_withheld);
        $this->assertSame('476175.00', $entry->fresh()->income_payment);
    }

    public function test_dm_only_file_still_rejects_bad_dates_and_conflicting_later_heading(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([
            ['08/09/2026', 'DM (REG)#999', 'TEST', null, 10],
            ['221101A WITHHOLDING TAX PAYABLE - EXPANDED 2%'],
        ] as $extra) {
            $this->postJson('/expanded-dm/review', $this->payload([
                'excel_file' => $this->workbook([$extra], true),
            ]))->assertUnprocessable()->assertJsonValidationErrors('excel_file');
        }
    }

    public function test_review_matches_world_best_without_writes_and_excludes_pv_debit(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->entry(['tax_withheld' => '1000.00', 'income_payment' => '100000.00']);
        $second = $this->entry(['tax_withheld' => '9684.86', 'income_payment' => '968486.00']);
        $this->entry(['withholding_agent_tin' => '123456789']);
        $this->entry(['reporting_period' => '2026-08-31']);
        $response = $this->postJson('/expanded-dm/review', $this->payload())->assertOk()
            ->assertJsonPath('total_deduction', '1161.36')->assertJsonPath('excluded_debit', '119648.62')
            ->assertJsonPath('targets.0.before', '10684.86')->assertJsonPath('targets.0.after', '9523.50')
            ->assertJsonPath('targets.0.source_count', 2)->assertJsonPath('issues', []);
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame([], $response->json('warnings'));
        $this->assertSame('1000.00', $first->fresh()->tax_withheld);
        $this->assertSame('968486.00', $second->fresh()->income_payment);
        $this->assertDatabaseCount('expanded_wtax_entries', 4);
    }

    public function test_rate_mismatch_and_duplicate_references_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/expanded-dm/review', $this->payload(['percentage' => '2']))->assertUnprocessable()->assertJsonValidationErrors('excel_file');
        $duplicate = $this->workbook([['09/09/2026', 'DM (REG)#000252', 'WORLD BEST', null, 1]]);
        $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $duplicate]))->assertUnprocessable()->assertJsonValidationErrors('excel_file');
    }

    public function test_ambiguous_atc_and_unmatched_names_cannot_get_an_apply_token(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('token', null);
        $this->entry();
        $this->entry(['atc_code' => 'WI158']);
        $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('token', null);
    }

    public function test_uploaded_zero_can_be_adjusted_and_reuploaded_for_dm(): void
    {
        $this->actingAs(User::factory()->create());
        $upload = function () {
            $csv = "No,Date,Supplier Name,TIN,Reference,(1%),(2%),(5%),(10%),(15%),Total\n"
                ."1,09/30/2026,WORLD BEST IND L SALES INC,000227599,A-1,,00,,,,0\n";
            return $this->post('/vat-import', [
                'record_type' => 'expanded', 'reporting_month' => '2026-09',
                'withholding_agent_tin' => '008791976', 'withholding_agent_branch_code' => '0000',
                'excel_file' => UploadedFile::fake()->createWithContent('zero.csv', $csv),
            ]);
        };
        $upload()->assertSessionHas('success');
        $record = ExpandedWtaxEntry::sole();
        $scope = ['withholding_agent_tin' => '008791976', 'withholding_agent_branch_code' => '0000',
            'reporting_month' => '2026-09', 'percentage' => '2'];
        foreach ([['withholding_agent_tin' => '123456789'], ['withholding_agent_branch_code' => '0001'],
            ['reporting_month' => '2026-08'], ['percentage' => '1']] as $override) {
            $this->assertSame(0, app(ExpandedWtaxDmAdjustmentService::class)->query(array_replace($scope, $override))->count());
        }
        // DM-only workbook uses the chosen modal rate.
        $payload = fn () => $this->payload(['excel_file' => $this->workbook([], true), 'percentage' => '2']);
        $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $this->workbook([], true)]))
            ->assertOk()->assertJsonPath('token', null);
        $review = $this->postJson('/expanded-dm/review', $payload())->assertOk()
            ->assertJsonPath('issues', [])->assertJsonPath('bir_warnings', [])
            ->assertJsonPath('targets.0.before', '0.00')
            ->assertJsonPath('targets.0.after', '-1161.36')
            ->assertJsonPath('targets.0.income_after', '-58068.00');
        $this->assertNotEmpty($review->json('negative_balance_warnings'));
        $this->assertSame('0.00', $record->fresh()->tax_withheld);
        $body = ['review_version' => 4, 'token' => $review->json('token')];
        $this->postJson('/expanded-dm/apply', $body)->assertUnprocessable();
        $this->assertSame('0.00', $record->fresh()->tax_withheld);
        $this->postJson('/expanded-dm/apply', $body + ['acknowledge_warnings' => true])->assertOk();
        $this->assertSame('-1161.36', $record->fresh()->tax_withheld);
        $this->assertSame('-58068.00', $record->fresh()->income_payment);
        $this->postJson('/expanded-dm/review', $payload())->assertOk()->assertJsonPath('token', null);
        $upload()->assertSessionHas('success');
        $this->assertSame('0.00', ExpandedWtaxEntry::sole()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_references', 0);
        $this->assertNotEmpty($this->postJson('/expanded-dm/review', $payload())->assertOk()->json('token'));
    }

    public function test_zero_balance_match_accepts_negative_dm_result_after_warning_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->entry();
        $acersteel = $this->entry([
            'payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC', 'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'payee_tin' => '007086184', 'tax_withheld' => '0.00', 'income_payment' => '0.00',
        ]);
        $file = $this->workbook([['09/09/2026', 'DM (REG)#000257', 'ACERSTEEL INDUSTRIAL SALES, INC.', null, 1714.29]], true);
        $review = $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $file]))->assertOk()
            ->assertJsonPath('issues', [])->assertJsonPath('bir_warnings', [])
            ->assertJsonPath('targets.1.before', '0.00')->assertJsonPath('targets.1.after', '-1714.29')
            ->assertJsonPath('targets.1.income_after', '-171429.00');
        $this->assertNotEmpty($review->json('negative_balance_warnings'));
        $this->assertNotEmpty($review->json('token'));
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $review->json('token')])->assertUnprocessable();
        $this->assertSame('0.00', $acersteel->fresh()->tax_withheld);
        $body = ['review_version' => 4, 'token' => $review->json('token'), 'acknowledge_warnings' => true];
        $this->postJson('/expanded-dm/apply', $body)->assertOk();
        $this->postJson('/expanded-dm/apply', $body)->assertOk();
        $this->assertSame('-1714.29', $acersteel->fresh()->tax_withheld);
        $this->assertSame('-171429.00', $acersteel->fresh()->income_payment);
        $this->assertDatabaseCount('expanded_dm_applications', 1);
        $this->assertDatabaseCount('expanded_dm_references', 3);
    }

    public function test_over_balance_deduction_warns_and_saves_negative_amounts_once(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry(['tax_withheld' => '100.00', 'income_payment' => '10000.00']);
        $review = $this->postJson('/expanded-dm/review', $this->payload())->assertOk()
            ->assertJsonPath('issues', [])->assertJsonPath('bir_warnings', [])
            ->assertJsonPath('targets.0.after', '-1061.36')->assertJsonPath('targets.0.income_after', '-106136.00');
        $this->assertNotEmpty($review->json('negative_balance_warnings'));
        $this->assertNotEmpty($review->json('token'));
        $this->assertSame('100.00', $entry->fresh()->tax_withheld);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $review->json('token')])->assertUnprocessable();
        $body = ['review_version' => 4, 'token' => $review->json('token'), 'acknowledge_warnings' => true];
        $this->postJson('/expanded-dm/apply', $body)->assertOk();
        $this->postJson('/expanded-dm/apply', $body)->assertOk();
        $this->assertSame('-1061.36', $entry->fresh()->tax_withheld);
        $this->assertSame('-106136.00', $entry->fresh()->income_payment);
        $this->assertSame([], app(BirExpandedWtaxRowValidator::class)->validate($entry->fresh()->toBirExpandedRow(), 0));
        $this->assertDatabaseCount('expanded_dm_applications', 1);
        $this->assertDatabaseCount('expanded_dm_references', 2);
    }

    public function test_mixed_sources_and_existing_negative_totals_preserve_full_deduction(): void
    {
        $this->actingAs(User::factory()->create());
        $negative = $this->entry(['tax_withheld' => '-200.00', 'income_payment' => '-20000.00']);
        $positive = $this->entry(['tax_withheld' => '100.00', 'income_payment' => '10000.00']);
        $review = $this->postJson('/expanded-dm/review', $this->payload())->assertOk()
            ->assertJsonPath('issues', [])->assertJsonPath('targets.0.before', '-100.00')
            ->assertJsonPath('targets.0.after', '-1261.36')->assertJsonPath('targets.0.income_after', '-126136.00');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $review->json('token'), 'acknowledge_warnings' => true])->assertOk();
        $this->assertSame('-200.00', $negative->fresh()->tax_withheld);
        $this->assertSame('-1061.36', $positive->fresh()->tax_withheld);
        $this->assertSame('-106136.00', $positive->fresh()->income_payment);
    }

    public function test_income_only_negative_result_is_a_warning_not_a_block(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry(['income_payment' => '10000.00']);
        $review = $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('issues', [])
            ->assertJsonPath('targets.0.after', '9523.50')->assertJsonPath('targets.0.income_after', '-106136.00');
        $this->assertNotEmpty($review->json('negative_balance_warnings'));
        $this->assertNotEmpty($review->json('bir_warnings'));
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $review->json('token'), 'acknowledge_warnings' => true])->assertOk();
        $this->assertSame('-106136.00', $entry->fresh()->income_payment);
    }

    public function test_allocation_reduces_tax_once_in_id_order(): void
    {
        $first = $this->entry(['tax_withheld' => '1000.00']);
        $second = $this->entry(['tax_withheld' => '9684.86']);
        $file = $this->workbook();
        $parsed = app(ExpandedWtaxDmReader::class)->read($file->getRealPath(), '2026-09', '1.00');
        $review = app(ExpandedWtaxDmAdjustmentService::class)->review($parsed, ExpandedWtaxEntry::orderBy('id')->get());
        $this->assertSame([
            ['id' => $first->id, 'before' => '1000.00', 'after' => '0.00', 'income_before' => '1068486.00', 'income_after' => '968486.00'],
            ['id' => $second->id, 'before' => '9684.86', 'after' => '9523.50', 'income_before' => '1068486.00', 'income_after' => '1052350.00'],
        ], $review['changes']);
    }

    public function test_review_requires_authentication(): void
    {
        $this->postJson('/expanded-dm/review', [])->assertUnauthorized();
    }

    public function test_apply_changes_both_amounts_and_retry_cannot_deduct_twice(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $review = $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('apply_available', true);
        $confirmation = ['review_version' => 4, 'token' => $review->json('token'), 'acknowledge_warnings' => true];
        $this->postJson('/expanded-dm/apply', $confirmation)->assertOk();
        $this->postJson('/expanded-dm/apply', $confirmation)->assertOk();
        $this->assertSame('9523.50', $entry->fresh()->tax_withheld);
        $this->assertSame('952350.00', $entry->fresh()->income_payment);
        $this->assertDatabaseCount('expanded_wtax_entries', 1);
        $this->assertDatabaseCount('expanded_dm_applications', 1);
        $this->assertDatabaseCount('expanded_dm_references', 2);
        $row = ExpandedWtaxEntry::consolidate([$entry->fresh()])->first();
        $this->assertSame([], app(BirExpandedWtaxRowValidator::class)->validate($row, 0));
        $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('token', null);
    }

    public function test_stale_and_expired_reviews_cannot_write(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $entry->update(['tax_withheld' => '10000.00']);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true])->assertUnprocessable();
        $this->assertSame('10000.00', $entry->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_applications', 0);
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->travel(21)->minutes();
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true])->assertUnprocessable();
        $this->assertSame('10000.00', $entry->fresh()->tax_withheld);
    }

    public function test_old_tax_only_preview_is_rejected_before_writing(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $pending = Cache::get('expanded-dm:'.$token);
        unset($pending['calculation_version']);
        Cache::put('expanded-dm:'.$token, $pending, 1200);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token])->assertUnprocessable();
        $this->assertSame('1068486.00', $entry->fresh()->income_payment);
        $this->assertSame('10684.86', $entry->fresh()->tax_withheld);
    }

    public function test_fractional_rate_rounding_preserves_total_base_deduction(): void
    {
        $first = $this->entry(['tax_rate' => '1.50', 'income_payment' => '0.67', 'tax_withheld' => '0.01']);
        $second = $this->entry(['tax_rate' => '1.50', 'income_payment' => '10.00', 'tax_withheld' => '0.15']);
        $parsed = ['items' => [['row' => 5, 'supplier' => $first->payee_name, 'reference' => 'DM#1', 'debit_cents' => 2]], 'excluded_count' => 0, 'excluded_debit' => '0.00'];
        $review = app(ExpandedWtaxDmAdjustmentService::class)->review($parsed, collect([$first, $second]));
        $this->assertSame('1.33', $review['targets'][0]['income_deduction']);
        $this->assertSame('9.34', $review['targets'][0]['income_after']);
        $this->assertStringNotContainsString('does not match income_payment', implode(' ', $review['warnings']));
    }

    public function test_warning_confirmation_and_token_ownership_are_required(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry(['income_payment' => '2000000.00']);
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token])->assertUnprocessable();
        $this->actingAs(User::factory()->create());
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true])->assertForbidden();
        $this->assertSame('10684.86', $entry->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_references', 0);
    }

    public function test_second_review_and_replaced_records_do_not_bypass_reference_history(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $first = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $second = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $first, 'acknowledge_warnings' => true])->assertOk();
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $second, 'acknowledge_warnings' => true])->assertUnprocessable();
        $entry->delete();
        $this->entry();
        $review = $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('token', null);
        $review->assertJsonPath('entries.0.status', 'already_applied')->assertJsonPath('summary.eligible_total', '0.00');
    }

    public function test_failed_source_save_rolls_back_amounts_and_reference_history(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->entry(['tax_withheld' => '1000.00']);
        $second = $this->entry(['tax_withheld' => '9684.86']);
        $token = $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $this->workbook([['09/30/2026', 'DM#998', 'UNMATCHED', null, 100]])]))->json('token');
        ExpandedWtaxEntry::updating(function ($record) use ($second) {
            if ($record->id === $second->id) {
                throw new \RuntimeException('Simulated save failure');
            }
        });
        try {
            $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_warnings' => true, 'acknowledge_skipped' => true])->assertStatus(500);
        } finally {
            ExpandedWtaxEntry::flushEventListeners();
        }
        $this->assertSame('1000.00', $first->fresh()->tax_withheld);
        $this->assertSame('9684.86', $second->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_applications', 0);
        $this->assertDatabaseCount('expanded_dm_references', 0);
    }

    public function test_three_dm_fixture_produces_the_agreed_supplier_totals(): void
    {
        $this->actingAs(User::factory()->create());
        $amounts = [27.28, 263, 656.25, 40.18, 101.32, 2208.05, 24.76, 150.48, 792.21, 132.14, 121.88, 811.97, 139.55, 139.32, 1240.61, 451.07, 28.59, 1047.32, 99.06, 39.28, 91.66, 59.57, 1691.01, 54.63, 273.67];
        foreach ($amounts as $amount) {
            $this->entry(['tax_withheld' => $amount, 'income_payment' => $amount * 100]);
        }
        $this->entry(['payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC', 'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC', 'payee_tin' => '007086184', 'tax_withheld' => '2990.26', 'income_payment' => '299026.00']);
        $file = $this->workbook([['09/14/2026', 'DM (REG)#000257', 'ACERSTEEL INDUSTRIAL SALES, INC.', null, 1714.29]]);
        $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $file]))->assertOk()
            ->assertJsonPath('dm_count', 3)->assertJsonPath('total_deduction', '2875.65')
            ->assertJsonPath('targets.0.source_count', 25)->assertJsonPath('targets.0.after', '9523.50')
            ->assertJsonPath('targets.1.after', '1275.97')->assertJsonPath('issues', []);
    }

    public function test_mixed_review_applies_only_eligible_entries_and_retries_same_outcome(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $old = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $old])->assertOk();
        $file = fn () => $this->workbook([
            ['09/30/2026', 'DM#999', 'WORLD BEST IND L SALES INC', null, 100],
            ['09/30/2026', 'DM#998', 'AMADA CORPORATION', null, 300],
        ], true);
        $review = $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $file()]))->assertOk()
            ->assertJsonPath('review_version', 4)->assertJsonPath('issues', [])
            ->assertJsonPath('summary.file_total', '1561.36')->assertJsonPath('summary.eligible_total', '100.00')
            ->assertJsonPath('summary.skipped_total', '1461.36')->assertJsonPath('summary.eligible_count', 1)
            ->assertJsonPath('summary.skipped_count', 3)->assertJsonPath('entries.0.status', 'already_applied')
            ->assertJsonPath('entries.3.status', 'no_matching_record')->assertJsonPath('targets.0.before', '9523.50')
            ->assertJsonPath('targets.0.after', '9423.50')->assertJsonPath('total_deduction', '100.00');
        $this->assertCount(2, $review->json('supplier_groups'));
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $review->json('token')])->assertUnprocessable();
        $this->assertSame('9523.50', $entry->fresh()->tax_withheld);
        $body = ['review_version' => 4, 'token' => $review->json('token'), 'acknowledge_skipped' => true];
        $saved = $this->postJson('/expanded-dm/apply', $body)->assertOk()->assertJsonPath('summary.eligible_total', '100.00');
        $this->postJson('/expanded-dm/apply', $body)->assertExactJson($saved->json());
        $this->assertSame('9423.50', $entry->fresh()->tax_withheld);
        $this->assertSame('942350.00', $entry->fresh()->income_payment);
        $this->assertDatabaseCount('expanded_dm_applications', 2);
        $this->assertDatabaseCount('expanded_dm_references', 3);
        $application = DB::table('expanded_dm_applications')->orderByDesc('id')->first();
        $this->assertEquals(100, $application->total_deduction);
        $this->assertTrue(json_decode($application->details, true)['acknowledgments']['skipped']);
        $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $file()]))->assertOk()
            ->assertJsonPath('token', null)->assertJsonPath('targets', [])->assertJsonPath('summary.eligible_total', '0.00');
        $amada = $this->entry(['payee_name' => 'AMADA CORPORATION', 'company_name' => 'AMADA CORPORATION', 'payee_tin' => '123456789']);
        $next = $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $file()]))->assertOk()
            ->assertJsonPath('summary.eligible_total', '300.00')->assertJsonPath('summary.already_applied_count', 3);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $next->json('token'), 'acknowledge_skipped' => true])->assertOk();
        $this->assertSame('10384.86', $amada->fresh()->tax_withheld);
        $this->assertSame('9423.50', $entry->fresh()->tax_withheld);
    }

    public function test_partial_negative_requires_both_acknowledgments(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry(['tax_withheld' => 0, 'income_payment' => 0]);
        $review = $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $this->workbook([
            ['09/30/2026', 'DM#998', 'AMADA CORPORATION', null, 300],
        ])]))->assertOk()->assertJsonPath('summary.eligible_total', '1161.36');
        foreach ([['acknowledge_skipped' => true], ['acknowledge_warnings' => true]] as $ack) {
            $this->postJson('/expanded-dm/apply', $ack + ['review_version' => 4, 'token' => $review->json('token')])->assertUnprocessable();
        }
        $this->assertSame('0.00', $entry->fresh()->tax_withheld);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $review->json('token'), 'acknowledge_skipped' => true, 'acknowledge_warnings' => true])->assertOk();
        $this->assertSame('-1161.36', $entry->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_references', 2);
    }

    public function test_distinct_matching_reasons_and_empty_review_do_not_create_history(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('token', null)
            ->assertJsonPath('entries.0.status', 'no_matching_record')->assertJsonPath('summary.skipped_total', '1161.36');
        $entry = $this->entry(['payee_tin' => '000000000']);
        $this->postJson('/expanded-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'invalid_tin');
        $entry->update(['payee_tin' => '000227599']);
        $second = $this->entry(['payee_tin' => '123456789']);
        $this->postJson('/expanded-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'ambiguous_tin');
        $second->update(['payee_tin' => '000227599', 'atc_code' => 'WI158']);
        $this->postJson('/expanded-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'ambiguous_group');
        $second->update(['atc_code' => 'WC158', 'payee_branch_code' => '0001']);
        $this->postJson('/expanded-dm/review', $this->payload())->assertJsonPath('entries.0.status', 'conflicting_identity');
        $this->assertDatabaseCount('expanded_dm_applications', 0);
        $this->assertDatabaseCount('expanded_dm_references', 0);
    }

    public function test_reference_state_change_without_source_change_invalidates_review(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $entry = $this->entry();
        $review = $this->postJson('/expanded-dm/review', $this->payload())->assertOk();
        $application = DB::table('expanded_dm_applications')->insertGetId(['token' => (string) Str::uuid(), 'user_id' => $user->id,
            'scope' => json_encode($review->json('scope')), 'details' => '{}', 'file_digest' => str_repeat('a', 64), 'total_deduction' => 1, 'created_at' => now()]);
        DB::table('expanded_dm_references')->insert(['application_id' => $application, 'identity' => $review->json('entries.0.key'), 'reference' => 'DM(REG)#000252']);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $review->json('token'), 'acknowledge_skipped' => true])->assertUnprocessable();
        $this->assertSame('10684.86', $entry->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_applications', 1);
    }

    public function test_historical_completed_token_without_versioned_details_still_retries(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->entry();
        $review = $this->postJson('/expanded-dm/review', $this->payload());
        $token = $review->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token])->assertOk();
        DB::table('expanded_dm_applications')->where('token', $token)->update(['details' => '{}']);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token])->assertOk()->assertJsonPath('summary', null);
        $this->assertDatabaseCount('expanded_dm_applications', 1);
    }

    public function test_old_client_cannot_confirm_new_preview_and_scope_link_filters_records(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $this->entry(['withholding_agent_tin' => '123456789']);
        $this->entry(['withholding_agent_branch_code' => '0001']);
        $this->entry(['report_type' => 'annual']);
        $this->entry(['tax_rate' => '2.00']);
        $review = $this->postJson('/expanded-dm/review', $this->payload());
        $this->postJson('/expanded-dm/apply', ['token' => $review->json('token')])->assertUnprocessable()->assertJsonValidationErrors('review_version');
        $this->assertSame('10684.86', $entry->fresh()->tax_withheld);
        $this->get('/records/expanded-wtax?period=2026-09&withholding_agent_tin=008791976&withholding_agent_branch_code=0000&report_type=quarterly&tax_rate=1.00')
            ->assertOk()->assertInertia(fn ($page) => $page->has('expandedWtaxEntries.data', 1)
            ->where('withholdingTaxRateSummary.total_tax_withheld', '10684.86')
            ->where('filters.withholding_agent_tin', '008791976')->where('months.0.records_count', 1));
    }

    public function test_partial_application_is_superseded_by_regular_replacement(): void
    {
        $this->actingAs(User::factory()->create());
        $this->entry();
        $payload = fn () => $this->payload(['excel_file' => $this->workbook([['09/30/2026', 'DM#998', 'AMADA CORPORATION', null, 300]])]);
        $token = $this->postJson('/expanded-dm/review', $payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_skipped' => true])->assertOk();
        $this->assertDatabaseCount('expanded_dm_references', 2);
        $this->regularUpload()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('expanded_dm_references', 0);
        $this->assertNotNull(DB::table('expanded_dm_applications')->first()->superseded_at);
        $this->postJson('/expanded-dm/apply', ['token' => $token])->assertUnprocessable();
        $this->postJson('/expanded-dm/review', $payload())->assertJsonPath('summary.eligible_total', '1161.36')->assertJsonPath('summary.needs_review_count', 1);
    }

    public function test_partial_review_cannot_expand_after_new_match_is_added(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $token = $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $this->workbook([['09/30/2026', 'DM#998', 'AMADA CORPORATION', null, 300]])]))->json('token');
        $this->entry(['payee_name' => 'AMADA CORPORATION', 'company_name' => 'AMADA CORPORATION', 'payee_tin' => '123456789']);
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token, 'acknowledge_skipped' => true])->assertUnprocessable();
        $this->assertSame('10684.86', $entry->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_applications', 0);
    }

    public function test_changed_already_applied_document_never_creates_difference_adjustment(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['review_version' => 4, 'token' => $token])->assertOk();
        $scope = ['withholding_agent_tin' => '008791976', 'withholding_agent_branch_code' => '0000', 'report_type' => 'quarterly', 'reporting_month' => '2026-09', 'percentage' => '1.00'];
        $review = app(ExpandedWtaxDmReviewService::class)->review($scope, ['items' => [
            ['row' => 4, 'reference' => 'DM(REG)#000252', 'supplier' => 'DIFFERENT SUPPLIER', 'debit_cents' => 999999],
        ], 'excluded_count' => 0, 'excluded_debit' => '0.00'], collect([$entry->fresh()]));
        $this->assertSame('already_applied', $review['entries'][0]['status']);
        $this->assertSame('0.00', $review['total_deduction']);
        $this->assertSame([], $review['changes']);
        $this->assertSame([], $review['warnings']);
        $this->assertSame('9999.99', $review['summary']['skipped_total']);
    }

    public function test_invalid_dm_amount_date_and_unsupported_report_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([['08/14/2026', 10], ['09/31/2026', 10], ['09/14/2026', -1], ['09/14/2026', 1.234]] as [$date, $amount]) {
            $file = $this->workbook([[$date, 'DM (REG)#000257', 'TEST', null, $amount]]);
            $this->postJson('/expanded-dm/review', $this->payload(['excel_file' => $file]))->assertUnprocessable()->assertJsonValidationErrors('excel_file');
        }
        $this->postJson('/expanded-dm/review', $this->payload(['report_type' => 'annual']))->assertUnprocessable()->assertJsonValidationErrors('report_type');
    }
}
