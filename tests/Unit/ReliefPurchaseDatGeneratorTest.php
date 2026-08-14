<?php

namespace Tests\Unit;

use App\Models\VatInput;
use App\Services\BIR\BirPurchaseRowValidator;
use App\Services\BIR\ReliefPurchaseDatGenerator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ReliefPurchaseDatGeneratorTest extends TestCase
{
    public function test_generated_dat_matches_reference_file(): void
    {
        $expected = file_get_contents(base_path('008791976P042026.DAT'));
        $lines = explode("\r\n", trim($expected));
        $header = str_getcsv($lines[0]);

        $company = [
            'tin' => $header[2],
            'name' => $header[3],
            'last_name' => $header[4],
            'first_name' => $header[5],
            'middle_name' => $header[6],
            'registered_name' => $header[7],
            'address1' => $header[8],
            'address2' => $header[9],
            'rdo_code' => $header[18],
            'final_header_field' => $header[20],
        ];

        $transactions = collect(array_slice($lines, 1))->map(function (string $line): array {
            $fields = str_getcsv($line);

            return [
                'vendor_type' => $fields[3] === '' ? 'individual' : 'company',
                'vendor_tin' => $fields[2],
                'company_name' => $fields[3],
                'last_name' => $fields[4],
                'first_name' => $fields[5],
                'middle_name' => $fields[6],
                'address1' => $fields[7],
                'address2' => $fields[8],
                'exempt' => $fields[9],
                'zero_rated' => $fields[10],
                'services' => $fields[11],
                'capital_goods' => $fields[12],
                'other_than_capital_goods' => $fields[13],
                'input_vat' => $fields[14],
            ];
        });

        $actual = app(ReliefPurchaseDatGenerator::class)
            ->generate($company, $transactions, Carbon::create(2026, 4, 1), 0);

        $this->assertSame($expected, $actual);
    }

    public function test_header_and_detail_field_counts_are_fixed(): void
    {
        $content = app(ReliefPurchaseDatGenerator::class)->generate(
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
                    'vendor_type' => 'company',
                    'vendor_tin' => '236791864',
                    'company_name' => 'A ZINC INDUSTRIAL GALVANIZING PHILIPPINES',
                    'address1' => '668 B 6TH ST 7TH AVE',
                    'address2' => 'CALOOCAN CITY',
                    'exempt' => 0,
                    'zero_rated' => 0,
                    'services' => 17697.17,
                    'capital_goods' => 0,
                    'other_than_capital_goods' => 0,
                    'input_vat' => 2123.66,
                ],
            ]),
            Carbon::create(2026, 4, 1),
            0
        );

        [$header, $detail] = explode("\r\n", $content);

        $this->assertCount(21, str_getcsv($header));
        $this->assertCount(17, str_getcsv($detail));
        $this->assertStringContainsString("\r\n", $content);
    }

    public function test_filename_uses_tin_purchase_type_and_period(): void
    {
        $filename = app(ReliefPurchaseDatGenerator::class)
            ->filename(['tin' => '008-791-976'], Carbon::create(2026, 4, 1));

        $this->assertSame('008791976P042026.DAT', $filename);
    }

    public function test_vendor_type_validation_requires_correct_name_fields(): void
    {
        $validator = app(BirPurchaseRowValidator::class);

        $companyErrors = $validator->validate([
            'vendor_type' => 'company',
            'vendor_tin' => '236791864',
            'company_name' => '',
            'exempt' => 0,
            'zero_rated' => 0,
            'services' => 0,
            'capital_goods' => 0,
            'other_than_capital_goods' => 0,
            'input_vat' => 0,
        ], 2);

        $individualErrors = $validator->validate([
            'vendor_type' => 'individual',
            'vendor_tin' => '236791864',
            'last_name' => 'KIM',
            'first_name' => 'ROSE',
            'middle_name' => '',
            'exempt' => 0,
            'zero_rated' => 0,
            'services' => 0,
            'capital_goods' => 0,
            'other_than_capital_goods' => 0,
            'input_vat' => 0,
        ], 3);

        $this->assertNotEmpty($companyErrors);
        $this->assertNotEmpty($individualErrors);
    }

    public function test_vat_input_maps_to_bir_purchase_detail_source_row(): void
    {
        $vatInput = new VatInput([
            'supplier_name' => 'ONE CORPORATION',
            'tin_number' => '212345678',
            'vendor_type' => 'company',
            'company_name' => 'ONE CORPORATION',
            'address1' => 'ADDRESS 1',
            'address2' => 'ADDRESS 2',
            'exempt' => 0,
            'zero_rated' => 2000,
            'services' => 8928.57,
            'capital_goods' => 0,
            'other_than_capital_goods' => 0,
            'input_vat' => 1071.43,
        ]);

        $this->assertSame([
            'vendor_type' => 'company',
            'vendor_tin' => '212345678',
            'company_name' => 'ONE CORPORATION',
            'last_name' => null,
            'first_name' => null,
            'middle_name' => null,
            'address1' => 'ADDRESS 1',
            'address2' => 'ADDRESS 2',
            'exempt' => '0.00',
            'zero_rated' => '2000.00',
            'services' => '8928.57',
            'capital_goods' => '0.00',
            'other_than_capital_goods' => '0.00',
            'input_vat' => '1071.43',
        ], $vatInput->toBirPurchaseRow());
    }
}
