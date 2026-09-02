<?php

namespace Tests\Unit;

use App\Models\SalesVatInput;
use App\Services\BIR\SalesSiCmConsolidator;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SalesSiCmConsolidatorTest extends TestCase
{
    public function test_same_customer_si_and_cm_rows_net_to_one_group(): void
    {
        $rows = new Collection([
            $this->sale('SI#10001', 'SI', 1000, 120),
            $this->sale('SI#10002', 'SI', 500, 60),
            $this->sale('CM#00001', 'CM', 200, 24),
            $this->sale('CM#00002', 'CM', -100, -12),
        ]);

        $groups = app(SalesSiCmConsolidator::class)->consolidate($rows);

        $this->assertCount(1, $groups);
        $this->assertSame(2, $groups[0]['si_count']);
        $this->assertSame(2, $groups[0]['cm_count']);
        $this->assertEqualsWithDelta(1200.0, $groups[0]['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(144.0, $groups[0]['output_vat'], 0.001);
    }

    public function test_cm_only_customer_becomes_negative(): void
    {
        $groups = app(SalesSiCmConsolidator::class)->consolidate(new Collection([
            $this->sale('CM#00001', 'CM', 300, 36),
        ]));

        $this->assertCount(1, $groups);
        $this->assertSame(0, $groups[0]['si_count']);
        $this->assertSame(1, $groups[0]['cm_count']);
        $this->assertEqualsWithDelta(-300.0, $groups[0]['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(-36.0, $groups[0]['output_vat'], 0.001);
    }

    public function test_different_customers_do_not_net_against_each_other(): void
    {
        $groups = app(SalesSiCmConsolidator::class)->consolidate(new Collection([
            $this->sale('SI#10001', 'SI', 1000, 120, ['customer_name' => 'CUSTOMER A', 'customer_tin' => '111-222-333']),
            $this->sale('CM#00001', 'CM', 200, 24, ['customer_name' => 'CUSTOMER B', 'customer_tin' => '444-555-666']),
        ]));

        $this->assertCount(2, $groups);
        $this->assertEqualsWithDelta(1000.0, $groups->firstWhere('customer_name', 'CUSTOMER A')['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(-200.0, $groups->firstWhere('customer_name', 'CUSTOMER B')['taxable_sales'], 0.001);
    }

    private function sale(string $documentNo, string $documentType, float $taxable, float $vat, array $overrides = []): SalesVatInput
    {
        return new SalesVatInput(array_merge([
            'document_no' => $documentNo,
            'document_type' => $documentType,
            'customer_name' => 'ACME BUILDERS CORP.',
            'customer_tin' => '111-222-333-0000',
            'customer_type' => 'company',
            'company_name' => 'ACME BUILDERS CORP.',
            'address1' => 'ADDRESS 1',
            'address2' => 'ADDRESS 2',
            'exempt_sales' => 0.00,
            'zero_rated_sales' => 0.00,
            'taxable_net_of_vat' => $taxable,
            'output_vat' => $vat,
            'net_amount' => $taxable,
            'gross_amount' => $taxable + $vat,
        ], $overrides));
    }
}
