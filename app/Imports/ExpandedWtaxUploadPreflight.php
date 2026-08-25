<?php

namespace App\Imports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Facades\Excel;
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
class ExpandedWtaxUploadPreflight implements ToArray, WithCalculatedFormulas
{
    /** Offending rows are named individually up to this many, then counted. */
    private const ROWS_NAMED = 8;

    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    /** @var 'bir'|'system'|null */
    private ?string $layout = null;

    /** @var array<string, int> */
    private array $columnIndexes = [];

    private int $headingRow = 0;

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
        Excel::import($this, $file);
        $this->detectLayout();

        $missing = $this->missingColumns();

        if ($missing !== []) {
            return [
                'The workbook is missing ' . (count($missing) === 1 ? 'the column ' : 'the columns ')
                . implode(', ', $missing) . '. ' . $this->layoutHint(),
            ];
        }

        return $this->monthMismatches(Carbon::parse($reportingPeriod));
    }

    /**
     * @return string[] the template's own header names, for the ones not found
     */
    private function missingColumns(): array
    {
        if ($this->layout === null) {
            return array_keys(ExpandedWtaxImport::COLUMNS);
        }

        $columns = $this->layout === 'system'
            ? ExpandedWtaxImport::SYSTEM_COLUMNS
            : ExpandedWtaxImport::COLUMNS;

        $missing = [];

        foreach ($columns as $header => $acceptedKeys) {
            if (! array_key_exists($header, $this->columnIndexes)) {
                $missing[] = $header;
            }
        }

        if ($this->layout === 'system') {
            $missing = array_values(array_diff($missing, ['No', 'Reference', 'Total']));
        }

        return $missing;
    }

    /**
     * @return string[]
     */
    private function monthMismatches(Carbon $month): array
    {
        $errors = [];
        $extra = 0;

        foreach ($this->rows as $index => $row) {
            if ($index <= $this->headingRow) {
                continue;
            }

            if ($this->isBlank($row)) {
                continue;
            }

            if ($this->isSystemSummaryRow($row)) {
                continue;
            }

            $worksheetRow = $index + 1;
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
        $value = $this->layout === 'system'
            ? $this->value($row, 'Date')
            : $this->value($row, 'Reporting_Month');

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
        $columns = $this->layout === 'system'
            ? ExpandedWtaxImport::SYSTEM_COLUMNS
            : ExpandedWtaxImport::COLUMNS;

        foreach (array_keys($columns) as $header) {
            if (trim((string) $this->value($row, $header)) !== '') {
                return false;
            }
        }

        return true;
    }

    private function value(array $row, string $column): mixed
    {
        $index = $this->columnIndexes[$column] ?? null;

        return $index === null ? null : ($row[$index] ?? null);
    }

    private function layoutHint(): string
    {
        return 'Use either the BIR 1601EQ Schedule 1 layout with headings '
            . implode(', ', array_keys(ExpandedWtaxImport::COLUMNS))
            . ', or the system Expanded WTAX layout with headings '
            . implode(', ', array_keys(ExpandedWtaxImport::SYSTEM_COLUMNS)) . '.';
    }

    private function detectLayout(): void
    {
        $this->layout = null;
        $this->columnIndexes = [];
        $this->headingRow = 0;
        $bestLayout = null;
        $bestIndexes = [];
        $bestHeadingRow = 0;

        foreach ($this->rows as $index => $row) {
            $headings = $this->headingMap($row);

            $birIndexes = $this->indexesFor($headings, ExpandedWtaxImport::COLUMNS);
            if (count($birIndexes) === count(ExpandedWtaxImport::COLUMNS)) {
                $this->layout = 'bir';
                $this->columnIndexes = $birIndexes;
                $this->headingRow = $index;

                return;
            }

            $systemIndexes = $this->indexesFor($headings, ExpandedWtaxImport::SYSTEM_COLUMNS);
            if (count($systemIndexes) >= 10
                && isset($systemIndexes['Supplier Name'], $systemIndexes['TIN'], $systemIndexes['Date'])
                && $this->hasAnySystemRateColumn($systemIndexes)
            ) {
                $this->layout = 'system';
                $this->columnIndexes = $systemIndexes;
                $this->headingRow = $index;

                return;
            }

            if (count($birIndexes) > count($bestIndexes)) {
                $bestLayout = 'bir';
                $bestIndexes = $birIndexes;
                $bestHeadingRow = $index;
            }

            if (count($systemIndexes) > count($bestIndexes)) {
                $bestLayout = 'system';
                $bestIndexes = $systemIndexes;
                $bestHeadingRow = $index;
            }
        }

        if ($bestLayout !== null && count($bestIndexes) >= 2) {
            $this->layout = $bestLayout;
            $this->columnIndexes = $bestIndexes;
            $this->headingRow = $bestHeadingRow;
        }
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, int>
     */
    private function headingMap(array $row): array
    {
        $headings = [];

        foreach (array_values($row) as $index => $value) {
            $key = $this->normaliseHeading($value);

            if ($key !== '') {
                $headings[$key] = $index;
            }
        }

        return $headings;
    }

    /**
     * @param  array<string, int>  $headings
     * @param  array<string, array<int, string>>  $columns
     * @return array<string, int>
     */
    private function indexesFor(array $headings, array $columns): array
    {
        $indexes = [];

        foreach ($columns as $column => $acceptedKeys) {
            foreach ($acceptedKeys as $key) {
                $normalised = $this->normaliseHeading($key);

                if (array_key_exists($normalised, $headings)) {
                    $indexes[$column] = $headings[$normalised];

                    break;
                }
            }
        }

        return $indexes;
    }

    /**
     * @param  array<string, int>  $indexes
     */
    private function hasAnySystemRateColumn(array $indexes): bool
    {
        foreach (['(1%)', '(2%)', '(5%)', '(10%)', '(15%)'] as $column) {
            if (array_key_exists($column, $indexes)) {
                return true;
            }
        }

        return false;
    }

    private function normaliseHeading($value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string) $value)));
    }

    private function isSystemSummaryRow(array $row): bool
    {
        if ($this->layout !== 'system') {
            return false;
        }

        $label = strtoupper(trim((string) ($this->value($row, 'No') ?: $this->value($row, 'Supplier Name'))));

        return in_array($label, ['TOTAL', 'TOTAL:', 'SUBTOTAL', 'SUBTOTAL:', 'GRAND TOTAL', 'GRAND TOTAL:'], true);
    }
}
