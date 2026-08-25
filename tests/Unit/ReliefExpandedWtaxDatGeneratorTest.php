<?php

namespace Tests\Unit;

use App\Services\BIR\ReliefExpandedWtaxDatGenerator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The reference file Docs/Expanded/0087919760000123120251604E.dat is still useful
 * sample data, but it is the old annual 1604E body. The generator now emits the
 * 1601EQ/QAP body required by the Alphalist Validation System.
 *
 * The main test reads its 59 detail rows back into plain field values and asks
 * the generator to rebuild the file. That is not circular: the generator has to
 * re-derive the header, the quoting, the two-decimal amount formatting, the
 * sequence numbers and the control-record total from scratch. Nothing but the
 * semantic field values is handed back to it.
 *
 * Note that Docs/Expanded/EXPANDED WTAX.xlsx is NOT the input to this file --
 * the workbook covers July 2026 while the DAT covers December 2025. They are
 * independent samples, so the Excel side is covered by ExpandedWtaxImportTest.
 */
class ReliefExpandedWtaxDatGeneratorTest extends TestCase
{
    private const REFERENCE = 'Docs/Expanded/0087919760000123120251604E.dat';

    /**
     * The BIR's own 1601EQ output, and the format authority for this file.
     */
    private const BIR_REFERENCE = 'Docs/Expanded/compareDatFile/original/00879197600000320251601EQ.DAT';

    /**
     * Where each stored value sits in a line of the sample file. That file is the
     * old annual 1604E body, so its own column order is not the 1601EQ order this
     * generator now writes -- this map exists only to read fixtures back out of it.
     */
    private const DETAIL_COLUMNS = [
        6 => 'payee_tin',
        7 => 'payee_branch_code',
        8 => 'company_name',
        9 => 'last_name',
        10 => 'first_name',
        11 => 'middle_name',
        12 => 'atc_code',
        13 => 'income_payment',
        14 => 'tax_rate',
        15 => 'tax_withheld',
    ];

    /**
     * Where each value sits in a generated 1601EQ detail line. Matches the
     * BIR-generated reference
     * Docs/Expanded/compareDatFile/original/00879197600000320251601EQ.DAT.
     */
    private const SEQUENCE = 2;
    private const PAYEE_TIN = 3;
    private const PAYEE_BRANCH = 4;
    private const COMPANY_NAME = 5;
    private const LAST_NAME = 6;
    private const FIRST_NAME = 7;
    private const MIDDLE_NAME = 8;
    private const PERIOD = 9;
    private const ATC = 10;
    private const RATE = 11;
    private const INCOME_PAYMENT = 12;
    private const TAX_WITHHELD = 13;

    private function reference(): string
    {
        return file_get_contents(base_path(self::REFERENCE));
    }

    private function company(): array
    {
        return config('bir.companies.008791976');
    }

    private function period(): Carbon
    {
        return Carbon::parse('2025-12-01');
    }

    /**
     * The reference detail rows as the database would hold them.
     */
    private function rows(): array
    {
        $lines = explode("\r\n", rtrim($this->reference(), "\r\n"));

        array_shift($lines); // header
        array_pop($lines);   // control record

        return array_map(function (string $line) {
            $fields = str_getcsv($line);
            $row = [];

            foreach (self::DETAIL_COLUMNS as $index => $key) {
                $row[$key] = $fields[$index];
            }

            // Stored rows always carry a payee type; it drives nothing in the
            // generator, but keeping it makes the fixtures realistic.
            $row['payee_type'] = $row['company_name'] === '' ? 'individual' : 'company';

            return $row;
        }, $lines);
    }

    private function generate(?array $rows = null): string
    {
        return (new ReliefExpandedWtaxDatGenerator())->generate(
            $this->company(),
            collect($rows ?? $this->rows()),
            $this->period()
        );
    }

    public function test_it_generates_a_1601eq_qap_file_from_the_reference_rows(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));

        $this->assertCount(61, $lines);
        $this->assertSame('HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",12/2025,045', $lines[0]);
        $this->assertSame(
            'D1,1601EQ,1,007086184,0000,"ACERSTEEL INDUSTRIAL SALES INC",,,,12/2025,WC158,1.00,3682716.00,36827.16',
            $lines[1]
        );
        $this->assertSame('C1,1601EQ,008791976,0000,12/2025,14284247.61,241326.68', $lines[60]);
    }

    /**
     * The strongest check available: read the BIR-generated file's own field values
     * back out, hand them to the generator, and require the bytes to match. March
     * 2025 has no two rows sharing month, TIN, ATC and rate, so consolidation would
     * not have merged anything and the comparison is fair.
     */
    public function test_it_reproduces_the_bir_generated_reference_file_byte_for_byte(): void
    {
        $expected = rtrim(file_get_contents(base_path(self::BIR_REFERENCE)), "\r\n");

        $rows = array_map(function (string $line) {
            $fields = str_getcsv($line);

            return [
                'payee_tin' => $fields[self::PAYEE_TIN],
                'payee_branch_code' => $fields[self::PAYEE_BRANCH],
                'company_name' => $fields[self::COMPANY_NAME],
                'last_name' => $fields[self::LAST_NAME],
                'first_name' => $fields[self::FIRST_NAME],
                'middle_name' => $fields[self::MIDDLE_NAME],
                'atc_code' => $fields[self::ATC],
                'tax_rate' => $fields[self::RATE],
                'income_payment' => $fields[self::INCOME_PAYMENT],
                'tax_withheld' => $fields[self::TAX_WITHHELD],
            ];
        }, array_slice(explode("\r\n", $expected), 1, -1));

        $actual = (new ReliefExpandedWtaxDatGenerator())->generate(
            $this->company(),
            collect($rows),
            Carbon::parse('2025-03-01')
        );

        $this->assertSame($expected, rtrim($actual, "\r\n"));
    }

    public function test_the_filename_matches_the_reference_file(): void
    {
        $this->assertSame(
            '00879197600001220251601EQ.DAT',
            (new ReliefExpandedWtaxDatGenerator())->filename($this->company(), $this->period())
        );
    }

    /**
     * The month the AVS run in Docs/Expanded/error/ was made against, so the two
     * strings the fix plan names are asserted verbatim.
     */
    public function test_july_2026_produces_the_header_and_filename_bir_expects(): void
    {
        $generator = new ReliefExpandedWtaxDatGenerator();
        $july = Carbon::parse('2026-07-01');

        $header = explode("\r\n", $generator->generate($this->company(), collect(), $july))[0];

        $this->assertSame('HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",07/2026,045', $header);
        $this->assertSame(
            '00879197600000720261601EQ.DAT',
            $generator->filename($this->company(), $july)
        );
    }

    public function test_line_endings_are_crlf_with_a_trailing_terminator(): void
    {
        $content = $this->generate();

        $this->assertStringEndsWith("\r\n", $content);
        // Every LF belongs to a CRLF pair: no bare newline anywhere.
        $this->assertSame(substr_count($content, "\r\n"), substr_count($content, "\n"));
        $this->assertSame(61, substr_count($content, "\r\n")); // 1 + 59 + 1
    }

    public function test_field_counts_are_fixed_per_record_type(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));

        $this->assertCount(7, str_getcsv($lines[0]));
        $this->assertCount(7, str_getcsv($lines[count($lines) - 1]));

        foreach (array_slice($lines, 1, -1) as $line) {
            $this->assertCount(14, str_getcsv($line));
        }
    }

    /**
     * The whole point of the fix: every value in the position the Alphalist
     * Validation System reads it from.
     */
    public function test_detail_fields_sit_in_the_bir_positions(): void
    {
        $fields = str_getcsv(explode("\r\n", $this->generate())[7]);

        $this->assertCount(14, $fields);
        $this->assertSame('D1', $fields[0]);
        $this->assertSame('1601EQ', $fields[1]);
        $this->assertSame('7', $fields[self::SEQUENCE]);
        $this->assertSame('220052738', $fields[self::PAYEE_TIN]);
        $this->assertSame('0000', $fields[self::PAYEE_BRANCH]);
        $this->assertSame('', $fields[self::COMPANY_NAME]);
        $this->assertSame('BANSIL', $fields[self::LAST_NAME]);
        $this->assertSame('ANNIE', $fields[self::FIRST_NAME]);
        $this->assertSame('', $fields[self::MIDDLE_NAME]);
        $this->assertSame('12/2025', $fields[self::PERIOD]);
        $this->assertSame('WI516', $fields[self::ATC]);
        $this->assertSame('10.00', $fields[self::RATE]);
        $this->assertSame('5865.60', $fields[self::INCOME_PAYMENT]);
        $this->assertSame('586.56', $fields[self::TAX_WITHHELD]);
    }

    /**
     * The agent's TIN, branch and the month-end date used to lead every detail
     * row. AVS read the first of them as the payee TIN, which is what produced
     * "Invalid Payees TIN" on all 47 lines of the July 2026 run.
     */
    public function test_detail_rows_do_not_repeat_the_withholding_agent(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));

        foreach (array_slice($lines, 1, -1) as $line) {
            $fields = str_getcsv($line);

            $this->assertNotSame('008791976', $fields[self::SEQUENCE]);
            $this->assertStringNotContainsString('/31/', $line);
        }
    }

    public function test_the_header_carries_the_agent_tin_branch_name_return_period_and_rdo(): void
    {
        $header = explode("\r\n", $this->generate())[0];

        $this->assertSame('HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",12/2025,045', $header);
    }

    public function test_the_control_record_totals_the_detail_amounts(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));
        $control = str_getcsv(array_pop($lines));
        array_shift($lines);

        $expectedIncome = 0.0;
        $expectedTax = 0.0;
        foreach ($lines as $line) {
            $expectedIncome += (float) str_getcsv($line)[self::INCOME_PAYMENT];
            $expectedTax += (float) str_getcsv($line)[self::TAX_WITHHELD];
        }

        $this->assertSame('C1', $control[0]);
        $this->assertSame('1601EQ', $control[1]);
        $this->assertSame('008791976', $control[2]);
        $this->assertSame('0000', $control[3]);
        $this->assertSame('12/2025', $control[4]);
        $this->assertSame(number_format(round($expectedIncome, 2), 2, '.', ''), $control[5]);
        $this->assertSame(number_format(round($expectedTax, 2), 2, '.', ''), $control[6]);
        $this->assertSame('14284247.61', $control[5]);
        $this->assertSame('241326.68', $control[6]);
    }

    public function test_every_detail_line_uses_schedule_one(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));
        $details = array_slice($lines, 1, -1);

        foreach ($details as $index => $line) {
            $fields = str_getcsv($line);

            $this->assertSame('D1', $fields[0]);
            $this->assertSame((string) ($index + 1), $fields[self::SEQUENCE]);
        }
    }

    /**
     * A company payee leaves the three individual name columns bare rather than
     * writing empty quoted strings.
     */
    public function test_a_company_row_quotes_only_the_company_name(): void
    {
        $line = explode("\r\n", $this->generate())[1];

        $this->assertSame(
            'D1,1601EQ,1,007086184,0000,"ACERSTEEL INDUSTRIAL SALES INC"'
            . ',,,,12/2025,WC158,1.00,3682716.00,36827.16',
            $line
        );
    }

    public function test_an_individual_row_quotes_the_name_parts_it_has(): void
    {
        $line = explode("\r\n", $this->generate())[7];

        $this->assertSame(
            'D1,1601EQ,7,220052738,0000,,"BANSIL","ANNIE",'
            . ',12/2025,WI516,10.00,5865.60,586.56',
            $line
        );
    }

    /**
     * Reversals are filed as negative amounts, not dropped or absolute-valued.
     */
    public function test_negative_amounts_survive_generation(): void
    {
        $line = explode("\r\n", $this->generate())[19];
        $fields = str_getcsv($line);

        $this->assertSame('5.00', $fields[self::RATE]);
        $this->assertSame('-51600.00', $fields[self::INCOME_PAYMENT]);
        $this->assertSame('-2580.00', $fields[self::TAX_WITHHELD]);
    }

    public function test_company_names_are_truncated_to_fifty_characters(): void
    {
        $rows = $this->rows();
        $rows[0]['company_name'] = 'ACERSTEEL INDUSTRIAL SALES INCORPORATED AND SUBSIDIARIES HOLDINGS';

        $name = str_getcsv(explode("\r\n", $this->generate($rows))[1])[self::COMPANY_NAME];

        $this->assertSame(50, strlen($name));
        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INCORPORATED AND SUBSID', $name);
    }

    /**
     * Both characters break the CSV or the BIR parser. The validator rejects them
     * upstream; this is the generator's own last line of defence.
     */
    public function test_commas_are_replaced_and_ampersands_expanded(): void
    {
        $rows = $this->rows();
        $rows[0]['company_name'] = 'Acersteel Industrial, Sales & Supply';

        $name = str_getcsv(explode("\r\n", $this->generate($rows))[1])[self::COMPANY_NAME];

        $this->assertStringNotContainsString(',', $name);
        $this->assertStringNotContainsString('&', $name);
        $this->assertStringContainsString('AND', $name);
        $this->assertStringStartsWith('ACERSTEEL INDUSTRIAL', $name);
    }

    /**
     * The sample file keeps a double space inside one payee name, so the
     * generator must not tidy internal spacing on the way out. Normalisation is
     * the importer's job.
     */
    public function test_internal_spacing_is_left_exactly_as_stored(): void
    {
        $names = collect(explode("\r\n", rtrim($this->generate(), "\r\n")))
            ->slice(1, 59)
            ->map(fn (string $line) => str_getcsv($line)[self::COMPANY_NAME])
            ->filter()
            ->values();

        $this->assertTrue($names->contains(
            'PRINTSCAPE  PRINTING SERVICES AND BUSINESS SUPPLIE'
        ));
    }

    public function test_a_month_with_no_rows_still_produces_a_header_and_control_record(): void
    {
        $lines = explode("\r\n", rtrim($this->generate([]), "\r\n"));

        $this->assertCount(2, $lines);
        $this->assertSame('HQAP,H1601EQ,008791976,0000,"FORTRESS STEEL INC",12/2025,045', $lines[0]);
        $this->assertSame('C1,1601EQ,008791976,0000,12/2025,0.00,0.00', $lines[1]);
    }

    /**
     * The period is written as a month and year, so any day inside December 2025
     * yields the same file.
     */
    public function test_any_day_inside_the_month_yields_the_same_period(): void
    {
        $generator = new ReliefExpandedWtaxDatGenerator();
        $rows = collect($this->rows());

        $this->assertSame(
            $generator->generate($this->company(), $rows, Carbon::parse('2025-12-31')),
            $generator->generate($this->company(), $rows, Carbon::parse('2025-12-09'))
        );
    }
}
