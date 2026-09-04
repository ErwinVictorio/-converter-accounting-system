<?php

namespace Tests\Unit;

use App\Services\BIR\BirSalesRowValidator;
use App\Services\BIR\ReliefSalesDatGenerator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ReliefSalesDatGeneratorTest extends TestCase
{
    public function test_header_and_detail_field_counts_are_fixed(): void
    {
        $content = app(ReliefSalesDatGenerator::class)->generate(
            [
                'tin' => '008791976',
                'name' => 'FORTRESS STEEL INC.',
                'registered_name' => 'FORTRESS STEEL INC.',
                'address1' => 'LOT 433 J.P RIZAL NANGKA',
                'address2' => ' MARIKINA 1808',
                'rdo_code' => '045',
            ],
            new Collection([
                [
                    'customer_type' => 'company',
                    'customer_tin' => '743020291',
                    'company_name' => '1HB CONSTRUCTION CORP.',
                    'address1' => 'ADDRESS 1',
                    'address2' => 'ADDRESS 2',
                    'exempt_sales' => 0,
                    'zero_rated_sales' => 0,
                    'taxable_sales' => 106910.71,
                    'output_vat' => 12829.29,
                ],
            ]),
            Carbon::create(2026, 5, 1)
        );

        [$header, $detail] = explode("\r\n", trim($content));

        $this->assertCount(17, str_getcsv($header));
        $this->assertCount(15, str_getcsv($detail));
        $this->assertStringContainsString("\r\n", $content);
    }

    public function test_filename_uses_tin_sales_type_and_period(): void
    {
        $filename = app(ReliefSalesDatGenerator::class)
            ->filename(['tin' => '008-791-976'], Carbon::create(2026, 5, 1));

        $this->assertSame('008791976S052026.DAT', $filename);
    }

    public function test_individual_customer_leaves_company_field_blank(): void
    {
        $content = app(ReliefSalesDatGenerator::class)->generate(
            [
                'tin' => '008791976',
                'name' => 'FORTRESS STEEL INC.',
                'registered_name' => 'FORTRESS STEEL INC.',
                'address1' => 'LOT 433 J.P RIZAL NANGKA',
                'address2' => ' MARIKINA 1808',
                'rdo_code' => '045',
            ],
            new Collection([
                [
                    'customer_type' => 'individual',
                    'customer_tin' => '166416216',
                    'last_name' => 'LARGA',
                    'first_name' => 'RAMON',
                    'middle_name' => 'HIZOLE',
                    'address1' => 'AMOINGON BOAC',
                    'address2' => 'MARINDUQUE',
                    'exempt_sales' => 0,
                    'zero_rated_sales' => 0,
                    'taxable_sales' => 481600,
                    'output_vat' => 57792,
                ],
            ]),
            Carbon::create(2026, 5, 1)
        );

        $detail = explode("\r\n", trim($content))[1];

        $this->assertStringStartsWith('D,S,"166416216",,"LARGA","RAMON","HIZOLE"', $detail);
    }

    public function test_consolidated_bir_rounded_amounts_are_written_to_detail_row(): void
    {
        $content = app(ReliefSalesDatGenerator::class)->generate(
            [
                'tin' => '008791976',
                'name' => 'FORTRESS STEEL INC.',
                'registered_name' => 'FORTRESS STEEL INC.',
                'address1' => 'LOT 433 J.P RIZAL NANGKA',
                'address2' => ' MARIKINA 1808',
                'rdo_code' => '045',
            ],
            new Collection([
                [
                    'customer_type' => 'company',
                    'customer_tin' => '000267071',
                    'company_name' => 'NCR CONSTRUCTION SUPPLY INC.',
                    'address1' => '866 HENSON ST. ANGELES CITY',
                    'address2' => 'PAMPANGA',
                    'exempt_sales' => 0,
                    'zero_rated_sales' => 0,
                    'taxable_sales' => 1536995.67,
                    'output_vat' => 184439.48,
                ],
            ]),
            Carbon::create(2026, 7, 1)
        );

        $detail = str_getcsv(explode("\r\n", trim($content))[1]);

        $this->assertSame('1536995.67', $detail[11]);
        $this->assertSame('184439.48', $detail[12]);
    }

    public function test_sales_validator_requires_customer_bir_info(): void
    {
        $errors = app(BirSalesRowValidator::class)->validate([
            'customer_type' => 'company',
            'customer_tin' => '',
            'company_name' => 'ACME CORP',
            'address1' => '',
            'exempt_sales' => 0,
            'zero_rated_sales' => 0,
            'taxable_sales' => 100,
            'output_vat' => 12,
        ], 2);

        $this->assertNotEmpty($errors);
    }
}
