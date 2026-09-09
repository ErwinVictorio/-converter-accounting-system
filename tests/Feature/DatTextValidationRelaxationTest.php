<?php

namespace Tests\Feature;

use App\Models\SalesVatInput;
use App\Models\User;
use App\Models\VatInput;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatPackageAssertions;
use Tests\TestCase;

class DatTextValidationRelaxationTest extends TestCase
{
    use RefreshDatabase;
    use DatPackageAssertions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // The production period queries use MySQL DATE_FORMAT.
        DB::connection()->getPdo()->sqliteCreateFunction('DATE_FORMAT',
            fn ($date, $format) => Carbon::parse($date)->format($format === '%Y-%m' ? 'Y-m' : 'F Y'), 2);
    }

    public static function textCases(): array
    {
        $cases = [];
        foreach (['purchase', 'sales'] as $type) {
            foreach (['company_name', 'address1', 'address2'] as $field) {
                $cases["{$type} {$field} length"] = [$type, [$field => str_repeat('A', 60)]];
                $cases["{$type} {$field} punctuation"] = [$type, [$field => 'A, B & C']];
            }
        }
        return $cases;
    }

    #[DataProvider('textCases')]
    public function test_text_only_issues_allow_page_and_unchanged_dat_package(string $type, array $overrides): void
    {
        $record = $this->record($type, $overrides);
        $this->get('/generate-datfile?record_type=' . $type)->assertInertia(fn (Assert $page) => $page
            ->component('GenerateDatFile')
            ->where('periodIssues.2026-07.invalid_count', 0)
            ->where('periodIssues.2026-07.errors', []));

        $letter = $type === 'purchase' ? 'P' : 'S';
        $content = $this->datFromPackage(
            $this->get('/download-datfile?period=2026-07-31&record_type=' . $type),
            "008791976{$letter}072026.DAT"
        );
        $company = array_merge(config('bir.companies.008791976'), ['final_header_field' => '12']);
        $rows = $type === 'purchase'
            ? collect([$record->toBirPurchaseRow()])
            : app(\App\Services\BIR\SalesSiCmConsolidator::class)->consolidate(collect([$record]));
        $generator = $type === 'purchase'
            ? app(\App\Services\BIR\ReliefPurchaseDatGenerator::class)
            : app(\App\Services\BIR\ReliefSalesDatGenerator::class);
        $this->assertSame($generator->generate($company, $rows, Carbon::parse('2026-07-31')), $content);
        foreach ($overrides as $field => $value) {
            $this->assertSame($value, $record->fresh()->getAttribute($field));
        }
    }

    public static function blockingCases(): array
    {
        $cases = [];
        foreach (['purchase', 'sales'] as $type) {
            $tin = $type === 'purchase' ? 'tin_number' : 'customer_tin';
            foreach (['', '123', '000000000'] as $value) {
                $cases["{$type} TIN {$value}"] = [$type, [$tin => $value], 'TIN must contain'];
            }
            $cases["{$type} address"] = [$type, ['address1' => ''], 'Address1 is required'];
            $cases["{$type} name"] = [$type, ['company_name' => '', $type === 'purchase' ? 'supplier_name' : 'customer_name' => ''], 'Company name is required'];
            $cases["{$type} type"] = [$type, [$type === 'purchase' ? 'vendor_type' : 'customer_type' => 'invalid'], 'type must be'];
        }
        return $cases;
    }

    #[DataProvider('blockingCases')]
    public function test_real_errors_still_block_even_with_relaxed_text(string $type, array $overrides, string $message): void
    {
        $this->record($type, array_merge(['address2' => str_repeat('A', 60) . ', &'], $overrides));
        $this->get('/generate-datfile?record_type=' . $type)->assertInertia(fn (Assert $page) => $page
            ->where('periodIssues.2026-07.invalid_count', 1)
            ->where('periodIssues.2026-07.errors.0', fn ($error) => str_contains($error, $message)));
        $this->get('/download-datfile?period=2026-07-31&record_type=' . $type)
            ->assertRedirect()
            ->assertSessionHas('error', fn ($error) => str_contains($error, $message)
                && ! str_contains($error, 'must not exceed') && ! str_contains($error, 'ampersand'));
    }

    private function record(string $type, array $overrides)
    {
        $common = ['company_name' => 'TEST COMPANY', 'address1' => 'ADDRESS 1', 'address2' => 'CITY'];
        if ($type === 'purchase') {
            return VatInput::create(array_merge($common, [
                'supplier_name' => 'TEST COMPANY', 'tin_number' => '111222333', 'vendor_type' => 'company',
                'exempt' => 0, 'zero_rated' => 0, 'services' => 1000, 'capital_goods' => 0,
                'other_than_capital_goods' => 0, 'taxable_net_of_vat' => 1000, 'vat_rate' => 12,
                'input_vat' => 120, 'total_purchases' => 1120, 'others' => 0, 'total' => 1120,
                'date_uploaded' => '2026-07-31', 'is_imported' => true, 'is_adjusted' => false,
            ], $overrides));
        }
        return SalesVatInput::create(array_merge($common, [
            'document_no' => 'SI#10001', 'document_type' => 'SI', 'document_date' => '2026-07-31',
            'customer_name' => 'TEST COMPANY', 'customer_tin' => '111222333', 'customer_type' => 'company',
            'gross_amount' => 1120, 'discount' => 0, 'charges' => 0, 'net_amount' => 1120,
            'output_vat' => 120, 'taxable_net_of_vat' => 1000, 'exempt_sales' => 0,
            'zero_rated_sales' => 0, 'reporting_period' => '2026-07-31', 'is_adjusted' => false,
        ], $overrides));
    }
}
