<?php

namespace Tests\Feature;

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

    public function test_sales_records_can_be_searched(): void
    {
        $this->sale();
        $this->sale(['customer_name' => 'DAVAO CONSTRUCTION CORP.', 'customer_tin' => '444-555-666-0000']);

        $this->get('/records/sales?search=DAVAO')->assertOk()->assertInertia(
            fn ($page) => $page->has('salesVatInputs.data', 1)
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
