<?php

namespace App\Imports;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PurchaseDmReader
{
    public const TYPES = ['local' => 'PURCHASES LOCAL', 'imported' => 'PURCHASES IMPORTED', 'services' => 'SERVICES', 'others' => 'OTHERS'];

    public function read(string $path, string $month, string $type): array
    {
        $book = IOFactory::load($path);
        try {
            if ($book->getSheetCount() !== 1) {
                $this->fail('Use one ACCOUNT DETAILS worksheet per upload.');
            }
            $sheet = $book->getSheet(0);
            if ($sheet->getHighestDataRow() > 10000) {
                $this->fail('The workbook cannot exceed 10,000 rows.');
            }
            // Never calculate formulas or trust cached formula results for deductions.
            $rows = $sheet->rangeToArray('A1:G'.$sheet->getHighestDataRow(), null, false, false);
            if (trim((string) ($rows[0][0] ?? '')) !== 'ACCOUNT DETAILS'
                || array_map(fn ($v) => trim((string) $v), $rows[2] ?? []) !== ['Date', 'Reference', 'Particulars', 'Other Info', 'Debit', 'Credit', 'Balance']) {
                $this->fail('Expected ACCOUNT DETAILS and Date, Reference, Particulars, Other Info, Debit, Credit, Balance headings on row 3.');
            }
            if (! preg_match('/^Period Covered:\s*(.+?)\s+-\s+(.+)$/i', trim((string) ($rows[1][0] ?? '')), $period)) {
                $this->fail('Missing covered period on row 2.');
            }
            $dates = [];
            foreach ([$period[1], $period[2]] as $date) {
                $parsed = DateTimeImmutable::createFromFormat('!F j, Y', trim($date));
                if (! $parsed || $parsed->format('F j, Y') !== trim($date) || $parsed->format('Y-m') !== $month) {
                    $this->fail('Workbook period must match the selected month.');
                }
                $dates[] = $parsed;
            }
            if ($dates[0] > $dates[1]) {
                $this->fail('Workbook period is reversed.');
            }
            $items = [];
            $excluded = [];
            $seen = [];
            $section = false;
            foreach (array_slice($rows, 3, null, true) as $index => $row) {
                $number = $index + 1;
                $first = trim((string) $row[0]);
                $reference = trim((string) $row[1]);
                if (str_contains(strtoupper($first), 'VAT INPUT TAX')) {
                    if ($section || ! preg_match('/VAT INPUT TAX\s*-\s*'.preg_quote(self::TYPES[$type], '/').'\s*$/i', $first)) {
                        $this->fail("Row {$number}: use one account section matching the selected Purchase Type.");
                    }
                    $section = true;

                    continue;
                }
                if ($reference === '') {
                    if (preg_match('/^(TOTAL|GRAND TOTAL|SUBTOTAL)\s*:?$/i', $first) || count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    $this->fail("Row {$number}: missing reference or unrecognized account line.");
                }
                if (! $section) {
                    $this->fail('A matching VAT INPUT TAX account heading is required before transactions.');
                }
                if (! preg_match('/^DM(?:\b|\()/i', $reference)) {
                    $excluded[] = ['row' => $number, 'reference' => $reference, 'supplier' => trim((string) $row[2]), 'status' => 'non_dm', 'reason' => 'Non-DM transaction skipped.'];

                    continue;
                }
                $normalized = preg_replace('/\s+/', '', strtoupper($reference));
                if (isset($seen[$normalized])) {
                    $this->fail("Row {$number}: duplicate DM reference {$reference}.");
                }
                $seen[$normalized] = true;
                $reason = null;
                if (! preg_match('/^DM\s*(?:\([A-Z ]+\))?\s*#\s*\d+$/i', $reference) || strlen($reference) > 200) {
                    $reason = 'Malformed DM reference.';
                }
                $date = is_numeric($row[0]) ? Date::excelToDateTimeObject((float) $row[0])->format('m/d/Y') : $first;
                $parsed = DateTimeImmutable::createFromFormat('!m/d/Y', $date);
                if (! $parsed || $parsed->format('m/d/Y') !== $date || $parsed->format('Y-m') !== $month) {
                    $reason = 'DM date must be valid and inside the selected month.';
                }
                $supplier = trim((string) $row[2]);
                if ($supplier === '' || str_starts_with($supplier, '=')) {
                    $reason = 'A literal supplier name is required.';
                }
                $credit = $this->amount($row[5]);
                if ($credit === null || $credit <= 0) {
                    $reason = 'Credit must be a positive literal number with at most two decimal places.';
                }
                if ($row[4] !== null && $row[4] !== '' && $this->amount($row[4]) !== 0) {
                    $reason = 'DM cannot contain both Debit and Credit.';
                }
                $items[] = ['row' => $number, 'sheet' => $sheet->getTitle(), 'reference' => $reference, 'normalized_reference' => $normalized,
                    'date' => $parsed ? $parsed->format('Y-m-d') : null, 'supplier' => $supplier, 'credit_cents' => $credit, 'invalid_reason' => $reason];
            }
            if (! $section) {
                $this->fail('A matching VAT INPUT TAX account heading is required.');
            }

            return ['items' => $items, 'excluded' => $excluded, 'excluded_count' => count($excluded)];
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function amount(mixed $value): ?int
    {
        $text = trim((string) $value);
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $text)) {
            return null;
        }
        $parts = explode('.', $text);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['excel_file' => $message]);
    }
}
