<?php

namespace App\Imports;

use App\Models\ExpandedWtaxEntry;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Runs the BIR row rules over an Expanded WTAX workbook before the upload replaces
 * anything, so a payee TIN eight digits long is refused at upload with the worksheet
 * row named, instead of importing and then blocking Generate DAT weeks later.
 *
 * Two things make that possible without a second set of import rules:
 *
 * 1. Rows are read through ExpandedWtaxRowMapper, the same mapping
 *    ExpandedWtaxImport persists. What is validated here is what would have been
 *    stored -- layout, skipped rows, names, TIN, branch, ATC, amounts and all.
 * 2. The mapped attributes are validated as ExpandedWtaxEntry::toBirExpandedRow()
 *    presents them, on an unsaved model, so the decimal casts and the BIR field
 *    names match what the Generate DAT check sees. Nothing is written: the model is
 *    constructed and read, never saved.
 *
 * Structural problems -- a missing column, a row from another month, a company name
 * under two TINs -- stay in ExpandedWtaxUploadPreflight and run first. This class
 * assumes the columns are there.
 *
 * The Generate DAT validation is deliberately left in place. It still guards rows
 * that predate this check and rows that arrive by any other path.
 */
class ExpandedWtaxBirInfoPreflight implements ToArray, WithCalculatedFormulas
{
    /**
     * Where each BIR field the validator names is corrected, per layout. The
     * validator speaks in stored column names; the person fixing the file is
     * looking at workbook headings.
     *
     * @var array<string, array<string, string>>
     */
    private const WORKBOOK_FIELDS = [
        'bir' => [
            'payee_tin' => 'Vendor_TIN',
            'payee_branch_code' => 'branchCode',
            'payee_type' => 'companyName / surName',
            'company_name' => 'companyName',
            'last_name' => 'surName',
            'first_name' => 'firstName',
            'middle_name' => 'middleName',
            'atc_code' => 'ATC',
            'tax_rate' => 'ewt_rate',
            'income_payment' => 'income_payment',
            'tax_withheld' => 'tax_amount',
        ],
        'system' => [
            'payee_tin' => 'TIN',
            'payee_branch_code' => 'TIN',
            'payee_type' => 'Supplier Name',
            'company_name' => 'Supplier Name',
            'last_name' => 'Supplier Name',
            'first_name' => 'Supplier Name',
            'middle_name' => 'Supplier Name',
            'atc_code' => 'ATC mapping for the rate column',
            'tax_rate' => 'rate column',
            'income_payment' => 'rate column',
            'tax_withheld' => 'rate column',
        ],
    ];

    /**
     * Fields written per rate column in a system export. An error on one of these
     * names the column; an error about the payee's identity does not, because the
     * identity is one cell shared by every rate column on the line.
     */
    private const PER_COLUMN_FIELDS = ['atc_code', 'tax_rate', 'income_payment', 'tax_withheld'];

    /** The numeric cells whose raw text is inspected before it is converted. */
    private const BIR_NUMERIC_COLUMNS = ['income_payment', 'ewt_rate', 'tax_amount'];

    /** @var array<int, array<int, mixed>> */
    private array $rows = [];

    /** @var 'bir'|'system'|null */
    private ?string $layout = null;

    /** @var array<string, int> */
    private array $columnIndexes = [];

    private int $headingRow = 0;

    public function __construct(private BirExpandedWtaxRowValidator $validator)
    {
    }

    /**
     * Deliberately not SkipsEmptyRows: every row is kept so the array index still
     * maps to the worksheet row number the user is looking at.
     */
    public function array(array $rows): void
    {
        $this->rows = $rows;
    }

    /**
     * @param  \Illuminate\Http\UploadedFile|string  $file
     * @param  array<string, mixed>|null  $withholdingAgent
     * @return array<int, array<string, mixed>> empty when every row is filable
     */
    public function check(
        $file,
        string $reportingPeriod,
        ?array $withholdingAgent = null,
        bool $useRowReportingPeriod = false,
        string $reportType = 'quarterly'
    ): array {
        $this->rows = [];

        Excel::import($this, $file);
        $this->detectLayout();

        if ($this->layout === null) {
            return [];
        }

        $mapper = new ExpandedWtaxRowMapper(
            $reportingPeriod,
            $withholdingAgent,
            $useRowReportingPeriod,
            $reportType === 'annual' ? 'annual' : 'quarterly'
        );

        $issues = [];

        foreach ($this->rows as $index => $row) {
            if ($index <= $this->headingRow) {
                continue;
            }

            $data = array_values($row);

            if ($this->isBlankRow($data)) {
                continue;
            }

            $worksheetRow = $index + 1;

            $entries = $this->layout === 'system'
                ? $mapper->mapSystemRow($data, $this->columnIndexes, $worksheetRow)
                : $mapper->mapBirRow($data, $this->columnIndexes, $worksheetRow);

            // A row that names a payee is judged even when it mapped to nothing. In
            // the system layout an unreadable rate cell is read as 0.00, which is
            // precisely why the line produced no entry -- letting it through here
            // would import the payee's month with the amount silently missing.
            if ($entries === [] && ! $this->namesAPayee($data)) {
                continue;
            }

            $name = $entries === [] ? $this->rowName($data) : $this->payeeName($entries);

            foreach ($this->malformedNumbers($data, $worksheetRow, $name) as $issue) {
                $issues[] = $issue;
            }

            if ($entries === []) {
                continue;
            }

            foreach ($entries as $entry) {
                $birRow = (new ExpandedWtaxEntry(ExpandedWtaxRowMapper::attributes($entry)))
                    ->toBirExpandedRow();

                foreach ($this->validator->validate($birRow, $worksheetRow) as $error) {
                    $issues[] = $this->issue(
                        $worksheetRow,
                        $name,
                        $this->storedField($error),
                        $this->problem($error),
                        $entry['source_column'] ?? null
                    );
                }
            }
        }

        return $this->deduplicate($issues);
    }

    /**
     * How many worksheet rows the issues came from, which is not the issue count:
     * one row can fail several rules.
     *
     * @param  array<int, array<string, mixed>>  $issues
     */
    public static function affectedRows(array $issues): int
    {
        return count(array_unique(array_column($issues, 'row')));
    }

    /**
     * A nonblank numeric cell the importer would silently read as 0.00, or read at
     * the wrong scale. "1.234,56" becomes 1.23 and "(1,000.00)" loses its sign, so
     * both are reported rather than converted -- the arithmetic is left alone and
     * only the raw text is judged. Thousands separators and spaces stay acceptable.
     *
     * @param  array<int, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function malformedNumbers(array $data, int $worksheetRow, string $name): array
    {
        $columns = $this->layout === 'system'
            ? array_keys(ExpandedWtaxRowMapper::SYSTEM_RATES)
            : self::BIR_NUMERIC_COLUMNS;

        $issues = [];

        foreach ($columns as $column) {
            $index = $this->columnIndexes[$column] ?? null;

            if ($index === null) {
                continue;
            }

            $value = $data[$index] ?? null;

            if (is_null($value) || is_int($value) || is_float($value) || $value instanceof \DateTimeInterface) {
                continue;
            }

            $raw = trim((string) $value);

            // A blank cell is a legitimate zero in both layouts, and an optional
            // rate column in the system export.
            if ($raw === '' || $this->isReadableNumber($raw)) {
                continue;
            }

            $issues[] = $this->issue(
                $worksheetRow,
                $name,
                $this->layout === 'system' ? 'tax_withheld' : $this->numericField($column),
                "{$column} is not a readable number ({$raw}). Enter a plain number, "
                    . 'without currency symbols, brackets or letters.',
                $this->layout === 'system' ? $column : null
            );
        }

        return $issues;
    }

    private function isReadableNumber(string $raw): bool
    {
        $candidate = str_replace([' ', ',', "\u{00A0}"], '', $raw);

        return preg_match('/^[+-]?\d+(\.\d+)?$/', $candidate) === 1;
    }

    private function numericField(string $column): string
    {
        return match ($column) {
            'income_payment' => 'income_payment',
            'ewt_rate' => 'tax_rate',
            default => 'tax_withheld',
        };
    }

    /**
     * Whether the line identifies somebody, which is what separates a payment row
     * that mapped to nothing from a spacer or a totals line.
     *
     * @param  array<int, mixed>  $data
     */
    private function namesAPayee(array $data): bool
    {
        $name = strtoupper($this->rowName($data));

        if ($name === '' || in_array($name, ['TOTAL', 'GRAND TOTAL', 'SUBTOTAL'], true)) {
            return false;
        }

        return true;
    }

    /**
     * The name as the cells carry it, for a row the mapper produced no entry for.
     *
     * @param  array<int, mixed>  $data
     */
    private function rowName(array $data): string
    {
        $columns = $this->layout === 'system'
            ? ['Supplier Name']
            : ['companyName', 'surName'];

        foreach ($columns as $column) {
            $index = $this->columnIndexes[$column] ?? null;

            if ($index === null) {
                continue;
            }

            $value = trim((string) ($data[$index] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function payeeName(array $entries): string
    {
        foreach ($entries as $entry) {
            $name = trim((string) ($entry['payee_name'] ?? ''));

            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(int $row, string $name, string $field, string $problem, ?string $sourceColumn): array
    {
        $workbookField = self::WORKBOOK_FIELDS[$this->layout][$field] ?? $field;
        $namesColumn = in_array($field, self::PER_COLUMN_FIELDS, true) && $sourceColumn !== null;

        return [
            'row' => $row,
            'name' => $name,
            'record_type' => 'expanded',
            'field' => $field,
            'problem' => $problem,
            // No fix_route: an Expanded payee comes from the workbook, not from
            // Customers or Suppliers, so there is nowhere in the app to send the
            // user. The file is the thing to correct.
            'fix_location' => 'The uploaded workbook. Correct the listed cells and upload again.',
            'needed_fields' => [$workbookField],
            'match_basis' => $namesColumn
                ? "worksheet row {$row}, column {$sourceColumn}"
                : "worksheet row {$row}",
        ];
    }

    /**
     * The stored column a validator message is about, used to name the workbook
     * column that holds it.
     */
    private function storedField(string $error): string
    {
        return match (true) {
            // "tax_withheld X does not match income_payment at R%" names both amount
            // columns, and a plain substring search would settle on whichever is
            // listed first here. The figure being judged is the tax, so the tax
            // column is the one named.
            str_contains($error, 'does not match income_payment') => 'tax_withheld',
            str_contains($error, 'payee_tin') => 'payee_tin',
            str_contains($error, 'payee_branch_code') => 'payee_branch_code',
            str_contains($error, 'payee_type') => 'payee_type',
            str_contains($error, 'company_name') => 'company_name',
            str_contains($error, 'last_name') => 'last_name',
            str_contains($error, 'first_name') => 'first_name',
            str_contains($error, 'middle_name') => 'middle_name',
            str_contains($error, 'ATC') => 'atc_code',
            str_contains($error, 'tax_rate') => 'tax_rate',
            str_contains($error, 'income_payment') => 'income_payment',
            str_contains($error, 'tax_withheld') => 'tax_withheld',
            default => 'bir_info',
        };
    }

    /**
     * The validator's own wording, with its row prefix dropped (the dialog shows the
     * row on its own line) and the stored column names read as workbook headings.
     */
    private function problem(string $error): string
    {
        $problem = preg_replace('/^Row \d+:\s*/', '', $error) ?? $error;
        $opensWithColumn = false;

        foreach (self::WORKBOOK_FIELDS[$this->layout] ?? [] as $stored => $workbook) {
            // payee_type is not a column in either layout -- it is inferred from
            // which name side the file filled in -- and the ATC messages already
            // name the ATC, so rewriting either one only makes the sentence worse.
            if ($stored === 'payee_type' || $stored === 'atc_code') {
                continue;
            }

            $opensWithColumn = $opensWithColumn || str_starts_with($problem, $stored);
            $problem = str_replace($stored, $workbook, $problem);
        }

        // A heading is spelled the way the workbook spells it. Capitalising the
        // first letter of the sentence would turn "firstName is required" into
        // "FirstName", which is a column the user will not find.
        return $opensWithColumn ? $problem : ucfirst($problem);
    }

    /**
     * One worksheet row of a system export becomes an entry per rate column, so the
     * same identity error arrives once per column. Identical issues collapse; the
     * per-column ones stay apart because their column is part of the issue.
     *
     * @param  array<int, array<string, mixed>>  $issues
     * @return array<int, array<string, mixed>>
     */
    private function deduplicate(array $issues): array
    {
        $seen = [];
        $unique = [];

        foreach ($issues as $issue) {
            $key = implode('|', [
                $issue['row'],
                $issue['field'],
                $issue['problem'],
                $issue['match_basis'],
            ]);

            if (array_key_exists($key, $seen)) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $issue;
        }

        return $unique;
    }

    /**
     * The same detection ExpandedWtaxImport applies, run over every row rather than
     * one at a time: whichever row carries the headings is the heading row.
     */
    private function detectLayout(): void
    {
        $this->layout = null;
        $this->columnIndexes = [];
        $this->headingRow = 0;

        foreach ($this->rows as $index => $row) {
            $headings = $this->headingMap(array_values($row));

            $birIndexes = $this->indexesFor($headings, ExpandedWtaxImport::COLUMNS);
            if (count($birIndexes) === count(ExpandedWtaxImport::COLUMNS)) {
                $this->layout = 'bir';
                $this->columnIndexes = $birIndexes;
                $this->headingRow = $index;

                return;
            }

            $systemIndexes = $this->indexesFor($headings, ExpandedWtaxImport::SYSTEM_COLUMNS);
            if (count($systemIndexes) >= 10
                && isset($systemIndexes['Supplier Name'], $systemIndexes['TIN'])
                && $this->hasAnySystemRateColumn($systemIndexes)
            ) {
                $this->layout = 'system';
                $this->columnIndexes = $systemIndexes;
                $this->headingRow = $index;

                return;
            }
        }
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, int>
     */
    private function headingMap(array $row): array
    {
        $headings = [];

        foreach ($row as $index => $value) {
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
        foreach (array_keys(ExpandedWtaxRowMapper::SYSTEM_RATES) as $column) {
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

    /**
     * @param  array<int, mixed>  $data
     */
    private function isBlankRow(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
