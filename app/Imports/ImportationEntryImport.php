<?php

namespace App\Imports;

use App\Services\ImportationEntryWriter;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

class ImportationEntryImport implements ToArray, WithCalculatedFormulas
{
    /**
     * @var array<string, array<int, string>>
     */
    public const COLUMNS = [
        'Tax Month' => ['taxmonth'],
        'Import Entry No.' => ['importentryno'],
        'Name of Seller' => ['nameofseller'],
        'Assessment / Release Date' => ['assessmentreleasedate', 'assessmentdate', 'releasedate'],
        'Date of Importation' => ['dateofimportation', 'importationdate'],
        'Country of Origin' => ['countryoforigin', 'country'],
        'VAT Rate' => ['vatrate'],
        'Total Landed Cost' => ['totallandedcost'],
        'Dutiable Value' => ['dutiablevalue'],
        'Exempt' => ['exempt'],
        'OR Number' => ['ornumber'],
        'Date of VAT Payment' => ['dateofvatpayment', 'paymentdate'],
    ];

    private const ROWS_NAMED = 10;

    /**
     * @var array<string, int>
     */
    private array $columnIndexes = [];

    private int $headingRow = 0;

    private int $imported = 0;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $prepared = [];

    public function __construct(private ImportationEntryWriter $entries) {}

    public function array(array $rows): void
    {
        $this->detectHeadingRow($rows);
        $missing = $this->missingColumns();

        if ($missing !== []) {
            throw new RuntimeException(
                'The workbook is missing '.(count($missing) === 1 ? 'the column ' : 'the columns ')
                .implode(', ', $missing).'. Use the Importation upload template.'
            );
        }

        $this->prepared = [];
        $this->imported = 0;
        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            if ($index <= $this->headingRow || $this->isBlankRow($row)) {
                continue;
            }

            $worksheetRow = $index + 1;

            try {
                $data = $this->prepareRow($row);
                $key = $this->duplicateKey($data);

                if (isset($seen[$key])) {
                    throw new RuntimeException(
                        "Import Entry No. {$data['import_entry_no']} is repeated for the same tax month "
                        ."on rows {$seen[$key]} and {$worksheetRow}."
                    );
                }

                $seen[$key] = $worksheetRow;
                $this->entries->validate($data, null, true);
                $this->prepared[] = $data;
            } catch (Throwable $th) {
                if (count($errors) < self::ROWS_NAMED) {
                    $errors[] = "Row {$worksheetRow}: ".$this->message($th);
                }
            }
        }

        if ($this->prepared === [] && $errors === []) {
            throw new RuntimeException('The workbook has no importation rows to upload.');
        }

        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
    }

    public function savePreparedRows(): void
    {
        foreach ($this->prepared as $data) {
            $this->entries->create($data);
            $this->imported++;
        }
    }

    public function importedCount(): int
    {
        return $this->imported;
    }

    /**
     * @return array<int, string>
     */
    public function taxMonths(): array
    {
        return collect($this->prepared)
            ->pluck('tax_month')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, mixed>
     */
    private function prepareRow(array $row): array
    {
        $taxMonth = $this->taxMonth($this->value($row, 'Tax Month'));
        $assessmentDate = $this->dateValue($this->value($row, 'Assessment / Release Date'), 'Assessment / Release Date');
        $importationDate = $this->dateValue($this->value($row, 'Date of Importation'), 'Date of Importation');
        $paymentDate = $this->dateValue($this->value($row, 'Date of VAT Payment'), 'Date of VAT Payment');

        return [
            'tax_month' => $taxMonth,
            'import_entry_no' => $this->requiredText($row, 'Import Entry No.'),
            'assessment_date' => $assessmentDate,
            'supplier' => $this->requiredText($row, 'Name of Seller'),
            'importation_date' => $importationDate,
            'country' => $this->requiredText($row, 'Country of Origin'),
            'total_landed_cost' => $this->amount($row, 'Total Landed Cost'),
            'dutiable_value' => $this->amount($row, 'Dutiable Value'),
            'exempt' => $this->amount($row, 'Exempt'),
            'vat_rate' => $this->vatRate($row),
            'or_number' => $this->requiredText($row, 'OR Number'),
            'payment_date' => $paymentDate,
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function detectHeadingRow(array $rows): void
    {
        $this->columnIndexes = [];
        $this->headingRow = 0;
        $bestIndexes = [];
        $bestHeadingRow = 0;

        foreach ($rows as $index => $row) {
            $headings = $this->headingMap($row);
            $indexes = [];

            foreach (self::COLUMNS as $column => $acceptedKeys) {
                foreach ($acceptedKeys as $key) {
                    if (array_key_exists($key, $headings)) {
                        $indexes[$column] = $headings[$key];
                        break;
                    }
                }
            }

            if (count($indexes) === count(self::COLUMNS)) {
                $this->columnIndexes = $indexes;
                $this->headingRow = $index;

                return;
            }

            if (count($indexes) > count($bestIndexes)) {
                $bestIndexes = $indexes;
                $bestHeadingRow = $index;
            }
        }

        $this->columnIndexes = $bestIndexes;
        $this->headingRow = $bestHeadingRow;
    }

    /**
     * @return string[]
     */
    private function missingColumns(): array
    {
        $missing = [];

        foreach (array_keys(self::COLUMNS) as $column) {
            if (! array_key_exists($column, $this->columnIndexes)) {
                $missing[] = $column;
            }
        }

        return $missing;
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

    private function normaliseHeading($value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', trim((string) $value)));
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function value(array $row, string $column): mixed
    {
        $index = $this->columnIndexes[$column] ?? null;

        return $index === null ? null : ($row[$index] ?? null);
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function requiredText(array $row, string $column): string
    {
        $value = trim((string) $this->value($row, $column));

        if ($value === '') {
            throw new RuntimeException("{$column} is required.");
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function amount(array $row, string $column): string
    {
        $value = $this->value($row, $column);

        if ($value === null || trim((string) $value) === '') {
            throw new RuntimeException("{$column} is required.");
        }

        $number = $this->parseNumber($value);

        if ($number === null || $number < 0) {
            throw new RuntimeException("{$column} must be a number of 0 or more.");
        }

        return number_format($number, 2, '.', '');
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function vatRate(array $row): string
    {
        $value = $this->value($row, 'VAT Rate');

        if ($value === null || trim((string) $value) === '') {
            throw new RuntimeException('VAT Rate is required.');
        }

        $number = $this->parseNumber($value);

        if ($number === null || $number < 0) {
            throw new RuntimeException('VAT Rate must be a number of 0 or more.');
        }

        if ($number > 0 && $number <= 1) {
            $number *= 100;
        }

        return number_format($number, 2, '.', '');
    }

    private function parseNumber($value): ?float
    {
        $cleanValue = preg_replace('/[^\d.-]/', '', (string) $value);

        return is_numeric($cleanValue) ? round((float) $cleanValue, 2) : null;
    }

    private function taxMonth($value): string
    {
        if ($value === null || trim((string) $value) === '') {
            throw new RuntimeException('Tax Month is required.');
        }

        try {
            $date = is_numeric($value)
                ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))
                : Carbon::parse((string) $value);

            return $date->startOfMonth()->toDateString();
        } catch (Throwable) {
            throw new RuntimeException('Tax Month is blank or unreadable.');
        }
    }

    private function dateValue($value, string $column): string
    {
        if ($value === null || trim((string) $value) === '') {
            throw new RuntimeException("{$column} is required.");
        }

        try {
            $date = is_numeric($value)
                ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))
                : Carbon::parse((string) $value);

            return $date->toDateString();
        } catch (Throwable) {
            throw new RuntimeException("{$column} is blank or unreadable.");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function duplicateKey(array $data): string
    {
        $taxMonth = Carbon::parse($data['tax_month'])->startOfMonth()->toDateString();
        $entryNo = $this->entries->birText((string) $data['import_entry_no']);

        return $taxMonth.'|'.$entryNo;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function message(Throwable $th): string
    {
        if (method_exists($th, 'errors')) {
            return collect($th->errors())->flatten()->implode(' ');
        }

        return $th->getMessage();
    }
}
