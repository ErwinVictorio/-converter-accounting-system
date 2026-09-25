<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TemporaryPurchaseSupplierTotalService
{
    /**
     * Build a report in memory. The uploaded workbook is never persisted by the application.
     */
    public function build(UploadedFile $file): Spreadsheet
    {
        try {
            $reader = IOFactory::createReader('Xlsx');
            $source = $reader->load($file->getRealPath());
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'excel_file' => 'The uploaded file is not a readable XLSX workbook.',
            ]);
        }

        try {
            [$sheet, $headingRow, $supplierColumn, $amountColumn] = $this->locateSource($source);
            $summary = $this->summarize($sheet, $headingRow, $supplierColumn, $amountColumn);

            return $this->createReport(
                $summary['groups'],
                $summary['transaction_count'],
                $summary['grand_total'],
                $file->getClientOriginalName(),
                $sheet->getTitle(),
                $this->coveredPeriod($sheet, $headingRow),
            );
        } finally {
            $source->disconnectWorksheets();
            unset($source);
        }
    }

    /**
     * Prefer Sheet2 when it contains the needed headers, then fall back to the
     * first worksheet that contains both Supplier Name and Amount on one row.
     *
     * @return array{Worksheet, int, string, string}
     */
    private function locateSource(Spreadsheet $workbook): array
    {
        $sheets = $workbook->getAllSheets();
        usort($sheets, fn (Worksheet $left, Worksheet $right) => match (true) {
            $left->getTitle() === 'Sheet2' => -1,
            $right->getTitle() === 'Sheet2' => 1,
            default => $left->getParent()->getIndex($left) <=> $right->getParent()->getIndex($right),
        });

        foreach ($sheets as $sheet) {
            $coordinates = $this->findHeadings($sheet);
            if ($coordinates !== null) {
                return [$sheet, ...$coordinates];
            }
        }

        throw ValidationException::withMessages([
            'excel_file' => 'No worksheet contains Supplier Name and Amount headings on the same row.',
        ]);
    }

    /** @return array{int, string, string}|null */
    private function findHeadings(Worksheet $sheet): ?array
    {
        $lastRow = min(25, $sheet->getHighestDataRow());
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row <= $lastRow; $row++) {
            $supplierColumn = null;
            $amountColumn = null;

            for ($column = 1; $column <= $lastColumn; $column++) {
                $heading = $this->normalizeHeading($sheet->getCell([$column, $row])->getFormattedValue());

                if ($heading === 'supplier name') {
                    $supplierColumn = Coordinate::stringFromColumnIndex($column);
                } elseif ($heading === 'amount') {
                    $amountColumn = Coordinate::stringFromColumnIndex($column);
                }
            }

            if ($supplierColumn !== null && $amountColumn !== null) {
                return [$row, $supplierColumn, $amountColumn];
            }
        }

        return null;
    }

    /**
     * @return array{groups: array<int, array{name: string, count: int, total: BigDecimal}>, transaction_count: int, grand_total: BigDecimal}
     */
    private function summarize(Worksheet $sheet, int $headingRow, string $supplierColumn, string $amountColumn): array
    {
        /** @var array<string, array{name: string, count: int, total: BigDecimal}> $groups */
        $groups = [];
        $sourceTotal = BigDecimal::zero();
        $transactionCount = 0;

        for ($row = $headingRow + 1; $row <= $sheet->getHighestDataRow(); $row++) {
            $supplierValue = trim((string) $sheet->getCell($supplierColumn.$row)->getFormattedValue());
            $rawAmount = $sheet->getCell($amountColumn.$row)->getValue();

            if ($supplierValue === '' && ($rawAmount === null || trim((string) $rawAmount) === '')) {
                continue;
            }

            $supplierName = $this->cleanSupplierName($supplierValue);
            if ($supplierName === '') {
                if ($this->isTotalRow($sheet, $row)) {
                    continue;
                }

                throw ValidationException::withMessages([
                    'excel_file' => "{$sheet->getTitle()} row {$row}: Supplier Name is required.",
                ]);
            }

            $amount = $this->amount($sheet, $row, $amountColumn);
            $key = mb_strtolower($supplierName, 'UTF-8');

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $supplierName,
                    'count' => 0,
                    'total' => BigDecimal::zero(),
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['total'] = $groups[$key]['total']->plus($amount);
            $sourceTotal = $sourceTotal->plus($amount);
            $transactionCount++;
        }

        if ($transactionCount === 0) {
            throw ValidationException::withMessages([
                'excel_file' => "{$sheet->getTitle()} does not contain any valid purchase rows after the detected headings.",
            ]);
        }

        $groups = array_values($groups);
        usort($groups, fn (array $left, array $right) => strcasecmp($left['name'], $right['name']));

        $groupedTotal = BigDecimal::zero();
        foreach ($groups as &$group) {
            $group['total'] = $group['total']->toScale(2, RoundingMode::HALF_UP);
            $groupedTotal = $groupedTotal->plus($group['total']);
        }
        unset($group);

        $grandTotal = $sourceTotal->toScale(2, RoundingMode::HALF_UP);
        if (! $groupedTotal->isEqualTo($grandTotal)) {
            throw ValidationException::withMessages([
                'excel_file' => "{$sheet->getTitle()} totals could not be reconciled after grouping. No report was generated.",
            ]);
        }

        return [
            'groups' => $groups,
            'transaction_count' => $transactionCount,
            'grand_total' => $grandTotal,
        ];
    }

    private function amount(Worksheet $sheet, int $row, string $amountColumn): BigDecimal
    {
        $cell = $sheet->getCell($amountColumn.$row);

        try {
            $value = $cell->getDataType() === DataType::TYPE_FORMULA
                ? $cell->getCalculatedValue()
                : $cell->getValue();
        } catch (\Throwable) {
            $value = null;
        }

        if (is_string($value) && str_starts_with(trim($value), '#')) {
            $value = null;
        }

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric(trim($value)))) {
            throw ValidationException::withMessages([
                'excel_file' => "{$sheet->getTitle()} row {$row}: Amount must be numeric.",
            ]);
        }

        try {
            return BigDecimal::of((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'excel_file' => "{$sheet->getTitle()} row {$row}: Amount must be numeric.",
            ]);
        }
    }

    /**
     * @param  array<int, array{name: string, count: int, total: BigDecimal}>  $groups
     */
    private function createReport(
        array $groups,
        int $transactionCount,
        BigDecimal $grandTotal,
        string $sourceFilename,
        string $sourceWorksheet,
        string $coveredPeriod,
    ): Spreadsheet {
        $report = new Spreadsheet;
        $sheet = $report->getActiveSheet();
        $sheet->setTitle('Supplier Totals');

        $sheet->setCellValue('A1', 'PURCHASE SUPPLIER TOTALS');
        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A2', 'Source File');
        $sheet->setCellValue('B2', $sourceFilename);
        $sheet->mergeCells('B2:D2');
        $sheet->setCellValue('A3', 'Source Worksheet');
        $sheet->setCellValue('B3', $sourceWorksheet);
        $sheet->mergeCells('B3:D3');
        $sheet->setCellValue('A4', 'Period Covered');
        $sheet->setCellValue('B4', $coveredPeriod !== '' ? $coveredPeriod : 'Not provided');
        $sheet->mergeCells('B4:D4');
        $sheet->setCellValue('A5', 'Generated At');
        $sheet->setCellValue('B5', now()->format('F j, Y g:i A'));
        $sheet->mergeCells('B5:D5');

        $headingRow = 7;
        $sheet->fromArray(['No', 'Supplier Name', 'Transaction Count', 'Total Amount'], null, 'A'.$headingRow);

        $outputRow = $headingRow + 1;
        foreach ($groups as $index => $group) {
            $sheet->setCellValue('A'.$outputRow, $index + 1);
            $sheet->setCellValue('B'.$outputRow, $group['name']);
            $sheet->setCellValue('C'.$outputRow, $group['count']);
            $sheet->setCellValueExplicit('D'.$outputRow, (string) $group['total'], DataType::TYPE_NUMERIC);
            $outputRow++;
        }

        $grandTotalRow = $outputRow;
        $sheet->mergeCells("A{$grandTotalRow}:B{$grandTotalRow}");
        $sheet->setCellValue('A'.$grandTotalRow, 'GRAND TOTAL');
        $sheet->setCellValue('C'.$grandTotalRow, $transactionCount);
        $sheet->setCellValueExplicit('D'.$grandTotalRow, (string) $grandTotal, DataType::TYPE_NUMERIC);

        $sheet->getStyle('A1:D1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0344A4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A{$headingRow}:D{$headingRow}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '047857']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A{$grandTotalRow}:D{$grandTotalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D1FAE5']],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet->getStyle("A{$headingRow}:D{$grandTotalRow}")
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('FFD1D5DB'));
        $sheet->getStyle("D8:D{$grandTotalRow}")->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');
        $sheet->getStyle("A8:A{$grandTotalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C8:C{$grandTotalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D8:D{$grandTotalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A2:A5')->getFont()->setBold(true);

        $sheet->setAutoFilter("A{$headingRow}:D".($grandTotalRow - 1));
        $sheet->freezePane('A8');
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(48);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(22);

        return $report;
    }

    private function cleanSupplierName(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    private function normalizeHeading(mixed $value): string
    {
        return mb_strtolower($this->cleanSupplierName($value), 'UTF-8');
    }

    private function isTotalRow(Worksheet $sheet, int $row): bool
    {
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($column = 1; $column <= $lastColumn; $column++) {
            $value = trim((string) $sheet->getCell([$column, $row])->getFormattedValue());
            if (preg_match('/^(grand\s+)?total\s*:?$/iu', $value)) {
                return true;
            }
        }

        return false;
    }

    private function coveredPeriod(Worksheet $sheet, int $headingRow): string
    {
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row < $headingRow; $row++) {
            for ($column = 1; $column <= $lastColumn; $column++) {
                $value = trim((string) $sheet->getCell([$column, $row])->getFormattedValue());
                if (str_starts_with(mb_strtolower($value, 'UTF-8'), 'period covered')) {
                    return $value;
                }
            }
        }

        return '';
    }
}
