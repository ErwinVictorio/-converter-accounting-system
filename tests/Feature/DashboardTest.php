<?php

namespace Tests\Feature;

use App\Models\ImportationEntry;
use App\Models\SalesVatInput;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The dashboard aggregates Sales, Purchases and Importation for one tax month.
 *
 * Two things these tests exist to protect:
 *
 * 1. Portability. The suite runs on sqlite, so a MySQL-only idiom slipping into
 *    the metrics service (DATE_FORMAT, as used in DatFileController) fails here
 *    rather than in production.
 * 2. The importation/purchase double-count. Every importation entry is mirrored
 *    into vat_inputs; if that mirror leaks into the purchase totals, the same
 *    transaction and its VAT are reported twice.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * tax_month is always stored as the first of the month.
     */
    private function importation(string $taxMonth, array $overrides = []): ImportationEntry
    {
        return ImportationEntry::create(array_merge([
            'tax_month' => $taxMonth . '-01',
            'import_entry_no' => 'C-' . fake()->unique()->numerify('#####'),
            'assessment_date' => $taxMonth . '-10',
            'supplier' => 'SHENZHEN METALS CO.',
            'importation_date' => $taxMonth . '-05',
            'country' => 'CHINA',
            'total_landed_cost' => 1512000.00,
            'dutiable_value' => 1500000.00,
            'charges' => 12000.00,
            'exempt' => 0.00,
            'taxable_goods' => 1512000.00,
            'vat_rate' => 12.00,
            'vat_payable' => 181440.00,
            'or_number' => '987654',
            'payment_date' => $taxMonth . '-12',
        ], $overrides));
    }

    /**
     * date_uploaded holds an arbitrary day inside the reporting month.
     */
    private function purchase(string $taxMonth, array $overrides = []): VatInput
    {
        return VatInput::create(array_merge([
            'supplier_name' => 'LOCAL HARDWARE INC.',
            'tin_number' => '123-456-789-0000',
            'vendor_type' => 'company',
            'company_name' => 'LOCAL HARDWARE INC.',
            'is_imported' => false,
            'exempt' => 0.00,
            'zero_rated' => 0.00,
            'purchase_imported' => 0.00,
            'purchase_local' => 100000.00,
            'services' => 0.00,
            'capital_goods' => 0.00,
            'other_than_capital_goods' => 100000.00,
            'taxable_net_of_vat' => 100000.00,
            'vat_rate' => 12.00,
            'input_vat' => 12000.00,
            'total_purchases' => 112000.00,
            'others' => 0.00,
            'total' => 112000.00,
            'date_uploaded' => $taxMonth . '-18',
            'is_broker' => false,
            'is_adjusted' => false,
        ], $overrides));
    }

    /**
     * Day 28 rather than 30: every month has one, so February fixtures do not
     * silently roll into March.
     */
    private function sale(string $taxMonth, array $overrides = []): SalesVatInput
    {
        return SalesVatInput::create(array_merge([
            'document_no' => 'SI#' . fake()->unique()->numerify('#####'),
            'document_date' => $taxMonth . '-15',
            'customer_name' => 'ACME BUILDERS CORP.',
            'gross_amount' => 250000.00,
            'discount' => 0.00,
            'charges' => 0.00,
            'net_amount' => 224000.00,
            'output_vat' => 24000.00,
            'taxable_net_of_vat' => 200000.00,
            'customer_tin' => '111-222-333-0000',
            'customer_type' => 'company',
            'exempt_sales' => 0.00,
            'zero_rated_sales' => 0.00,
            'reporting_period' => $taxMonth . '-28',
            'is_adjusted' => false,
        ], $overrides));
    }

    /**
     * The whole Inertia prop payload. Read through toArray() rather than
     * AssertableInertia::where() because where() compares strictly, and a
     * rounded money float that lands on a whole number JSON-encodes without a
     * decimal point and decodes as an int.
     */
    private function props(string $query = ''): array
    {
        $page = [];

        $this->get('/' . $query)->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$page) {
            $page = $inertia->toArray()['props'];
        });

        return $page;
    }

    public function test_it_defaults_to_the_previous_tax_month(): void
    {
        $previous = Carbon::now()->startOfMonth()->subMonth();

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('filters.tax_month', $previous->format('Y-m'))
                ->where('monthLabel', $previous->format('F Y'))
        );
    }

    public function test_it_reports_each_module_for_the_selected_month(): void
    {
        $this->sale('2026-04');
        $this->sale('2026-04');
        $this->purchase('2026-04');
        $this->importation('2026-04');

        // A neighbouring month must not leak into any of the three.
        $this->sale('2026-05');
        $this->purchase('2026-05');
        $this->importation('2026-05');

        $stats = $this->props('?tax_month=2026-04')['stats'];

        $this->assertSame(2, $stats['sales']['records']);
        $this->assertEqualsWithDelta(448000.0, $stats['sales']['amount'], 0.001);

        $this->assertSame(1, $stats['purchases']['records']);
        $this->assertEqualsWithDelta(112000.0, $stats['purchases']['amount'], 0.001);

        $this->assertSame(1, $stats['importation']['records']);
        $this->assertEqualsWithDelta(1512000.0, $stats['importation']['amount'], 0.001);
    }

    /**
     * Output VAT 48,000 - (input 12,000 + importation 181,440) = -145,440, i.e.
     * creditable. The sign is reported as-is.
     *
     * The breakdown lives on summary.vat; stats.vat carries only the net position
     * the card shows.
     */
    public function test_combined_vat_nets_output_against_input_and_importation(): void
    {
        $this->sale('2026-04');
        $this->sale('2026-04');
        $this->purchase('2026-04');
        $this->importation('2026-04');

        $props = $this->props('?tax_month=2026-04');
        $vat = $props['summary']['vat'];

        $this->assertEqualsWithDelta(48000.0, $vat['output'], 0.001);
        $this->assertEqualsWithDelta(12000.0, $vat['input'], 0.001);
        $this->assertEqualsWithDelta(181440.0, $vat['importation'], 0.001);
        $this->assertEqualsWithDelta(193440.0, $vat['total_input'], 0.001);
        $this->assertEqualsWithDelta(-145440.0, $vat['net'], 0.001);

        $this->assertEqualsWithDelta(-145440.0, $props['stats']['vat']['net'], 0.001);
    }

    /**
     * The importation mirror row that ImportationController writes into vat_inputs
     * must not be counted as a purchase, or the same peso is reported twice.
     */
    public function test_importation_mirror_rows_are_excluded_from_purchase_totals(): void
    {
        $this->post('/importation', [
            'tax_month' => '2026-04',
            'import_entry_no' => 'C-55555',
            'assessment_date' => '2026-04-10',
            'supplier' => 'Shenzhen Metals Co.',
            'importation_date' => '2026-04-05',
            'country' => 'China',
            'total_landed_cost' => '1512000.00',
            'dutiable_value' => '1500000.00',
            'exempt' => '0.00',
            'vat_rate' => '12.00',
            'vat_payable' => '181440.00',
            'or_number' => '987654',
            'payment_date' => '2026-04-12',
        ])->assertSessionHasNoErrors();

        // The entry did sync into vat_inputs...
        $this->assertNotNull(ImportationEntry::firstOrFail()->vat_input_id);
        $this->assertSame(1, VatInput::count());

        // ...but the dashboard must not see it as a purchase.
        $props = $this->props('?tax_month=2026-04');
        $stats = $props['stats'];
        $vat = $props['summary']['vat'];

        $this->assertSame(0, $stats['purchases']['records']);
        $this->assertEqualsWithDelta(0.0, $stats['purchases']['amount'], 0.001);
        $this->assertEqualsWithDelta(0.0, $vat['input'], 0.001);

        $this->assertSame(1, $stats['importation']['records']);
        $this->assertEqualsWithDelta(181440.0, $vat['importation'], 0.001);
        // Counted once, not twice.
        $this->assertEqualsWithDelta(181440.0, $vat['total_input'], 0.001);
    }

    public function test_the_month_over_month_delta_uses_the_preceding_month(): void
    {
        $this->sale('2026-03');
        $this->sale('2026-04');
        $this->sale('2026-04');

        $stats = $this->props('?tax_month=2026-04')['stats'];

        $this->assertSame(2, $stats['sales']['records']);
        $this->assertSame(1, $stats['sales']['previous_records']);
    }

    public function test_both_charts_cover_all_twelve_months_with_a_series_per_module(): void
    {
        $this->sale('2026-04');
        $this->purchase('2026-04');
        $this->importation('2026-04');
        $this->importation('2026-11');
        // A different year must not appear in this series.
        $this->sale('2025-04');

        $props = $this->props('?tax_month=2026-04');

        $this->assertSame(2026, $props['chartYear']);
        $this->assertCount(12, $props['transactions']);
        $this->assertCount(12, $props['amounts']);

        $transactions = collect($props['transactions'])->keyBy('month');
        $amounts = collect($props['amounts'])->keyBy('month');

        $this->assertSame(1, $transactions['Apr']['sales']);
        $this->assertSame(1, $transactions['Apr']['purchases']);
        $this->assertSame(1, $transactions['Apr']['importation']);

        $this->assertSame(0, $transactions['Jan']['sales']);
        $this->assertSame(1, $transactions['Nov']['importation']);
        $this->assertSame(0, $transactions['Nov']['sales']);

        $this->assertEqualsWithDelta(224000.0, $amounts['Apr']['sales'], 0.001);
        $this->assertEqualsWithDelta(1512000.0, $amounts['Apr']['importation'], 0.001);
        $this->assertEqualsWithDelta(0.0, $amounts['Jan']['purchases'], 0.001);
    }

    public function test_the_monthly_summary_covers_all_three_modules(): void
    {
        $this->sale('2026-04');
        $this->purchase('2026-04');
        $this->importation('2026-04');

        $summary = $this->props('?tax_month=2026-04')['summary'];

        $this->assertEqualsWithDelta(224000.0, $summary['total_sales'], 0.001);
        $this->assertEqualsWithDelta(112000.0, $summary['total_purchases'], 0.001);
        $this->assertEqualsWithDelta(1512000.0, $summary['total_importation'], 0.001);
        $this->assertEqualsWithDelta(24000.0, $summary['vat']['output'], 0.001);
        $this->assertEqualsWithDelta(12000.0, $summary['vat']['input'], 0.001);
        $this->assertEqualsWithDelta(181440.0, $summary['vat']['importation'], 0.001);
    }

    public function test_importation_analytics_are_kept_on_the_dashboard(): void
    {
        $this->importation('2026-04', ['import_entry_no' => 'C-11111']);
        $this->importation('2026-05', ['import_entry_no' => 'C-22222']);

        $this->get('/?tax_month=2026-04')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('recent', 1)
                ->where('recent.0.import_entry_no', 'C-11111')
                ->where('recent.0.tax_month', 'Apr 2026')
                ->where('recent.0.country', 'CHINA')
                // The brief for this table explicitly rules out a Status column.
                ->missing('recent.0.status')
        );
    }

    public function test_the_month_picker_reaches_months_from_every_module(): void
    {
        $oldSale = Carbon::now()->startOfMonth()->subMonths(30);
        $oldImportation = Carbon::now()->startOfMonth()->subMonths(36);

        $this->sale($oldSale->format('Y-m'));
        $this->importation($oldImportation->format('Y-m'));

        $values = collect($this->props()['months'])->pluck('value')->all();

        $this->assertContains($oldSale->format('Y-m'), $values);
        $this->assertContains($oldImportation->format('Y-m'), $values);
    }

    public function test_an_empty_database_reports_zeroes_rather_than_failing(): void
    {
        $props = $this->props();

        $this->assertFalse($props['hasAnyData']);
        $this->assertSame(0, $props['stats']['sales']['records']);
        $this->assertEqualsWithDelta(0.0, $props['stats']['vat']['net'], 0.001);
        $this->assertSame([], $props['recent']);
        $this->assertCount(12, $props['transactions']);
    }

    public function test_a_month_with_no_records_is_distinguished_from_an_empty_database(): void
    {
        $this->sale('2026-04');

        $props = $this->props('?tax_month=2026-09');

        $this->assertTrue($props['hasAnyData']);
        $this->assertSame(0, $props['stats']['sales']['records']);
    }

    public function test_an_unparsable_tax_month_falls_back_instead_of_erroring(): void
    {
        $previous = Carbon::now()->startOfMonth()->subMonth();

        $this->get('/?tax_month=not-a-month')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.tax_month', $previous->format('Y-m')));
    }
}
