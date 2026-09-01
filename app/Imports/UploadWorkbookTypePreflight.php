<?php

namespace App\Imports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class UploadWorkbookTypePreflight implements ToArray, WithCalculatedFormulas
{
    /** @var array<int, array<int, mixed>> */
    private array $rows = [];

    /** @var 'purchase'|'sales'|'expanded'|null */
    private ?string $detectedType = null;

    private ?Carbon $periodStart = null;

    private ?Carbon $periodEnd = null;

    private ?Carbon $inferredMonth = null;

    public function array(array $rows): void
    {
        $this->rows = array_map(
            fn (array $row) => array_values(array_map(
                fn ($value) => is_string($value) ? trim($value) : $value,
                $row
            )),
            $rows
        );
    }

    /**
     * @param  \Illuminate\Http\UploadedFile|string  $file
     * @return string[]
     */
    public function check($file, string $selectedType, ?string $reportingPeriod = null): array
    {
        $this->rows = [];
        $this->detectedType = null;
        $this->periodStart = null;
        $this->periodEnd = null;
        $this->inferredMonth = null;

        Excel::import($this, $file);

        $this->detectedType = $this->detectType();
        $this->detectPeriod();

        if ($this->detectedType === null) {
            return [
                'Upload rejected. The workbook does not match the selected '
                . $this->label($selectedType) . ' template. ' . $this->templateHint($selectedType),
            ];
        }

        if ($this->detectedType !== $selectedType) {
            return [
                'Upload rejected. The selected file type is ' . $this->label($selectedType)
                . ', but the workbook appears to be a ' . $this->label($this->detectedType)
                . ' file. Please choose ' . $this->label($this->detectedType)
                . ' or upload the correct ' . $this->label($selectedType) . ' template.',
            ];
        }

        $selectedMonth = $reportingPeriod ? Carbon::parse($reportingPeriod) : null;
        $workbookMonth = $this->workbookMonth();

        if ($selectedMonth !== null && $workbookMonth !== null && ! $workbookMonth->isSameMonth($selectedMonth)) {
            return [
                'Upload rejected. The workbook period is ' . $this->periodLabel()
                . ', but the selected reporting month is ' . $selectedMonth->format('F Y') . '.',
            ];
        }

        return [];
    }

    /**
     * @return 'purchase'|'sales'|'expanded'|null
     */
    private function detectType(): ?string
    {
        $scores = [
            'purchase' => 0,
            'sales' => 0,
            'expanded' => 0,
        ];

        foreach ($this->rows as $row) {
            $headings = $this->headingMap($row);
            $text = $this->normaliseText(implode(' ', array_map(fn ($value) => (string) $value, $row)));

            if (str_contains($text, 'SALES SUMMARY BY DOCUMENT NUMBER')) {
                $scores['sales'] += 5;
            }

            $flatHeading = $this->normaliseHeading($text);

            if (isset($headings['documentno']) || isset($headings['clienttin'])
                || str_contains($flatHeading, 'documentno')
                || str_contains($flatHeading, 'clienttin')
            ) {
                $scores['sales'] += 5;
            }

            if (isset($headings['customername']) || str_contains($flatHeading, 'customername')) {
                $scores['sales'] += 2;
            }

            if (isset($headings['vendortin'], $headings['companyname'])
                || isset($headings['suppliername'], $headings['inputvat'])
                || isset($headings['suppliername'], $headings['totalpurchases'])
                || (str_contains($flatHeading, 'suppliername') && str_contains($flatHeading, 'inputvat'))
                || (str_contains($flatHeading, 'vendortin') && str_contains($flatHeading, 'totalpurchases'))
            ) {
                $scores['purchase'] += 4;
            }

            $purchaseMatches = count(array_intersect(array_keys($headings), [
                'suppliertin',
                'suppliername',
                'vendortin',
                'tinnumber',
                'purchaseimported',
                'purchaselocal',
                'capitalgoods',
                'otherthancapitalgoods',
                'taxablenetofvat',
                'inputvat',
                'totalpurchases',
                'total',
            ]));

            if ($purchaseMatches >= 3) {
                $scores['purchase'] += $purchaseMatches;
            }

            foreach ([
                'suppliertin',
                'suppliername',
                'vendortin',
                'purchaseimported',
                'purchaselocal',
                'inputvat',
                'totalpurchases',
            ] as $needle) {
                if (str_contains($flatHeading, $needle)) {
                    $scores['purchase']++;
                }
            }

            if ($this->matchesAll($headings, ExpandedWtaxImport::COLUMNS)) {
                $scores['expanded'] += 8;
            }

            $expandedSystemMatches = $this->matchesFor($headings, ExpandedWtaxImport::SYSTEM_COLUMNS);
            if ($expandedSystemMatches >= 10
                && isset($headings['suppliername'], $headings['tin'], $headings['date'])
                && $this->hasExpandedRateHeading($headings)
            ) {
                $scores['expanded'] += 8;
            }
        }

        arsort($scores);
        $topType = array_key_first($scores);
        $topScore = $scores[$topType];
        $secondScore = array_values($scores)[1] ?? 0;

        if ($topScore < 4 || $topScore === $secondScore) {
            return null;
        }

        return $topType;
    }

    private function detectPeriod(): void
    {
        foreach ($this->rows as $row) {
            $period = $this->periodFromText(implode(' ', array_map(fn ($value) => (string) $value, $row)));

            if ($period !== null) {
                [$this->periodStart, $this->periodEnd] = $period;

                return;
            }

            foreach ($row as $value) {
                $period = $this->periodFromText((string) $value);

                if ($period !== null) {
                    [$this->periodStart, $this->periodEnd] = $period;

                    return;
                }
            }
        }

        $this->inferredMonth = $this->monthFromTransactionDates();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function periodFromText(string $value): ?array
    {
        if (! str_contains(strtolower($value), 'period covered')) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $value));
        if (preg_match(
            '/period covered\s*:\s*([a-z]+\s+\d{1,2},?\s+\d{4})\s*(?:-|to)\s*([a-z]+\s+\d{1,2},?\s+\d{4})/i',
            $text,
            $matches
        )) {
            try {
                return [Carbon::parse($matches[1])->startOfDay(), Carbon::parse($matches[2])->startOfDay()];
            } catch (Throwable) {
                return null;
            }
        }

        $range = trim((string) preg_replace('/^.*?period covered\s*:\s*/i', '', $text));
        $parts = preg_split('/\s*(?:-|to)\s*/i', $range);

        if (! is_array($parts) || count($parts) < 2) {
            return null;
        }

        try {
            return [Carbon::parse($parts[0])->startOfDay(), Carbon::parse($parts[1])->startOfDay()];
        } catch (Throwable) {
            return null;
        }
    }

    private function monthFromTransactionDates(): ?Carbon
    {
        foreach ($this->rows as $index => $row) {
            $headings = $this->headingMap($row);
            $dateIndex = $this->transactionDateIndex($headings);

            if ($dateIndex === null) {
                continue;
            }

            $months = [];

            foreach (array_slice($this->rows, $index + 1) as $dataRow) {
                $date = $this->parseDate($dataRow[$dateIndex] ?? null);

                if ($date === null) {
                    continue;
                }

                $months[$date->format('Y-m')] = $date->copy()->startOfMonth();
            }

            return count($months) === 1 ? array_values($months)[0] : null;
        }

        return null;
    }

    private function transactionDateIndex(array $headings): ?int
    {
        if ($this->detectedType === 'sales') {
            return $headings['date'] ?? null;
        }

        if ($this->detectedType === 'expanded') {
            return $headings['reportingmonth'] ?? $headings['date'] ?? null;
        }

        return null;
    }

    private function workbookMonth(): ?Carbon
    {
        if ($this->periodStart !== null && $this->periodEnd !== null && $this->periodStart->isSameMonth($this->periodEnd)) {
            return $this->periodStart->copy()->startOfMonth();
        }

        return $this->inferredMonth;
    }

    private function periodLabel(): string
    {
        if ($this->periodStart !== null && $this->periodEnd !== null) {
            if ($this->periodStart->isSameMonth($this->periodEnd)) {
                return $this->periodStart->format('F Y');
            }

            return $this->periodStart->format('m/d/Y') . ' to ' . $this->periodEnd->format('m/d/Y');
        }

        return $this->inferredMonth?->format('F Y') ?? 'an unreadable period';
    }

    private function parseDate(mixed $value): ?Carbon
    {
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
     * @param  array<string, int>  $headings
     * @param  array<string, array<int, string>>  $columns
     */
    private function matchesAll(array $headings, array $columns): bool
    {
        return $this->matchesFor($headings, $columns) === count($columns);
    }

    /**
     * @param  array<string, int>  $headings
     * @param  array<string, array<int, string>>  $columns
     */
    private function matchesFor(array $headings, array $columns): int
    {
        $matches = 0;

        foreach ($columns as $label => $acceptedKeys) {
            foreach ([$label, ...$acceptedKeys] as $key) {
                if (array_key_exists($this->normaliseHeading($key), $headings)) {
                    $matches++;

                    break;
                }
            }
        }

        return $matches;
    }

    private function hasExpandedRateHeading(array $headings): bool
    {
        return isset($headings['1'], $headings['2'], $headings['5'])
            || isset($headings['10'], $headings['15']);
    }

    /**
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

    private function normaliseHeading(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace('%', 'percent', $value);
        $value = str_replace(['#'], ['no'], $value);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    private function normaliseText(string $value): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }

    private function label(string $type): string
    {
        return match ($type) {
            'purchase' => 'Purchase',
            'sales' => 'Sales',
            'expanded' => 'Expanded WTAX',
            default => $type,
        };
    }

    private function templateHint(string $type): string
    {
        return match ($type) {
            'purchase' => 'Expected Purchase columns include supplier_name, vendor_tin, input_vat, and total_purchases.',
            'sales' => 'Expected Sales columns include Document No and Customer Name, or CLIENT TIN for BIR Sales.',
            'expanded' => 'Expected Expanded WTAX columns include Reporting_Month, Vendor_TIN, ATC, income_payment, ewt_rate, and tax_amount.',
            default => 'Please upload the correct template for the selected file type.',
        };
    }
}
