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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ViewInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_info_requires_authentication_and_rejects_unknown_resources(): void
    {
        $this->get('/view-info/purchase/1')->assertRedirect('/login');

        $this->actingAs(User::factory()->create());

        $this->getJson('/view-info/not-allowed/1')->assertNotFound();
        $this->getJson('/view-info/purchase/999999')->assertNotFound();
    }

    public function test_purchase_returns_complete_categorized_read_only_information(): void
    {
        $this->actingAs(User::factory()->create());
        Brokers::create(['broker_name' => 'CUSTOMS BROKER', 'tin_number' => '123-456-789-0000']);
        $purchase = $this->purchase([
            'tin_number' => '123-456-789-0000',
            'purchase_local' => 120.00,
            'services' => 12.00,
            'others' => 12.00,
            'total' => 500.00,
        ]);

        $response = $this->getJson("/view-info/purchase/{$purchase->id}")
            ->assertOk()
            ->assertJsonPath('resource', 'purchase')
            ->assertJsonPath('title', 'Purchase Information')
            ->assertJsonPath('subtitle', 'LOCAL HARDWARE INC.')
            ->assertJsonCount(4, 'sections')
            ->assertJsonCount(0, 'source_records');

        $fields = $this->fields($response->json('sections'));

        $this->assertSame('500.00', $fields['total']['value']);
        $this->assertSame(1200, $fields['display_calculated_total']['value']);
        $this->assertTrue($fields['broker_eligible']['value']);
        $this->assertFalse($fields['stored_is_broker']['value']);
        $this->assertSame($purchase->id, $fields['id']['value']);
        $this->assertArrayHasKey('created_at', $fields);
        $this->assertArrayHasKey('updated_at', $fields);
    }

    public function test_simple_resources_return_hidden_fields_and_system_metadata(): void
    {
        $this->actingAs(User::factory()->create());

        $importation = ImportationEntry::create([
            'sequence_number' => 3,
            'tax_month' => '2026-04-01',
            'import_entry_no' => 'C-12345',
            'assessment_date' => '2026-04-10',
            'supplier' => 'OVERSEAS SELLER',
            'importation_date' => '2026-04-05',
            'country' => 'CHINA',
            'total_landed_cost' => 1120,
            'dutiable_value' => 1000,
            'charges' => 120,
            'exempt' => 0,
            'taxable_goods' => 1120,
            'vat_rate' => 12,
            'vat_payable' => 134.40,
            'or_number' => 'OR-1',
            'payment_date' => '2026-04-12',
        ]);
        $supplier = Supplier::create(['tin' => '111-111-111-0000', 'name' => 'SUPPLIER', 'addr' => 'ADDRESS', 'city' => 'CITY']);
        $customer = Customer::create(['tin' => '222-222-222-0000', 'name' => 'CUSTOMER', 'name_key' => 'CUSTOMERKEY', 'addr' => 'ADDRESS', 'city' => 'CITY']);
        $broker = Brokers::create(['broker_name' => 'BROKER', 'tin_number' => '333-333-333-0000']);
        $company = WithholdingCompany::create([
            'tin' => '444444444',
            'branch_code' => '0000',
            'registered_name' => 'FILING COMPANY',
            'trade_name' => 'TRADE NAME',
            'rdo_code' => '001',
            'address1' => 'ADDRESS 1',
            'address2' => 'CITY',
            'is_active' => true,
        ]);

        $cases = [
            ['importation', $importation->id, 'vat_input_id'],
            ['supplier', $supplier->id, 'created_at'],
            ['customer', $customer->id, 'name_key'],
            ['broker', $broker->id, 'updated_at'],
            ['withholding-company', $company->id, 'has_filed_rows'],
        ];

        foreach ($cases as [$resource, $id, $expectedField]) {
            $response = $this->getJson("/view-info/{$resource}/{$id}")
                ->assertOk()
                ->assertJsonPath('resource', $resource);

            $this->assertArrayHasKey($expectedField, $this->fields($response->json('sections')));
        }

        $companyFields = $this->fields(
            $this->getJson("/view-info/withholding-company/{$company->id}")->json('sections')
        );
        $this->assertSame('FILING COMPANY (444444444-0000)', $companyFields['label']['value']);
        $this->assertFalse($companyFields['has_filed_rows']['value']);
    }

    public function test_sales_returns_existing_consolidated_summary_and_period_source_rows(): void
    {
        $this->actingAs(User::factory()->create());

        $si = $this->sale([
            'document_no' => 'SI#100',
            'document_type' => 'SI',
            'reporting_period' => '2026-04-30',
            'document_date' => '2026-04-05',
            'gross_amount' => 1120,
            'net_amount' => 1120,
            'taxable_net_of_vat' => 1000,
            'output_vat' => 120,
        ]);
        $this->sale([
            'document_no' => 'CM#100',
            'document_type' => 'CM',
            'reporting_period' => '2026-04-30',
            'document_date' => '2026-04-10',
            'gross_amount' => 112,
            'net_amount' => 112,
            'taxable_net_of_vat' => 100,
            'output_vat' => 12,
        ]);
        $this->sale([
            'document_no' => 'SI#200',
            'document_type' => 'SI',
            'reporting_period' => '2026-05-31',
            'document_date' => '2026-05-05',
        ]);
        $this->sale([
            'document_no' => 'SI#OTHER',
            'document_type' => 'SI',
            'customer_name' => 'OTHER CUSTOMER',
            'company_name' => 'OTHER CUSTOMER',
            'customer_tin' => '999-999-999-0000',
            'reporting_period' => '2026-04-30',
        ]);

        $response = $this->getJson("/view-info/sales/{$si->id}?period=2026-04")
            ->assertOk()
            ->assertJsonPath('resource', 'sales')
            ->assertJsonCount(2, 'source_records')
            ->assertJsonPath('source_records.0.title', 'SI SI#100')
            ->assertJsonPath('source_records.1.title', 'CM CM#100');

        $fields = $this->fields($response->json('sections'));
        $this->assertSame(2, $fields['records_count']['value']);
        $this->assertSame(1, $fields['si_count']['value']);
        $this->assertSame(1, $fields['cm_count']['value']);
        $this->assertSame(900, $fields['taxable_net_of_vat']['value']);
        $this->assertSame(108, $fields['output_vat']['value']);
        $this->assertSame('2026-04', $fields['period_scope']['value']);

        $this->getJson("/view-info/sales/{$si->id}")
            ->assertOk()
            ->assertJsonCount(3, 'source_records');

        $this->getJson("/view-info/sales/{$si->id}?period=not-a-month")->assertStatus(422);
    }

    public function test_expanded_wtax_returns_only_source_rows_from_the_selected_group(): void
    {
        $this->actingAs(User::factory()->create());

        $anchor = $this->expanded(['income_payment' => 1000, 'tax_withheld' => 10]);
        $this->expanded(['payee_tin' => '222222222', 'income_payment' => 500, 'tax_withheld' => 5]);
        $this->expanded([
            'payee_name' => 'OTHER PAYEE',
            'company_name' => 'OTHER PAYEE',
            'payee_tin' => '999999999',
            'income_payment' => 700,
            'tax_withheld' => 7,
        ]);
        $this->expanded([
            'atc_code' => 'WC160',
            'income_payment' => 800,
            'tax_withheld' => 8,
        ]);

        $response = $this->getJson("/view-info/expanded-wtax/{$anchor->id}")
            ->assertOk()
            ->assertJsonPath('resource', 'expanded-wtax')
            ->assertJsonCount(2, 'source_records');

        $fields = $this->fields($response->json('sections'));
        $this->assertSame(2, $fields['merged_rows']['value']);
        $this->assertSame(1500, $fields['income_payment']['value']);
        $this->assertSame(15, $fields['tax_withheld']['value']);
        $this->assertTrue($fields['has_multiple_payee_tins']['value']);
        $this->assertSame(['111111111', '222222222'], $fields['distinct_payee_tins']['value']);

        $this->get('/records/expanded-wtax')->assertInertia(
            fn (Assert $page) => $page->where('expandedWtaxEntries.data.1.source_record_id', $anchor->id)
        );
    }

    private function purchase(array $overrides = []): VatInput
    {
        return VatInput::create(array_merge([
            'supplier_name' => 'LOCAL HARDWARE INC.',
            'tin_number' => '123-456-789-0000',
            'vendor_type' => 'company',
            'company_name' => 'LOCAL HARDWARE INC.',
            'address1' => 'ADDRESS 1',
            'address2' => 'CITY',
            'is_imported' => false,
            'exempt' => 0,
            'zero_rated' => 0,
            'purchase_imported' => 0,
            'purchase_local' => 1000,
            'services' => 0,
            'capital_goods' => 0,
            'other_than_capital_goods' => 1000,
            'taxable_net_of_vat' => 1000,
            'vat_rate' => 12,
            'input_vat' => 120,
            'total_purchases' => 1120,
            'others' => 0,
            'total' => 1120,
            'date_uploaded' => '2026-04-30',
            'is_broker' => false,
            'is_adjusted' => false,
        ], $overrides));
    }

    private function sale(array $overrides = []): SalesVatInput
    {
        return SalesVatInput::create(array_merge([
            'document_no' => 'SI#1',
            'document_type' => 'SI',
            'document_date' => '2026-04-01',
            'customer_name' => 'ACME CUSTOMER',
            'customer_type' => 'company',
            'customer_tin' => '111-222-333-0000',
            'company_name' => 'ACME CUSTOMER',
            'address1' => 'ADDRESS 1',
            'address2' => 'CITY',
            'gross_amount' => 112,
            'discount' => 0,
            'charges' => 0,
            'net_amount' => 112,
            'output_vat' => 12,
            'taxable_net_of_vat' => 100,
            'exempt_sales' => 0,
            'zero_rated_sales' => 0,
            's_zero_rated' => false,
            'reporting_period' => '2026-04-30',
            'is_adjusted' => false,
        ], $overrides));
    }

    private function expanded(array $overrides = []): ExpandedWtaxEntry
    {
        return ExpandedWtaxEntry::create(array_merge([
            'reporting_period' => '2026-04-30',
            'report_type' => 'quarterly',
            'withholding_agent_tin' => '008791976',
            'withholding_agent_branch_code' => '0000',
            'withholding_agent_name' => 'FORTRESS STEEL INC.',
            'payee_name' => 'SAME PAYEE',
            'payee_type' => 'company',
            'payee_tin' => '111111111',
            'payee_branch_code' => '0000',
            'company_name' => 'SAME PAYEE',
            'atc_code' => 'WC158',
            'tax_rate' => 1,
            'income_payment' => 1000,
            'tax_withheld' => 10,
        ], $overrides));
    }

    private function fields(array $sections): array
    {
        return collect($sections)
            ->flatMap(fn (array $section) => $section['fields'])
            ->keyBy('key')
            ->all();
    }
}
