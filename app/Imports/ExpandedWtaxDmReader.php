<?php

namespace App\Imports;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/** Reads ACCOUNT DETAILS without importing or retaining business records. */
class ExpandedWtaxDmReader
{
    public function read(string $path, string $month, string $rate): array
    {
        $book = IOFactory::load($path);
        try {
            if ($book->getSheetCount() !== 1) {
                $this->fail('Use a workbook with one ACCOUNT DETAILS worksheet.');
            }
            $sheet = $book->getSheet(0);
            if ($sheet->getHighestDataRow() > 10000) {
                $this->fail('The DM workbook cannot exceed 10,000 rows.');
            }
            $rows = $sheet->rangeToArray('A1:G'.$sheet->getHighestDataRow(), null, true, false);
            if (trim((string) ($rows[0][0] ?? '')) !== 'ACCOUNT DETAILS'
                || array_map(fn ($v) => trim((string) $v), $rows[2] ?? []) !== ['Date', 'Reference', 'Particulars', 'Other Info', 'Debit', 'Credit', 'Balance']) {
                $this->fail('Expected ACCOUNT DETAILS with Date, Reference, Particulars, Other Info, Debit, Credit, Balance headings on row 3.');
            }
            $period = trim((string) ($rows[1][0] ?? ''));
            if (! preg_match('/^Period Covered:\s*(.+?)\s+-\s+(.+)$/i', $period, $matches)) {
                $this->fail('Missing covered period on row 2.');
            }
            foreach ([$matches[1], $matches[2]] as $date) {
                $parsed = DateTimeImmutable::createFromFormat('!F j, Y', trim($date));
                if (! $parsed || $parsed->format('Y-m') !== $month || $parsed->format('F j, Y') !== trim($date)) {
                    $this->fail('Workbook covered period must be inside the selected month.');
                }
            }

            $items = [];
            $seen = [];
            $sectionFound = false;
            $excludedCount = 0;
            $excludedDebit = 0;
            foreach (array_slice($rows, 3, null, true) as $index => $row) {
                $number = $index + 1;
                $first = trim((string) $row[0]);
                $reference = strtoupper(trim((string) $row[1]));
                if (str_contains(strtoupper($first), 'WITHHOLDING TAX PAYABLE')) {
                    if ($sectionFound || ! preg_match('/EXPANDED\s+(\d+(?:\.\d{1,2})?)\s*%\s*$/i', $first, $section)
                        || self::cents($section[1]) !== self::cents($rate)) {
                        $this->fail("Row {$number}: use one account section matching the entered percentage.");
                    }
                    $sectionFound = true;

                    continue;
                }
                if ($reference === '') {
                    if (preg_match('/^(TOTAL|GRAND TOTAL|SUBTOTAL)\s*:?$/i', $first)
                        || count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    $this->fail("Row {$number}: unrecognized line or missing document reference.");
                }
                if (! $sectionFound) {
                    $this->fail("Row {$number}: missing account/rate section.");
                }
                if (! preg_match('/^DM/i', $reference)) {
                    if (! preg_match('/^PV\s*(?:\([A-Z ]+\))?\s*#\s*\d+$/', $reference)) {
                        $this->fail("Row {$number}: unrecognized document reference.");
                    }
                    $excludedCount++;
                    if ($row[4] !== null && $row[4] !== '') {
                        $excludedDebit += $this->amount($row[4], $number);
                    }

                    continue;
                }
                if (! preg_match('/^DM\s*(?:\([A-Z ]+\))?\s*#\s*\d+$/', $reference)) {
                    $this->fail("Row {$number}: malformed DM reference.");
                }
                $reference = preg_replace('/\s+/', '', $reference);
                if (isset($seen[$reference])) {
                    $this->fail("Row {$number}: duplicate DM reference {$reference}.");
                }
                $seen[$reference] = true;
                $date = is_numeric($row[0]) ? Date::excelToDateTimeObject((float) $row[0])->format('m/d/Y') : $first;
                $parsed = DateTimeImmutable::createFromFormat('!m/d/Y', $date);
                if (! $parsed || $parsed->format('m/d/Y') !== $date || $parsed->format('Y-m') !== $month) {
                    $this->fail("Row {$number}: DM date must be valid and inside the selected month.");
                }
                $debit = $this->amount($row[4], $number);
                if ($debit <= 0 || trim((string) $row[2]) === '') {
                    $this->fail("Row {$number}: supplier and positive Debit are required.");
                }
                if ($row[5] !== null && $row[5] !== '' && $this->amount($row[5], $number) !== 0) {
                    $this->fail("Row {$number}: a DM cannot contain both Debit and Credit.");
                }
                $items[] = ['row' => $number, 'reference' => $reference, 'date' => $parsed->format('Y-m-d'), 'supplier' => trim((string) $row[2]), 'debit_cents' => $debit];
            }
            if (! $items) {
                $this->fail('No valid DM rows were found.');
            }

            return ['items' => $items, 'excluded_count' => $excludedCount, 'excluded_debit' => self::money($excludedDebit)];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    public static function cents(string|int|float $value): int
    {
        return (int) round((float) $value * 100);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function amount(mixed $value, int $row): int
    {
        $text = trim((string) $value);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text) || (float) $text > 999999999999.99) {
            $this->fail("Row {$row}: amount must be a nonnegative number with at most two decimal places.");
        }

        return self::cents($text);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['excel_file' => $message]);
    }
}
