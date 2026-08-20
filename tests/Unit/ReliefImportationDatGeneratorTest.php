<?php

namespace Tests\Unit;

use App\Services\BIR\ReliefImportationDatGenerator;
use Carbon\Carbon;
use RuntimeException;
use Tests\TestCase;

class ReliefImportationDatGeneratorTest extends TestCase
{
    private const REFERENCE = 'ImportaionFormat/008791976I072026.DAT';

    private function company(array $overrides = []): array
    {
        return array_merge([
            'tin' => '008791976',
            'name' => 'FORTRESS STEEL INC.',
            'last_name' => '',
            'first_name' => '',
            'middle_name' => '',
            'registered_name' => 'FORTRESS STEEL INC.',
            'address1' => 'LOT 433 J.P RIZAL NANGKA',
            'address2' => ' MARIKINA 1808',
            'rdo_code' => '045',
            'final_header_field' => '12',
        ], $overrides);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'import_entry_no' => 'C2051',
            'assessment_date' => '2026-07-14',
            'supplier' => 'DAO FORTUNE CO LIMITED',
            'importation_date' => '2026-06-10',
            'country' => 'CHINA',
            'dutiable_value' => '8094234.19',
            'charges' => '0.00',
            'exempt' => '0.00',
            'taxable_goods' => '8094234.19',
            'vat_rate' => '12.00',
            'vat_payable' => '971308.10',
            'or_number' => '000',
            'payment_date' => '2026-07-31',
        ], $overrides);
    }

    /**
     * The layout was reverse-engineered from a file BIR already accepted, so
     * regenerating that exact file is the standard the generator has to meet.
     */
    public function test_generated_dat_matches_reference_file(): void
    {
        $expected = file_get_contents(base_path(self::REFERENCE));
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
            'rdo_code' => $header[15],
            'final_header_field' => $header[17],
        ];

        $transactions = collect(array_slice($lines, 1))->map(function (string $line) use ($header): array {
            $fields = str_getcsv($line);

            return [
                'import_entry_no' => $fields[2],
                'assessment_date' => $fields[3],
                'supplier' => $fields[4],
                'importation_date' => $fields[5],
                'country' => $fields[6],
                'dutiable_value' => $fields[7],
                'charges' => $fields[8],
                'exempt' => $fields[9],
                'taxable_goods' => $fields[10],
                'vat_payable' => $fields[11],
                'or_number' => $fields[12],
                'payment_date' => $fields[13],
                // Detail lines carry no rate; the header holds the one monthly rate.
                'vat_rate' => $header[17],
            ];
        });

        $actual = app(ReliefImportationDatGenerator::class)
            ->generate($company, $transactions, Carbon::create(2026, 7, 1));

        $this->assertSame($expected, $actual);
    }

    public function test_header_and_detail_field_counts_are_fixed(): void
    {
        $content = app(ReliefImportationDatGenerator::class)
            ->generate($this->company(), collect([$this->row()]), Carbon::create(2026, 7, 1));

        [$header, $detail] = explode("\r\n", trim($content));

        $this->assertCount(18, str_getcsv($header));
        $this->assertCount(16, str_getcsv($detail));
    }

    public function test_filename_uses_the_importation_marker(): void
    {
        $filename = app(ReliefImportationDatGenerator::class)
            ->filename($this->company(), Carbon::create(2026, 7, 1));

        $this->assertSame('008791976I072026.DAT', $filename);
    }

    public function test_zero_amounts_render_as_bare_zero_in_details_and_two_decimals_in_the_header(): void
    {
        $content = app(ReliefImportationDatGenerator::class)->generate(
            $this->company(),
            collect([$this->row([
                'charges' => '0.00',
                'exempt' => '0.00',
            ])]),
            Carbon::create(2026, 7, 1)
        );

        [$header, $detail] = explode("\r\n", trim($content));

        $this->assertSame('0.00', str_getcsv($header)[11]); // total charges
        $this->assertSame('0.00', str_getcsv($header)[12]); // total exempt
        $this->assertSame('0', str_getcsv($detail)[8]);     // charges
        $this->assertSame('0', str_getcsv($detail)[9]);     // exempt
    }

    public function test_or_number_keeps_leading_zeros(): void
    {
        $content = app(ReliefImportationDatGenerator::class)->generate(
            $this->company(),
            collect([$this->row(['or_number' => '0000'])]),
            Carbon::create(2026, 7, 1)
        );

        $detail = explode("\r\n", trim($content))[1];

        $this->assertStringContainsString('"0000"', $detail);
        $this->assertSame('0000', str_getcsv($detail)[12]);
    }

    public function test_dates_are_written_unquoted_in_month_day_year_order(): void
    {
        $content = app(ReliefImportationDatGenerator::class)
            ->generate($this->company(), collect([$this->row()]), Carbon::create(2026, 7, 1));

        $detail = explode("\r\n", trim($content))[1];
        $fields = str_getcsv($detail);

        $this->assertSame('07/14/2026', $fields[3]);  // assessment date
        $this->assertSame('06/10/2026', $fields[5]);  // importation date
        $this->assertSame('07/31/2026', $fields[13]); // payment date
        $this->assertStringNotContainsString('"07/14/2026"', $detail);
    }

    public function test_detail_carries_the_company_tin_unquoted_and_the_header_quoted(): void
    {
        $content = app(ReliefImportationDatGenerator::class)
            ->generate($this->company(), collect([$this->row()]), Carbon::create(2026, 7, 1));

        [$header, $detail] = explode("\r\n", trim($content));

        $this->assertStringContainsString('"008791976"', $header);
        $this->assertStringNotContainsString('"008791976"', $detail);
        $this->assertSame('008791976', str_getcsv($detail)[14]);
    }

    public function test_header_totals_sum_the_detail_amounts(): void
    {
        $content = app(ReliefImportationDatGenerator::class)->generate(
            $this->company(),
            collect([
                $this->row(),
                $this->row([
                    'import_entry_no' => 'C2077',
                    'dutiable_value' => '11953318.58',
                    'taxable_goods' => '11953318.58',
                    'vat_payable' => '1434398.23',
                ]),
            ]),
            Carbon::create(2026, 7, 1)
        );

        $header = str_getcsv(explode("\r\n", trim($content))[0]);

        $this->assertSame('20047552.77', $header[10]); // dutiable value
        $this->assertSame('20047552.77', $header[13]); // taxable goods
        $this->assertSame('2405706.33', $header[14]);  // VAT payable
    }

    public function test_mixed_vat_rates_in_one_month_throw(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('one VAT rate per month');

        app(ReliefImportationDatGenerator::class)->generate(
            $this->company(),
            collect([
                $this->row(),
                $this->row(['import_entry_no' => 'C2077', 'vat_rate' => '10.00']),
            ]),
            Carbon::create(2026, 7, 1)
        );
    }
}
