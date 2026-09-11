<?php

namespace Tests\Feature;

use App\Models\Brokers;
use App\Models\ExpandedWtaxEntry;
use App\Models\ImportationEntry;
use App\Models\SalesVatInput;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The Record section: one page per data type.
 *
 * What these guard is the split itself -- that each listing reads only its own
 * storage, that Import Data and Importation carry no listing any more, and that
 * the upload and DAT routes they were carved out of still answer. The amounts,
 * consolidation and DAT layouts are covered by their own suites.
 */
class RecordPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function purchase(array $overrides = []): VatInput
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
            'date_uploaded' => '2026-04-18',
            'is_broker' => false,
            'is_adjusted' => false,
        ], $overrides));
    }

    private function sale(array $overrides = []): SalesVatInput
    {
        return SalesVatInput::create(array_merge([
            'document_no' => 'SI#' . fake()->unique()->numerify('#####'),
            'document_date' => '2026-04-15',
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
            'reporting_period' => '2026-04-28',
            'is_adjusted' => false,
        ], $overrides));
    }

    private function withholding(array $overrides = []): ExpandedWtaxEntry
    {
        return ExpandedWtaxEntry::create(array_merge([
            'reporting_period' => '2026-04-28',
            'payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'payee_type' => 'company',
            'payee_tin' => '007086184',
            'payee_branch_code' => '0000',
            'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 3682716.00,
            'tax_withheld' => 36827.16,
        ], $overrides));
    }

    private function importation(array $overrides = []): ImportationEntry
    {
        return ImportationEntry::create(array_merge([
            'sequence_number' => 1,
            'tax_month' => '2026-04-01',
            'import_entry_no' => 'C-12345',
            'assessment_date' => '2026-04-10',
            'supplier' => 'SHENZHEN METALS CO.',
            'importation_date' => '2026-04-05',
            'country' => 'CHINA',
            'total_landed_cost' => 1512000.00,
            'dutiable_value' => 1500000.00,
            'charges' => 12000.00,
            'exempt' => 0.00,
            'taxable_goods' => 1512000.00,
            'vat_rate' => 12.00,
            'vat_payable' => 181440.00,
            'or_number' => '987654',
            'payment_date' => '2026-04-12',
        ], $overrides));
    }

    /**
     * Seed one row of every type, so a listing that reached past its own table
     * would show more rows than it should rather than none.
     */
    private function seedOneOfEach(): void
    {
        $this->purchase();
        $this->sale();
        $this->withholding();
        $this->importation();
    }

    public function test_each_record_page_lists_only_its_own_rows(): void
    {
        $this->seedOneOfEach();

        $this->get('/records/purchases')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Records/PurchaseRecords')
                ->has('vatInputs.data', 1)
                ->missing('salesVatInputs')
                ->missing('expandedWtaxEntries')
                ->missing('entries')
        );

        $this->get('/records/sales')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Records/SalesRecords')
                ->has('salesVatInputs.data', 1)
                ->missing('vatInputs')
                ->missing('expandedWtaxEntries')
                ->missing('entries')
        );

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Records/ExpandedWtaxRecords')
                ->has('expandedWtaxEntries.data', 1)
                ->missing('vatInputs')
                ->missing('salesVatInputs')
                ->missing('entries')
        );

        $this->get('/records/importations')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Records/ImportationRecords')
                ->has('entries.data', 1)
                ->missing('vatInputs')
                ->missing('salesVatInputs')
                ->missing('expandedWtaxEntries')
        );
    }

    public function test_import_data_carries_the_upload_form_only(): void
    {
        $this->seedOneOfEach();

        $this->get('/records')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('RecordEntry')
                ->has('birCompanies')
                ->missing('vatInputs')
                ->missing('salesVatInputs')
                ->missing('expandedWtaxEntries')
        );
    }

    public function test_the_importation_screen_carries_the_entry_form_only(): void
    {
        $this->seedOneOfEach();

        $this->get('/importation')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Importation')
                ->missing('entries')
                ->missing('months')
        );
    }

    /**
     * The listings were carved out of these two screens, so the routes they were
     * carved out of are what must still answer.
     *
     * The generate screen is checked on its expanded branch: the purchase branch
     * builds its month list with DATE_FORMAT, which sqlite has no function for,
     * and that query belongs to DAT generation rather than to this split.
     */
    public function test_the_upload_and_generate_routes_still_answer(): void
    {
        $this->seedOneOfEach();

        $this->get('/generate-datfile?record_type=expanded')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('GenerateDatFile')
                ->has('availablePeriods')
                ->missing('vatInputs')
                ->missing('salesVatInputs')
                ->missing('expandedWtaxEntries')
        );

        // Uploading is a POST to its own route, unchanged by the reorganization.
        $this->post('/vat-import', [])->assertSessionHasErrors('excel_file');
    }

    public function test_purchase_records_can_be_searched(): void
    {
        $this->purchase();
        $this->purchase(['supplier_name' => 'CEBU STEEL TRADING', 'tin_number' => '222-333-444-0000']);

        $this->get('/records/purchases?search=CEBU')->assertOk()->assertInertia(
            fn ($page) => $page->has('vatInputs.data', 1)
        );

        $this->get('/records/purchases?search=222-333-444')->assertOk()->assertInertia(
            fn ($page) => $page->has('vatInputs.data', 1)
        );
    }

    public function test_purchase_records_can_be_filtered_by_period(): void
    {
        $this->purchase();
        $this->purchase([
            'supplier_name' => 'MAY STEEL SUPPLY',
            'tin_number' => '222-333-444-0000',
            'date_uploaded' => '2026-05-31',
        ]);

        $this->get('/records/purchases')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('vatInputs.data', 2)
                ->has('months', 2)
                ->where('months.0.value', '2026-05')
                ->where('months.0.label', 'May 2026')
                ->where('months.0.records_count', 1)
        );

        $this->get('/records/purchases?period=2026-04')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('vatInputs.data', 1)
                ->where('filters.period', '2026-04')
                ->where('vatInputs.data.0.supplier_name', 'LOCAL HARDWARE INC.')
        );
    }

    public function test_purchase_bir_info_update_rejects_company_and_address_values_past_bir_limits(): void
    {
        $purchase = $this->purchase();

        $this->put("/records/{$purchase->id}/bir-info", [
            'vendor_type' => 'company',
            'tin_number' => '123-456-789-000',
            'company_name' => str_repeat('A', 51),
            'address1' => str_repeat('B', 31),
            'address2' => str_repeat('C', 31),
        ])->assertSessionHasErrors(['company_name', 'address1', 'address2']);
    }

    public function test_broker_adjustment_rejects_company_and_address_values_past_bir_limits(): void
    {
        $purchase = $this->purchase([
            'tin_number' => '123-456-789-000',
            'services' => 100.00,
            'total' => 100.00,
        ]);

        Brokers::create([
            'broker_name' => 'LOCAL HARDWARE INC.',
            'tin_number' => '123-456-789-000',
        ]);

        $this->put("/records/{$purchase->id}", [
            'supplier_name' => str_repeat('A', 51),
            'tin_number' => '123-456-789-000',
            'vendor_type' => 'company',
            'company_name' => str_repeat('A', 51),
            'address1' => str_repeat('B', 31),
            'address2' => str_repeat('C', 31),
            'is_imported' => false,
            'purchase_imported' => 0,
            'purchase_local' => 0,
            'services' => 50,
            'others' => 0,
        ])->assertSessionHasErrors(['supplier_name', 'company_name', 'address1', 'address2']);
    }

    public function test_sales_records_can_be_searched(): void
    {
        $this->sale();
        $this->sale(['customer_name' => 'DAVAO CONSTRUCTION CORP.', 'customer_tin' => '444-555-666-0000']);

        $this->get('/records/sales?search=DAVAO')->assertOk()->assertInertia(
            fn ($page) => $page->has('salesVatInputs.data', 1)
        );
    }

    public function test_sales_records_can_be_filtered_by_period(): void
    {
        $this->sale();
        $this->sale([
            'customer_name' => 'MAY BUILDERS CORP.',
            'customer_tin' => '444-555-666-0000',
            'reporting_period' => '2026-05-31',
        ]);

        $this->get('/records/sales')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('salesVatInputs.data', 2)
                ->has('months', 2)
                ->where('months.0.value', '2026-05')
                ->where('months.0.label', 'May 2026')
                ->where('months.0.records_count', 1)
        );

        $this->get('/records/sales?period=2026-05')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('salesVatInputs.data', 1)
                ->where('filters.period', '2026-05')
                ->where('salesVatInputs.data.0.customer_name', 'MAY BUILDERS CORP.')
        );
    }

    public function test_expanded_records_can_be_filtered_by_period(): void
    {
        $this->withholding();
        $this->withholding([
            'reporting_period' => '2026-05-31',
            'payee_name' => 'MAY CONTRACTOR INC',
            'payee_tin' => '222333444',
            'company_name' => 'MAY CONTRACTOR INC',
        ]);

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('expandedWtaxEntries.data', 2)
                ->has('months', 2)
                ->where('months.0.value', '2026-05')
                ->where('months.0.label', 'May 2026')
                ->where('months.0.records_count', 1)
        );

        $this->get('/records/expanded-wtax?period=2026-05')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('expandedWtaxEntries.data', 1)
                ->where('filters.period', '2026-05')
                ->where('expandedWtaxEntries.data.0.payee_name', 'MAY CONTRACTOR INC')
        );
    }

    public function test_record_period_filters_keep_search_scoped(): void
    {
        $this->purchase();
        $this->purchase([
            'supplier_name' => 'MAY STEEL SUPPLY',
            'tin_number' => '222-333-444-0000',
            'date_uploaded' => '2026-05-31',
        ]);
        $this->purchase([
            'supplier_name' => 'MAY OFFICE SUPPLY',
            'tin_number' => '555-666-777-0000',
            'date_uploaded' => '2026-05-31',
        ]);

        $this->get('/records/purchases?period=2026-05&search=STEEL')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('vatInputs.data', 1)
                ->where('filters.period', '2026-05')
                ->where('filters.search', 'STEEL')
                ->where('vatInputs.data.0.supplier_name', 'MAY STEEL SUPPLY')
        );
    }

    /**
     * The month options above the importation table, and the filter they drive.
     */
    public function test_importation_records_can_be_filtered_by_tax_month(): void
    {
        $this->importation();
        $this->importation([
            'sequence_number' => 1,
            'tax_month' => '2026-05-01',
            'import_entry_no' => 'C-55555',
        ]);

        $this->get('/records/importations')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 2)
                ->has('months', 2)
                ->where('months.0.value', '2026-05')
                ->where('months.0.label', 'May 2026')
                ->where('months.0.records_count', 1)
        );

        $this->get('/records/importations?tax_month=2026-04')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('filters.tax_month', '2026-04')
                ->where('entries.data.0.import_entry_no', 'C-12345')
        );
    }

    public function test_importation_records_can_be_searched_across_columns(): void
    {
        $this->importation();
        $this->importation([
            'sequence_number' => 2,
            'tax_month' => '2026-07-01',
            'import_entry_no' => 'C-77777',
            'assessment_date' => '2026-07-11',
            'supplier' => 'PACIFIC PARTS LTD.',
            'importation_date' => '2026-07-06',
            'country' => 'JAPAN',
            'total_landed_cost' => 180000.00,
            'dutiable_value' => 150000.00,
            'charges' => 30000.00,
            'exempt' => 20000.00,
            'taxable_goods' => 160000.00,
            'vat_rate' => 12.00,
            'vat_payable' => 19200.00,
            'or_number' => 'OR-777',
            'payment_date' => '2026-07-15',
        ]);

        $this->get('/records/importations?search=PACIFIC')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.import_entry_no', 'C-77777')
                ->where('filters.search', 'PACIFIC')
        );

        $this->get('/records/importations?search=30,000')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.import_entry_no', 'C-77777')
        );

        $this->get('/records/importations?tax_month=2026-07&search=OR-777')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('entries.data', 1)
                ->where('filters.tax_month', '2026-07')
                ->where('filters.search', 'OR-777')
                ->where('entries.data.0.import_entry_no', 'C-77777')
        );
    }

    public function test_expanded_records_flag_rows_with_missing_id_or_tin(): void
    {
        $this->withholding([
            'payee_name' => 'SAMSON, RAM ELDRICH CELESTINO',
            'payee_type' => 'individual',
            'payee_tin' => '4',
            'company_name' => null,
            'last_name' => 'SAMSON',
            'first_name' => 'RAM ELDRICH CELESTINO',
            'atc_code' => 'WI516',
            'tax_rate' => 10.00,
            'income_payment' => 791.30,
            'tax_withheld' => 79.13,
        ]);

        $this->get('/records/expanded-wtax?search=SAMSON')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Records/ExpandedWtaxRecords')
                ->where('expandedWtaxEntries.data.0.payee_name', 'SAMSON, RAM ELDRICH CELESTINO')
                ->where('expandedWtaxEntries.data.0.invalid_count', 1)
                ->where('expandedWtaxEntries.data.0.has_missing_id', true)
                ->where('expandedWtaxEntries.data.0.validation_errors.0', 'payee_tin must contain at least 9 digits.')
        );
    }

    /**
     * A spread of rates in one month, holding the shapes the rate summary has to
     * get right: two payees on one rate, a fractional rate, centavo amounts, and
     * a reversal that cancels its own rate back to zero.
     *
     * Rate totals, in the order the cards must appear:
     *
     *   1.00 -> 38515.97    1.50 -> 79.13    2.00 -> 12345.67
     *   5.00 -> 0.30       10.00 -> 0.00
     *
     * which is 50941.07 across every row the table lists.
     */
    private function seedRateSpread(): void
    {
        $this->withholding([
            'payee_name' => 'ALPHA STEEL INC',
            'company_name' => 'ALPHA STEEL INC',
            'payee_tin' => '111111111',
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 3682716.00,
            'tax_withheld' => 36827.16,
        ]);

        $this->withholding([
            'payee_name' => 'BRAVO HARDWARE INC',
            'company_name' => 'BRAVO HARDWARE INC',
            'payee_tin' => '222222222',
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 168881.00,
            'tax_withheld' => 1688.81,
        ]);

        /*
         * 1.5% is not the rate of any allowed ATC, so this row also carries a
         * validation warning. Its amount still belongs in the totals: a warning
         * says the row needs BIR info before it can be filed, not that the
         * uploaded amount is wrong, and the table lists it either way.
         */
        $this->withholding([
            'payee_name' => 'CHARLIE FREIGHT INC',
            'company_name' => 'CHARLIE FREIGHT INC',
            'payee_tin' => '333333333',
            'atc_code' => 'WC158',
            'tax_rate' => 1.50,
            'income_payment' => 5275.33,
            'tax_withheld' => 79.13,
        ]);

        $this->withholding([
            'payee_name' => 'DELTA RENTALS INC',
            'company_name' => 'DELTA RENTALS INC',
            'payee_tin' => '444444444',
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
            'income_payment' => 617283.50,
            'tax_withheld' => 12345.67,
        ]);

        // Two centavo amounts on one rate: 0.10 + 0.20 is the float sum that
        // lands on 0.30000000000000004.
        $this->withholding([
            'payee_name' => 'ECHO SERVICES INC',
            'company_name' => 'ECHO SERVICES INC',
            'payee_tin' => '555555555',
            'atc_code' => 'WC100',
            'tax_rate' => 5.00,
            'income_payment' => 2.00,
            'tax_withheld' => 0.10,
        ]);

        $this->withholding([
            'payee_name' => 'FOXTROT SERVICES INC',
            'company_name' => 'FOXTROT SERVICES INC',
            'payee_tin' => '666666666',
            'atc_code' => 'WC100',
            'tax_rate' => 5.00,
            'income_payment' => 4.00,
            'tax_withheld' => 0.20,
        ]);

        // A payment and its reversal consolidate into one line at zero. The rate
        // still has matching records, so it still gets a card.
        foreach ([25800.00, -25800.00] as $income) {
            $this->withholding([
                'payee_name' => 'GOLF CONSULTING INC',
                'company_name' => 'GOLF CONSULTING INC',
                'payee_tin' => '777777777',
                'atc_code' => 'WC139',
                'tax_rate' => 10.00,
                'income_payment' => $income,
                'tax_withheld' => round($income * 0.10, 2),
            ]);
        }
    }

    public function test_expanded_records_total_tax_withheld_per_rate(): void
    {
        $this->seedRateSpread();

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Records/ExpandedWtaxRecords')
                // Seven consolidated lines out of eight uploaded rows.
                ->has('expandedWtaxEntries.data', 7)
                ->has('withholdingTaxRateSummary.rates', 5)
                /*
                 * Ascending by rate as a number. Sorted as strings, "10.00"
                 * would file between "1.00" and "2.00" and the cards would read
                 * 1%, 10%, 1.5%, 2%, 5%.
                 */
                ->where('withholdingTaxRateSummary.rates.0.tax_rate', '1.00')
                ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '38515.97')
                ->where('withholdingTaxRateSummary.rates.1.tax_rate', '1.50')
                ->where('withholdingTaxRateSummary.rates.1.tax_withheld_total', '79.13')
                ->where('withholdingTaxRateSummary.rates.2.tax_rate', '2.00')
                ->where('withholdingTaxRateSummary.rates.2.tax_withheld_total', '12345.67')
                ->where('withholdingTaxRateSummary.rates.3.tax_rate', '5.00')
                ->where('withholdingTaxRateSummary.rates.3.tax_withheld_total', '0.30')
                // A rate whose records cancel out keeps its card at zero.
                ->where('withholdingTaxRateSummary.rates.4.tax_rate', '10.00')
                ->where('withholdingTaxRateSummary.rates.4.tax_withheld_total', '0.00')
        );
    }

    /**
     * The cards are a partition of the filtered listing: every centavo in the
     * table is on exactly one card, and no centavo is on one twice.
     */
    public function test_expanded_rate_totals_account_for_the_whole_filtered_listing(): void
    {
        $this->seedRateSpread();

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia(
            fn ($page) => $page->where(
                'withholdingTaxRateSummary.rates',
                function ($rates) {
                    $this->assertSame(
                        (int) round((float) ExpandedWtaxEntry::sum('tax_withheld') * 100),
                        $rates->sum(fn (array $rate) => (int) round((float) $rate['tax_withheld_total'] * 100)),
                        'The rate cards must add up to the Tax Withheld sum of the filtered rows.'
                    );

                    return true;
                }
            )
        );
    }

    /**
     * A consolidated line is one amount, not one amount per row merged into it.
     */
    public function test_expanded_rate_totals_count_a_merged_line_once(): void
    {
        foreach ([10011.00, 20022.00, 30033.00] as $income) {
            $this->withholding([
                'payee_name' => 'MERGED SUPPLY INC',
                'company_name' => 'MERGED SUPPLY INC',
                'payee_tin' => '888888888',
                'atc_code' => 'WC158',
                'tax_rate' => 1.00,
                'income_payment' => $income,
                'tax_withheld' => round($income * 0.01, 2),
            ]);
        }

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('expandedWtaxEntries.data', 1)
                ->where('expandedWtaxEntries.data.0.merged_rows', 3)
                ->where(
                    'expandedWtaxEntries.data.0.tax_withheld',
                    fn ($value) => number_format((float) $value, 2, '.', '') === '600.66'
                )
                // 600.66, the consolidated amount -- not 1801.98, which is what
                // multiplying by merged_rows would give.
                ->has('withholdingTaxRateSummary.rates', 1)
                ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '600.66')
        );
    }

    /**
     * The cards total the filtered listing, not the page of it on screen.
     */
    public function test_expanded_rate_totals_are_the_same_on_every_page(): void
    {
        // Twelve 1% payees then eight 2% payees, listed by name: page one holds
        // fifteen of them, so five of the 2% amounts are only ever on page two.
        foreach (range(1, 20) as $index) {
            $isOnePercent = $index <= 12;

            $this->withholding([
                'payee_name' => sprintf('PAYEE %02d INC', $index),
                'company_name' => sprintf('PAYEE %02d INC', $index),
                'payee_tin' => sprintf('1000000%02d', $index),
                'atc_code' => $isOnePercent ? 'WC158' : 'WC160',
                'tax_rate' => $isOnePercent ? 1.00 : 2.00,
                'income_payment' => 1001.00,
                'tax_withheld' => $isOnePercent ? 10.01 : 20.02,
            ]);
        }

        $summary = fn ($page) => $page
            ->has('withholdingTaxRateSummary.rates', 2)
            ->where('withholdingTaxRateSummary.rates.0.tax_rate', '1.00')
            ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '120.12')
            ->where('withholdingTaxRateSummary.rates.1.tax_rate', '2.00')
            ->where('withholdingTaxRateSummary.rates.1.tax_withheld_total', '160.16');

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia(
            fn ($page) => $summary($page)
                ->has('expandedWtaxEntries.data', 15)
                ->where('expandedWtaxEntries.data.0.payee_name', 'PAYEE 01 INC')
        );

        // 100.10 of the 2% total is on this page alone, and the card does not move.
        $this->get('/records/expanded-wtax?page=2')->assertOk()->assertInertia(
            fn ($page) => $summary($page)
                ->has('expandedWtaxEntries.data', 5)
                ->where('expandedWtaxEntries.data.0.payee_name', 'PAYEE 16 INC')
        );
    }

    /**
     * Search and month narrow the cards and the table together, and clearing
     * them puts the totals back.
     */
    public function test_expanded_rate_totals_follow_the_search_and_month_filters(): void
    {
        $this->withholding([
            'payee_name' => 'ALPHA STEEL INC',
            'company_name' => 'ALPHA STEEL INC',
            'payee_tin' => '111111111',
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 10000.00,
            'tax_withheld' => 100.00,
        ]);

        $this->withholding([
            'payee_name' => 'BRAVO HARDWARE INC',
            'company_name' => 'BRAVO HARDWARE INC',
            'payee_tin' => '222222222',
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
            'income_payment' => 10000.00,
            'tax_withheld' => 200.00,
        ]);

        $this->withholding([
            'reporting_period' => '2026-05-31',
            'payee_name' => 'CHARLIE FREIGHT INC',
            'company_name' => 'CHARLIE FREIGHT INC',
            'payee_tin' => '333333333',
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 30000.00,
            'tax_withheld' => 300.00,
        ]);

        $unfiltered = fn ($page) => $page
            ->has('expandedWtaxEntries.data', 3)
            ->has('withholdingTaxRateSummary.rates', 2)
            ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '400.00')
            ->where('withholdingTaxRateSummary.rates.1.tax_withheld_total', '200.00');

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia($unfiltered);

        // Month only: April keeps both rates, May keeps the 1% payee alone.
        $this->get('/records/expanded-wtax?period=2026-04')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('expandedWtaxEntries.data', 2)
                ->has('withholdingTaxRateSummary.rates', 2)
                ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '100.00')
                ->where('withholdingTaxRateSummary.rates.1.tax_withheld_total', '200.00')
        );

        $this->get('/records/expanded-wtax?period=2026-05')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('expandedWtaxEntries.data', 1)
                ->has('withholdingTaxRateSummary.rates', 1)
                ->where('withholdingTaxRateSummary.rates.0.tax_rate', '1.00')
                ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '300.00')
        );

        // The three columns the search box covers: payee, TIN and ATC.
        $this->get('/records/expanded-wtax?search=CHARLIE')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('withholdingTaxRateSummary.rates', 1)
                ->where('withholdingTaxRateSummary.rates.0.tax_rate', '1.00')
                ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '300.00')
        );

        $this->get('/records/expanded-wtax?search=222222222')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('withholdingTaxRateSummary.rates', 1)
                ->where('withholdingTaxRateSummary.rates.0.tax_rate', '2.00')
                ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '200.00')
        );

        $this->get('/records/expanded-wtax?search=WC160')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('withholdingTaxRateSummary.rates', 1)
                ->where('withholdingTaxRateSummary.rates.0.tax_rate', '2.00')
                ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '200.00')
        );

        // Both together: WC158 is on two payees, April keeps one of them.
        $this->get('/records/expanded-wtax?period=2026-04&search=WC158')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('expandedWtaxEntries.data', 1)
                ->has('withholdingTaxRateSummary.rates', 1)
                ->where('withholdingTaxRateSummary.rates.0.tax_rate', '1.00')
                ->where('withholdingTaxRateSummary.rates.0.tax_withheld_total', '100.00')
        );

        $this->get('/records/expanded-wtax')->assertOk()->assertInertia($unfiltered);
    }

    public function test_expanded_rate_summary_is_empty_when_nothing_matches(): void
    {
        $this->seedRateSpread();

        $this->get('/records/expanded-wtax?search=ZULU')->assertOk()->assertInertia(
            fn ($page) => $page
                ->has('expandedWtaxEntries.data', 0)
                ->has('withholdingTaxRateSummary.rates', 0)
        );
    }

    public function test_the_record_pages_are_behind_auth(): void
    {
        Auth::logout();

        foreach ([
            '/records/purchases',
            '/records/sales',
            '/records/expanded-wtax',
            '/records/importations',
        ] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }
}
