<?php

namespace App\Imports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Checks an Expanded WTAX workbook before the selected month is replaced.
 *
 * Two problems have to stop the whole upload rather than mark individual rows:
 *
 * 1. **A missing column.** If `income_payment` is absent the importer would store
 *    zeros for every payee, and the month would look imported when it is not.
 * 2. **A month mismatch.** Every row's `Reporting_Month` must fall inside the month
 *    chosen on the form. The stored reporting period comes from that choice, so a
 *    November workbook uploaded as December would be filed as December with nothing
 *    on screen to say so.
 *
 * Both run before ExpandedWtaxImport, and before the delete that clears the month,
 * so a rejected file leaves the existing month exactly as it was. The transaction
 * in VatInputController would roll the delete back anyway; checking first is what
 * turns a raw "Import failed" into a message naming the column or the row.
 *
 * The class doubles as its own reader -- it implements the maatwebsite concerns it
 * needs so that check() can read the sheet through the same heading-row and
 * formula settings the real importer uses. Anything it disagreed with would be a
 * check against a file the importer never sees.
 */
class ExpandedWtaxUploadPreflight implements ToArray, WithCalculatedFormulas, WithHeadingRow
{
    /** Offending rows are named individually up to this many, then counted. */
    private const ROWS_NAMED = 8;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    public function headingRow(): int
    {
        return 1;
    }

    /**
     * The maatwebsite ToArray hook. Deliberately not SkipsEmptyRows: keeping every
     * row means the array index still maps to the worksheet row number, which is
     * what makes the error message something the user can act on.
     */
    public function array(array $rows): void
    {
        $this->rows = $rows;
    }

    /**
     * @param  \Illuminate\Http\UploadedFile|string  $file
     * @param  string  $reportingPeriod  the month chosen on the upload form
     * @return string[] empty when the file may be imported
     */
    public function check($file, string $reportingPeriod): array
    {
        $missing = $this->missingColumns($file);

        if ($missing !== []) {
            return [
                'The workbook is missing ' . (count($missing) === 1 ? 'the column ' : 'the columns ')
                . implode(', ', $missing) . '. ' . $this->layoutHint(),
            ];
        }

        return $this->monthMismatches($file, Carbon::parse($reportingPeriod));
    }

    /**
     * @return string[] the template's own header names, for the ones not found
     */
    private function missingColumns($file): array
    {
        $sheets = Excel::toArray(new HeadingRowImport($this->headingRow()), $file);

        $headings = array_map(
            fn ($heading) => strtolower(trim((string) $heading)),
            (array) ($sheets[0][0] ?? [])
        );

        $missing = [];

        foreach (ExpandedWtaxImport::COLUMNS as $header => $acceptedKeys) {
            if (array_intersect($acceptedKeys, $headings) === []) {
                $missing[] = $header;
            }
        }

        return $missing;
    }

    /**
     * @return string[]
     */
    private function monthMismatches($file, Carbon $month): array
    {
        Excel::import($this, $file);

        $errors = [];
        $extra = 0;

        foreach ($this->rows as $index => $row) {
            if ($this->isBlank($row)) {
                continue;
            }

            // Heading row 1, so the first data row is worksheet row 2.
            $worksheetRow = $index + $this->headingRow() + 1;
            $rowMonth = $this->rowMonth($row);

            if ($rowMonth !== null && $rowMonth->isSameMonth($month)) {
                continue;
            }

            if (count($errors) >= self::ROWS_NAMED) {
                $extra++;

                continue;
            }

            $errors[] = $rowMonth === null
                ? "Row {$worksheetRow}: Reporting_Month is blank or unreadable."
                : "Row {$worksheetRow}: Reporting_Month is {$rowMonth->format('m/d/Y')}, "
                    . "but this upload is for {$month->format('F Y')}.";
        }

        if ($errors !== []) {
            $errors[] = $extra > 0
                ? "...and {$extra} more row(s) outside {$month->format('F Y')}. "
                    . 'The BIR template covers one reporting month per file.'
                : 'The BIR template covers one reporting month per file. Upload each month separately, '
                    . 'or correct the Reporting_Month column.';
        }

        return $errors;
    }

    /**
     * Excel hands a date cell over as a serial number when the data is read
     * unformatted, but a CSV gives plain text and some writers give a DateTime.
     */
    private function rowMonth(array $row): ?Carbon
    {
        $value = $this->value($row, 'Reporting_Month');

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_null($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))
                : Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A spacer line between blocks of payees. Only a row with nothing at all in any
     * of the eleven columns is skipped, so a row that carries figures but no month
     * is still reported.
     */
    private function isBlank(array $row): bool
    {
        foreach (array_keys(ExpandedWtaxImport::COLUMNS) as $header) {
            if (trim((string) $this->value($row, $header)) !== '') {
                return false;
            }
        }

        return true;
    }

    private function value(array $row, string $column): mixed
    {
        foreach (ExpandedWtaxImport::COLUMNS[$column] as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    private function layoutHint(): string
    {
        return 'The Expanded WTAX upload uses the BIR 1601EQ Schedule 1 layout, with headings on row 1: '
            . implode(', ', array_keys(ExpandedWtaxImport::COLUMNS)) . '.';
    }
}
