<?php

namespace Tests\Unit;

use App\Services\BIR\ReliefExpandedWtaxAnnualDatGenerator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReliefExpandedWtaxAnnualDatGeneratorTest extends TestCase
{
    private const REFERENCE = 'Docs/Expanded/0087919760000123120251604E.dat';

    private const PAYEE_TIN = 6;
    private const PAYEE_BRANCH = 7;
    private const COMPANY_NAME = 8;
    private const LAST_NAME = 9;
    private const FIRST_NAME = 10;
    private const MIDDLE_NAME = 11;
    private const ATC = 12;
    private const INCOME_PAYMENT = 13;
    private const TAX_RATE = 14;
    private const TAX_WITHHELD = 15;

    private function company(): array
    {
        return config('bir.companies.008791976');
    }

    private function periodEnd(): Carbon
    {
        return Carbon::parse('2025-12-31');
    }

    private function reference(): string
    {
        return file_get_contents(base_path(self::REFERENCE));
    }

    private function rows(): array
    {
        $lines = explode("\r\n", rtrim($this->reference(), "\r\n"));

        array_shift($lines);
        array_pop($lines);

        return array_map(function (string $line) {
            $fields = str_getcsv($line);

            return [
                'payee_tin' => $fields[self::PAYEE_TIN],
                'payee_branch_code' => $fields[self::PAYEE_BRANCH],
                'company_name' => $fields[self::COMPANY_NAME],
                'last_name' => $fields[self::LAST_NAME],
                'first_name' => $fields[self::FIRST_NAME],
                'middle_name' => $fields[self::MIDDLE_NAME],
                'atc_code' => $fields[self::ATC],
                'income_payment' => $fields[self::INCOME_PAYMENT],
                'tax_rate' => $fields[self::TAX_RATE],
                'tax_withheld' => $fields[self::TAX_WITHHELD],
            ];
        }, $lines);
    }

    private function generate(?array $rows = null): string
    {
        return (new ReliefExpandedWtaxAnnualDatGenerator())->generate(
            $this->company(),
            collect($rows ?? $this->rows()),
            $this->periodEnd()
        );
    }

    public function test_it_reproduces_the_1604e_reference_file_byte_for_byte(): void
    {
        $this->assertSame(
            rtrim($this->reference(), "\r\n"),
            rtrim($this->generate(), "\r\n")
        );
    }

    public function test_the_filename_matches_the_annual_reference_file(): void
    {
        $this->assertSame(
            '0087919760000123120251604E.dat',
            (new ReliefExpandedWtaxAnnualDatGenerator())->filename($this->company(), $this->periodEnd())
        );
    }

    public function test_annual_record_shapes_and_totals_are_fixed(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));

        $this->assertCount(61, $lines);
        $this->assertCount(4, str_getcsv($lines[0]));
        $this->assertCount(6, str_getcsv($lines[60]));

        foreach (array_slice($lines, 1, -1) as $line) {
            $this->assertCount(16, str_getcsv($line));
        }

        $this->assertSame('H1604E,008791976,0000,12/31/2025', $lines[0]);
        $this->assertSame(
            'D3,1604E,008791976,0000,12/31/2025,1,007086184,0000,"ACERSTEEL INDUSTRIAL SALES INC",,,,WC158,3682716.00,1.00,36827.16',
            $lines[1]
        );
        $this->assertSame('C3,1604E,008791976,0000,12/31/2025,241326.68', $lines[60]);
    }

    public function test_company_and_individual_name_columns_follow_schedule_three(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));

        $company = str_getcsv($lines[1]);
        $individual = str_getcsv($lines[7]);

        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', $company[self::COMPANY_NAME]);
        $this->assertSame('', $company[self::LAST_NAME]);
        $this->assertSame('', $company[self::FIRST_NAME]);

        $this->assertSame('', $individual[self::COMPANY_NAME]);
        $this->assertSame('BANSIL', $individual[self::LAST_NAME]);
        $this->assertSame('ANNIE', $individual[self::FIRST_NAME]);
    }

    public function test_line_endings_are_crlf_with_a_trailing_terminator(): void
    {
        $content = $this->generate();

        $this->assertStringEndsWith("\r\n", $content);
        $this->assertSame(substr_count($content, "\r\n"), substr_count($content, "\n"));
        $this->assertSame(61, substr_count($content, "\r\n"));
    }
}
