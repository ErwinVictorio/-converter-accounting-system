<?php

namespace Tests\Feature;

use App\Models\Brokers;
use App\Models\Customer;
use App\Models\ExpandedWtaxEntry;
use App\Models\ImportationEntry;
use App\Models\SalesVatInput;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VatInput;
use App\Models\WithholdingCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AlphabeticalRecordOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_master_data_lists_are_ordered_by_name(): void
    {
        Customer::create(['name' => 'ZETA CUSTOMER', 'name_key' => 'ZETACUSTOMER', 'tin' => '111-111-111-000', 'addr' => 'ADDR', 'city' => 'CITY']);
        Customer::create(['name' => 'ALPHA CUSTOMER', 'name_key' => 'ALPHACUSTOMER', 'tin' => '222-222-222-000', 'addr' => 'ADDR', 'city' => 'CITY']);
        Supplier::create(['name' => 'ZETA SUPPLIER', 'tin' => '333-333-333-000', 'addr' => 'ADDR', 'city' => 'CITY']);
        Supplier::create(['name' => 'ALPHA SUPPLIER', 'tin' => '444-444-444-000', 'addr' => 'ADDR', 'city' => 'CITY']);
        Brokers::create(['broker_name' => 'ZETA BROKER', 'tin_number' => '555-555-555-000']);
        Brokers::create(['broker_name' => 'ALPHA BROKER', 'tin_number' => '666-666-666-000']);
        WithholdingCompany::create(['registered_name' => 'ZETA COMPANY', 'tin' => '777777777', 'branch_code' => '0000', 'rdo_code' => '045', 'is_active' => true]);
        WithholdingCompany::create(['registered_name' => 'ALPHA COMPANY', 'tin' => '888888888', 'branch_code' => '0000', 'rdo_code' => '045', 'is_active' => false]);

        $this->assertSame(['ALPHA CUSTOMER', 'ZETA CUSTOMER'], $this->prop('/customers', 'customerList.data.*.name'));
        $this->assertSame(['ALPHA SUPPLIER', 'ZETA SUPPLIER'], $this->prop('/suppliers', 'supplierList.data.*.name'));
        $this->assertSame(['ALPHA BROKER', 'ZETA BROKER'], $this->prop('/brokers', 'brokerList.*.broker_name'));
        $this->assertSame(['ALPHA COMPANY', 'ZETA COMPANY'], $this->prop('/withholding-companies', 'companies.data.*.registered_name'));
    }

    public function test_record_lists_are_ordered_by_main_name(): void
    {
        $this->purchase('ZETA SUPPLIER');
        $this->purchase('ALPHA SUPPLIER');
        $this->sale('ZETA CUSTOMER');
        $this->sale('ALPHA CUSTOMER');
        $this->expanded('ZETA PAYEE');
        $this->expanded('ALPHA PAYEE');
        $this->importation('ZETA IMPORTER', 'C-ZETA');
        $this->importation('ALPHA IMPORTER', 'C-ALPHA');

        $this->assertSame(['ALPHA SUPPLIER', 'ZETA SUPPLIER'], $this->prop('/records/purchases', 'vatInputs.data.*.supplier_name'));
        $this->assertSame(['ALPHA CUSTOMER', 'ZETA CUSTOMER'], $this->prop('/records/sales', 'salesVatInputs.data.*.customer_name'));
        $this->assertSame(['ALPHA PAYEE', 'ZETA PAYEE'], $this->prop('/records/expanded-wtax', 'expandedWtaxEntries.data.*.payee_name'));
        $this->assertSame(['ALPHA IMPORTER', 'ZETA IMPORTER'], $this->prop('/records/importations', 'entries.data.*.supplier'));
    }

    private function prop(string $url, string $path): array
    {
        $props = [];

        $this->get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return data_get($props, $path);
    }

    private function purchase(string $supplierName): void
    {
        VatInput::create([
            'supplier_name' => $supplierName,
            'tin_number' => '123-456-789-000',
            'vendor_type' => 'company',
            'company_name' => $supplierName,
            'is_imported' => false,
            'exempt' => 0.00,
            'zero_rated' => 0.00,
            'purchase_imported' => 0.00,
            'purchase_local' => 1000.00,
            'services' => 0.00,
            'capital_goods' => 0.00,
            'other_than_capital_goods' => 1000.00,
            'taxable_net_of_vat' => 1000.00,
            'vat_rate' => 12.00,
            'input_vat' => 120.00,
            'total_purchases' => 1120.00,
            'others' => 0.00,
            'total' => 1120.00,
            'date_uploaded' => '2026-04-30',
            'is_broker' => false,
            'is_adjusted' => false,
        ]);
    }

    private function sale(string $customerName): void
    {
        SalesVatInput::create([
            'document_no' => 'SI#' . str_replace(' ', '', $customerName),
            'document_type' => 'SI',
            'document_date' => '2026-04-15',
            'customer_name' => $customerName,
            'gross_amount' => 1120.00,
            'discount' => 0.00,
            'charges' => 0.00,
            'net_amount' => 1000.00,
            'output_vat' => 120.00,
            'taxable_net_of_vat' => 1000.00,
            'customer_tin' => '111-222-333-000',
            'customer_type' => 'company',
            'company_name' => $customerName,
            'address1' => 'ADDRESS',
            'address2' => 'CITY',
            'exempt_sales' => 0.00,
            'zero_rated_sales' => 0.00,
            'reporting_period' => '2026-04-30',
            'is_adjusted' => false,
        ]);
    }

    private function expanded(string $payeeName): void
    {
        ExpandedWtaxEntry::create([
            'reporting_period' => '2026-04-30',
            'payee_name' => $payeeName,
            'payee_type' => 'company',
            'payee_tin' => '007086184',
            'payee_branch_code' => '0000',
            'company_name' => $payeeName,
            'atc_code' => 'WC158',
            'tax_rate' => 1.00,
            'income_payment' => 1000.00,
            'tax_withheld' => 10.00,
        ]);
    }

    private function importation(string $supplier, string $entryNo): void
    {
        ImportationEntry::create([
            'sequence_number' => 1,
            'tax_month' => '2026-04-01',
            'import_entry_no' => $entryNo,
            'assessment_date' => '2026-04-10',
            'supplier' => $supplier,
            'importation_date' => '2026-04-05',
            'country' => 'CHINA',
            'total_landed_cost' => 1120.00,
            'dutiable_value' => 1000.00,
            'charges' => 120.00,
            'exempt' => 0.00,
            'taxable_goods' => 1120.00,
            'vat_rate' => 12.00,
            'vat_payable' => 134.40,
            'or_number' => $entryNo,
            'payment_date' => '2026-04-12',
        ]);
    }
}
