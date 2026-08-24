<?php

/*
 * Builder for EXPANDED_WTAX_BIR_FORMAT_SAMPLE.xlsx, the BIR-format fixture the
 * Expanded WTAX import tests upload. Run from the project root:
 *
 *   php Docs/Expanded/build-bir-format-sample.php
 *
 * Kept alongside the workbook it writes so the committed fixture stays
 * reproducible: the file is a binary, so a reviewer cannot read a diff of it, and
 * this script is the record of what it contains and why.
 *
 * Written as a script rather than assembled inside the test for two reasons: the
 * file in Docs/ is then something Accounting can open as an example of the layout,
 * and column K can be a genuine =ROUND(I*J/100,2) formula the way the real BIR
 * template has it. That formula is the point -- it proves the importer resolves
 * calculated cells instead of scraping the formula text.
 *
 * The seven rows cover: two ATCs for one payee, a mergeable duplicate pair, an
 * individual payee, a negative reversal, and a second payee under the same ATC.
 * Every row is filable as written, and the seven consolidate to six lines.
 */

require __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$headings = [
    'Reporting_Month', 'Vendor_TIN', 'branchCode', 'companyName', 'surName',
    'firstName', 'middleName', 'ATC', 'income_payment', 'ewt_rate', 'tax_amount',
];

// companyName | surName | firstName | middleName | TIN | ATC | income | rate
$rows = [
    ['ACERSTEEL INDUSTRIAL SALES INC', '', '', '', '007086184', 'WC158', 3682716.00, 1],
    ['ACERSTEEL INDUSTRIAL SALES INC', '', '', '', '007086184', 'WC160', 100000.00, 2],
    ['PRUDENTIAL GUARANTEE AND ASSURANCE INC', '', '', '', '000491813', 'WC160', 219023.50, 2],
    ['PRUDENTIAL GUARANTEE AND ASSURANCE INC', '', '', '', '000491813', 'WC160', 1988.50, 2],
    ['', 'BANSIL', 'JUAN', 'CRUZ', '220052738', 'WI010', 50000.00, 5],
    ['ACCUTECH INDUSTRIAL SUPPLY', '', '', '', '000698073', 'WC160', -51600.00, 2],
    ['ACERSTEEL INDUSTRIAL SALES INC', '', '', '', '009999999', 'WC158', 200000.00, 1],
];

$spreadsheet = new Spreadsheet;
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Sheet1');

foreach ($headings as $index => $heading) {
    $sheet->setCellValue([$index + 1, 1], $heading);
}

$serial = ExcelDate::PHPToExcel(new DateTime('2025-12-31'));

foreach ($rows as $index => $row) {
    [$company, $surname, $first, $middle, $tin, $atc, $income, $rate] = $row;
    $line = $index + 2;

    $sheet->setCellValue([1, $line], $serial);
    $sheet->getStyle([1, $line])->getNumberFormat()->setFormatCode('mm/dd/yyyy');

    // TIN and branch code go in as text: 000491813 must keep its leading zeros.
    $sheet->setCellValueExplicit([2, $line], $tin, DataType::TYPE_STRING);
    $sheet->setCellValueExplicit([3, $line], '0', DataType::TYPE_STRING);

    $sheet->setCellValue([4, $line], $company);
    $sheet->setCellValue([5, $line], $surname);
    $sheet->setCellValue([6, $line], $first);
    $sheet->setCellValue([7, $line], $middle);
    $sheet->setCellValue([8, $line], $atc);
    $sheet->setCellValue([9, $line], $income);
    $sheet->setCellValue([10, $line], $rate);

    // The template's own formula, left as a formula: the importer has to resolve it.
    $sheet->setCellValue([11, $line], "=ROUND(I{$line}*J{$line}/100,2)");

    foreach ([9, 11] as $column) {
        $sheet->getStyle([$column, $line])->getNumberFormat()->setFormatCode('0.00');
    }
}

foreach (range('A', 'K') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$target = __DIR__ . '/EXPANDED_WTAX_BIR_FORMAT_SAMPLE.xlsx';

(new Xlsx($spreadsheet))->setPreCalculateFormulas(true)->save($target);

echo 'Wrote ' . $target . PHP_EOL;
