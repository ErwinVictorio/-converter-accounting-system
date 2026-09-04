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

    public function test_consolidated_sales_dat_values_are_recomputed_from_net_amount(): void
    {
        $rows = new Collection([
            $this->saleFromNetAmount('SI#15683', 'SI', 124800.00, 111428.57, 13371.43),
            $this->saleFromNetAmount('SI#15684', 'SI', 600000.00, 535714.29, 64285.71),
            $this->saleFromNetAmount('SI#15685', 'SI', 10000.00, 8928.57, 1071.43),
            $this->saleFromNetAmount('SI#15686', 'SI', 100000.00, 89285.71, 10714.29),
            $this->saleFromNetAmount('SI#15687', 'SI', 414000.00, 369642.86, 44357.14),
            $this->saleFromNetAmount('SI#15694', 'SI', 239430.00, 213776.79, 25653.21),
            $this->saleFromNetAmount('SI#15695', 'SI', 306820.00, 273946.43, 32873.57),
            $this->saleFromNetAmount('CM#003203', 'CM', 21908.00, 19560.71, 2347.29),
            $this->saleFromNetAmount('CM#003204', 'CM', 11000.00, 9821.43, 1178.57),
            $this->saleFromNetAmount('CM#003231', 'CM', 16706.85, 14916.83, 1790.02),
            $this->saleFromNetAmount('CM#003239', 'CM', 24000.00, 21428.57, 2571.43),
        ]);

        $groups = app(SalesSiCmConsolidator::class)->consolidate($rows);

        $this->assertCount(1, $groups);
        $this->assertEqualsWithDelta(1721435.15, $groups[0]['net_amount'], 0.001);
        $this->assertEqualsWithDelta(1536995.67, $groups[0]['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(1536995.67, $groups[0]['taxable_net_of_vat'], 0.001);
        $this->assertEqualsWithDelta(184439.48, $groups[0]['output_vat'], 0.001);
    }

    public function test_exempt_and_zero_rated_sales_are_not_treated_as_vatable_gross(): void
    {
        $groups = app(SalesSiCmConsolidator::class)->consolidate(new Collection([
            $this->saleFromNetAmount('SI#10001', 'SI', 1240, 1000, 120, [
                'exempt_sales' => 80,
                'zero_rated_sales' => 40,
            ]),
        ]));

        $this->assertCount(1, $groups);
        $this->assertEqualsWithDelta(80.0, $groups[0]['exempt_sales'], 0.001);
        $this->assertEqualsWithDelta(40.0, $groups[0]['zero_rated_sales'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $groups[0]['taxable_sales'], 0.001);
        $this->assertEqualsWithDelta(120.0, $groups[0]['output_vat'], 0.001);
    }

    private function sale(string $documentNo, string $documentType, float $taxable, float $vat, array $overrides = []): SalesVatInput
    {
        return $this->saleFromNetAmount($documentNo, $documentType, $taxable + $vat, $taxable, $vat, $overrides);
    }

    private function saleFromNetAmount(
        string $documentNo,
        string $documentType,
        float $netAmount,
        float $taxable,
        float $vat,
        array $overrides = []
    ): SalesVatInput
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
            'net_amount' => $netAmount,
            'gross_amount' => $netAmount,
        ], $overrides));
    }
}
