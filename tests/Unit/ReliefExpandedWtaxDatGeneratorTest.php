<?php

namespace Tests\Unit;

use App\Services\BIR\ReliefExpandedWtaxDatGenerator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The reference file Docs/Expanded/0087919760000123120251604E.dat is the spec.
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

    public function test_it_reproduces_the_reference_file_byte_for_byte(): void
    {
        $this->assertSame($this->reference(), $this->generate());
    }

    public function test_the_filename_matches_the_reference_file(): void
    {
        $this->assertSame(
            '0087919760000123120251604E.dat',
            (new ReliefExpandedWtaxDatGenerator())->filename($this->company(), $this->period())
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

        $this->assertCount(4, str_getcsv($lines[0]));
        $this->assertCount(6, str_getcsv($lines[count($lines) - 1]));

        foreach (array_slice($lines, 1, -1) as $line) {
            $this->assertCount(16, str_getcsv($line));
        }
    }

    public function test_the_header_carries_the_agent_tin_branch_and_period_end(): void
    {
        $header = explode("\r\n", $this->generate())[0];

        $this->assertSame('H1604E,008791976,0000,12/31/2025', $header);
    }

    public function test_the_control_record_totals_the_detail_tax_withheld(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));
        $control = str_getcsv(array_pop($lines));
        array_shift($lines);

        $expected = 0.0;
        foreach ($lines as $line) {
            $expected += (float) str_getcsv($line)[15];
        }

        $this->assertSame('C3', $control[0]);
        $this->assertSame('1604E', $control[1]);
        $this->assertSame('12/31/2025', $control[4]);
        $this->assertSame(number_format(round($expected, 2), 2, '.', ''), $control[5]);
        $this->assertSame('241326.68', $control[5]);
    }

    public function test_sequence_numbers_run_from_one_in_generation_order(): void
    {
        $lines = explode("\r\n", rtrim($this->generate(), "\r\n"));
        $details = array_slice($lines, 1, -1);

        foreach ($details as $index => $line) {
            $this->assertSame((string) ($index + 1), str_getcsv($line)[5]);
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
            'D3,1604E,008791976,0000,12/31/2025,1,007086184,0000,'
            . '"ACERSTEEL INDUSTRIAL SALES INC",,,,WC158,3682716.00,1.00,36827.16',
            $line
        );
    }

    public function test_an_individual_row_quotes_the_name_parts_it_has(): void
    {
        $line = explode("\r\n", $this->generate())[7];

        $this->assertSame(
            'D3,1604E,008791976,0000,12/31/2025,7,220052738,0000,,'
            . '"BANSIL","ANNIE",,WI516,5865.60,10.00,586.56',
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

        $this->assertSame('-51600.00', $fields[13]);
        $this->assertSame('5.00', $fields[14]);
        $this->assertSame('-2580.00', $fields[15]);
    }

    public function test_company_names_are_truncated_to_fifty_characters(): void
    {
        $rows = $this->rows();
        $rows[0]['company_name'] = 'ACERSTEEL INDUSTRIAL SALES INCORPORATED AND SUBSIDIARIES HOLDINGS';

        $name = str_getcsv(explode("\r\n", $this->generate($rows))[1])[8];

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

        $name = str_getcsv(explode("\r\n", $this->generate($rows))[1])[8];

        $this->assertStringNotContainsString(',', $name);
        $this->assertStringNotContainsString('&', $name);
        $this->assertStringContainsString('AND', $name);
        $this->assertStringStartsWith('ACERSTEEL INDUSTRIAL', $name);
    }

    /**
     * The reference file keeps a double space inside one payee name, so the
     * generator must not tidy internal spacing on the way out. Normalisation is
     * the importer's job.
     */
    public function test_internal_spacing_is_left_exactly_as_stored(): void
    {
        $names = collect(explode("\r\n", rtrim($this->generate(), "\r\n")))
            ->slice(1, 59)
            ->map(fn (string $line) => str_getcsv($line)[8])
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
        $this->assertSame('H1604E,008791976,0000,12/31/2025', $lines[0]);
        $this->assertSame('C3,1604E,008791976,0000,12/31/2025,0.00', $lines[1]);
    }

    /**
     * The period is normalised to month end, so any day inside December 2025
     * yields the same file.
     */
    public function test_any_day_inside_the_month_yields_the_same_period_end(): void
    {
        $generator = new ReliefExpandedWtaxDatGenerator();
        $rows = collect($this->rows());

        $this->assertSame(
            $generator->generate($this->company(), $rows, Carbon::parse('2025-12-31')),
            $generator->generate($this->company(), $rows, Carbon::parse('2025-12-09'))
        );
    }
}
