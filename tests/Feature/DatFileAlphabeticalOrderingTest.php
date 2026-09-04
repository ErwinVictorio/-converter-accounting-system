<?php

namespace Tests\Feature;

use App\Models\ImportationEntry;
use App\Models\SalesVatInput;
use App\Models\User;
use App\Models\VatInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatFileAlphabeticalOrderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_purchase_dat_detail_rows_are_alphabetical_by_supplier_name(): void
    {
        $this->purchase(['supplier_name' => 'ZETA SUPPLY CORP', 'tin_number' => '333444555']);
        $this->purchase(['supplier_name' => 'ALPHA SUPPLY CORP', 'tin_number' => '111222333']);
        $this->purchase(['supplier_name' => 'BETA SUPPLY CORP', 'tin_number' => '222333444']);

        $lines = $this->lines($this->get('/download-datfile?period=2026-07-31&record_type=purchase')->getContent());

        $names = array_map(fn (string $line) => str_getcsv($line)[3], array_slice($lines, 1));

        $this->assertSame([
            'ALPHA SUPPLY CORP',
            'BETA SUPPLY CORP',
            'ZETA SUPPLY CORP',
        ], $names);
    }

    public function test_sales_dat_detail_rows_are_alphabetical_after_consolidation(): void
    {
        $this->sale(['customer_name' => 'ZETA CUSTOMER CORP', 'customer_tin' => '333444555']);
        $this->sale(['customer_name' => 'ALPHA CUSTOMER CORP', 'customer_tin' => '111222333']);
        $this->sale(['customer_name' => 'BETA CUSTOMER CORP', 'customer_tin' => '222333444']);

        $lines = $this->lines($this->get('/download-datfile?period=2026-07-31&record_type=sales')->getContent());

        $names = array_map(fn (string $line) => str_getcsv($line)[3], array_slice($lines, 1));

        $this->assertSame([
            'ALPHA CUSTOMER CORP',
            'BETA CUSTOMER CORP',
            'ZETA CUSTOMER CORP',
        ], $names);
    }

    public function test_importation_dat_detail_rows_are_alphabetical_by_supplier_name(): void
    {
        $this->importation(['supplier' => 'ZETA METALS LIMITED', 'import_entry_no' => 'C2200', 'sequence_number' => 1]);
        $this->importation(['supplier' => 'ALPHA METALS LIMITED', 'import_entry_no' => 'C2051', 'sequence_number' => 2]);
        $this->importation(['supplier' => 'BETA METALS LIMITED', 'import_entry_no' => 'C2100', 'sequence_number' => 3]);

        $lines = $this->lines($this->get('/download-datfile?period=2026-07-31&record_type=importation')->getContent());

        $names = array_map(fn (string $line) => str_getcsv($line)[4], array_slice($lines, 1));

        $this->assertSame([
            'ALPHA METALS LIMITED',
            'BETA METALS LIMITED',
            'ZETA METALS LIMITED',
        ], $names);
    }

    /** @return string[] */
    private function lines(string $content): array
    {
        return explode("\r\n", trim($content));
    }

    private function purchase(array $overrides = []): VatInput
    {
        return VatInput::create(array_merge([
            'supplier_name' => 'ALPHA SUPPLY CORP',
            'tin_number' => '111222333',
            'vendor_type' => 'company',
            'company_name' => $overrides['supplier_name'] ?? 'ALPHA SUPPLY CORP',
            'address1' => 'ADDRESS 1',
            'address2' => 'ADDRESS 2',
            'exempt' => 0,
            'zero_rated' => 0,
            'services' => 1000,
            'capital_goods' => 0,
            'other_than_capital_goods' => 0,
            'taxable_net_of_vat' => 1000,
            'vat_rate' => 12,
            'input_vat' => 120,
            'total_purchases' => 1120,
            'others' => 0,
            'total' => 1120,
            'date_uploaded' => '2026-07-31',
            'is_imported' => true,
            'is_adjusted' => false,
        ], $overrides, [
            'company_name' => $overrides['supplier_name'] ?? 'ALPHA SUPPLY CORP',
        ]));
    }

    private function sale(array $overrides = []): SalesVatInput
    {
        return SalesVatInput::create(array_merge([
            'document_no' => 'SI#10001',
            'document_type' => 'SI',
            'document_date' => '2026-07-31',
            'customer_name' => 'ALPHA CUSTOMER CORP',
            'gross_amount' => 1120,
            'discount' => 0,
            'charges' => 0,
            'net_amount' => 1120,
            'output_vat' => 120,
            'taxable_net_of_vat' => 1000,
            'customer_tin' => '111222333',
            'customer_type' => 'company',
            'company_name' => $overrides['customer_name'] ?? 'ALPHA CUSTOMER CORP',
            'address1' => 'ADDRESS 1',
            'address2' => 'ADDRESS 2',
            'exempt_sales' => 0,
            'zero_rated_sales' => 0,
            'reporting_period' => '2026-07-31',
            'is_adjusted' => false,
        ], $overrides, [
            'company_name' => $overrides['customer_name'] ?? 'ALPHA CUSTOMER CORP',
        ]));
    }

    private function importation(array $overrides = []): ImportationEntry
    {
        return ImportationEntry::create(array_merge([
            'sequence_number' => 1,
            'tax_month' => '2026-07-31',
            'import_entry_no' => 'C2051',
            'assessment_date' => '2026-07-14',
            'supplier' => 'ALPHA METALS LIMITED',
            'importation_date' => '2026-06-10',
            'country' => 'CHINA',
            'total_landed_cost' => 1000,
            'dutiable_value' => 1000,
            'charges' => 0,
            'exempt' => 0,
            'taxable_goods' => 1000,
            'vat_rate' => 12,
            'vat_payable' => 120,
            'or_number' => '000',
            'payment_date' => '2026-07-31',
        ], $overrides));
    }
}
