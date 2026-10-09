<?php

namespace Tests\Feature;

use App\Imports\ExpandedWtaxDmReader;
use App\Models\ExpandedWtaxEntry;
use App\Models\User;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use App\Services\ExpandedWtaxDmAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

    private function workbook(array $extra = []): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['ACCOUNT DETAILS'], ['Period Covered: September 1, 2026 - September 30, 2026'],
            ['Date', 'Reference', 'Particulars', 'Other Info', 'Debit', 'Credit', 'Balance'],
            ['221101A WITHHOLDING TAX PAYABLE - EXPANDED 1%', null, null, null, null, 'BEGINNING BALANCE...', 0],
            ['09/08/2026', 'PV (REG)#006689', 'BUREAU OF INTERNAL REVENUE', null, 119648.62],
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

    public function test_over_balance_deduction_is_blocked(): void
    {
        $this->actingAs(User::factory()->create());
        $this->entry(['tax_withheld' => '100.00']);
        $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('token', null);
        $this->assertSame('100.00', ExpandedWtaxEntry::first()->tax_withheld);
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
        $confirmation = ['token' => $review->json('token'), 'acknowledge_warnings' => true];
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
        $this->postJson('/expanded-dm/apply', ['token' => $token, 'acknowledge_warnings' => true])->assertUnprocessable();
        $this->assertSame('10000.00', $entry->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_applications', 0);
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->travel(21)->minutes();
        $this->postJson('/expanded-dm/apply', ['token' => $token, 'acknowledge_warnings' => true])->assertUnprocessable();
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
        $this->postJson('/expanded-dm/apply', ['token' => $token])->assertUnprocessable();
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
        $this->postJson('/expanded-dm/apply', ['token' => $token])->assertUnprocessable();
        $this->actingAs(User::factory()->create());
        $this->postJson('/expanded-dm/apply', ['token' => $token, 'acknowledge_warnings' => true])->assertForbidden();
        $this->assertSame('10684.86', $entry->fresh()->tax_withheld);
        $this->assertDatabaseCount('expanded_dm_references', 0);
    }

    public function test_second_review_and_replaced_records_do_not_bypass_reference_history(): void
    {
        $this->actingAs(User::factory()->create());
        $entry = $this->entry();
        $first = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $second = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        $this->postJson('/expanded-dm/apply', ['token' => $first, 'acknowledge_warnings' => true])->assertOk();
        $this->postJson('/expanded-dm/apply', ['token' => $second, 'acknowledge_warnings' => true])->assertUnprocessable();
        $entry->delete();
        $this->entry();
        $review = $this->postJson('/expanded-dm/review', $this->payload())->assertOk()->assertJsonPath('token', null);
        $this->assertStringContainsString('already applied', implode(' ', $review->json('issues')));
    }

    public function test_failed_source_save_rolls_back_amounts_and_reference_history(): void
    {
        $this->actingAs(User::factory()->create());
        $first = $this->entry(['tax_withheld' => '1000.00']);
        $second = $this->entry(['tax_withheld' => '9684.86']);
        $token = $this->postJson('/expanded-dm/review', $this->payload())->json('token');
        ExpandedWtaxEntry::updating(function ($record) use ($second) {
            if ($record->id === $second->id) {
                throw new \RuntimeException('Simulated save failure');
            }
        });
        try {
            $this->postJson('/expanded-dm/apply', ['token' => $token, 'acknowledge_warnings' => true])->assertStatus(500);
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
