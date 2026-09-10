<?php

namespace Tests\Unit;

use App\Models\ImportationEntry;
use App\Models\VatInput;
use App\Services\BIR\DatAttachmentPdfRenderer;
use App\Services\BIR\DatAttachmentReportBuilder;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatAttachmentReportBuilderTest extends TestCase
{
    public static function reports(): array
    {
        return [
            'purchase' => ['purchase', 13, 4, ['1060.00', '10.00', '50.00', '1000.00', '100.00', '200.00', '700.00', '120.00', '1120.00']],
            'sales' => ['sales', 10, 4, ['1060.00', '10.00', '50.00', '1000.00', '120.00', '1120.00']],
            'importation' => ['importation', 13, 5, ['1060.00', '1000.00', '60.00', '1000.00', '60.00', '120.00']],
        ];
    }

    public function test_expanded_report_uses_sawt_attachment_shape_without_changing_dat_fields(): void
    {
        $report = app(DatAttachmentReportBuilder::class)->build(
            'expanded',
            collect([
                [
                    'payee_type' => 'company',
                    'payee_tin' => '007-086-184',
                    'payee_branch_code' => '0000',
                    'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC',
                    'last_name' => null,
                    'first_name' => null,
                    'middle_name' => null,
                    'atc_code' => 'WC158',
                    'tax_rate' => 1.00,
                    'income_payment' => 3682716.00,
                    'tax_withheld' => 36827.16,
                ],
                [
                    'payee_type' => 'individual',
                    'payee_tin' => '220052738',
                    'payee_branch_code' => '0000',
                    'company_name' => 'SHOULD NOT PRINT',
                    'last_name' => 'BANSIL',
                    'first_name' => 'ANNIE',
                    'middle_name' => '',
                    'atc_code' => 'WI516',
                    'tax_rate' => 10.00,
                    'income_payment' => 5865.60,
                    'tax_withheld' => 586.56,
                ],
            ]),
            ['tin' => '008791976', 'branch_code' => '0000', 'name' => 'FORTRESS STEEL INC.'],
            Carbon::parse('2026-05-31')
        );

        $this->assertSame('BIR FORM 1702Q', $report['title']);
        $this->assertSame('SUMMARY ALPHALIST OF WITHHOLDING TAXES (SAWT)', $report['subtitle']);
        $this->assertSame('FOR THE MONTH OF MAY, 2026', $report['period_label']);
        $this->assertSame("PAYEE'S NAME", $report['name_label']);
        $this->assertNull($report['address_label']);
        $this->assertFalse($report['show_trade_name']);
        $this->assertFalse($report['show_taxable_month']);
        $this->assertSame('008-791-976-0000', $report['company']['tin']);

        $this->assertSame([
            'Seq No',
            'Taxpayer Identification Number',
            'Corporation Registered Name',
            'Individual Name',
            'ATC Code',
            'Nature of Payment',
            'Amount of Income Payment',
            'Tax Rate',
            'Amount of Tax Withheld',
        ], $report['columns']);

        $this->assertSame('007-086-184-0000', $report['rows'][0][1]);
        $this->assertSame('ACERSTEEL INDUSTRIAL SALES INC', $report['rows'][0][2]);
        $this->assertSame('', $report['rows'][0][3]);
        $this->assertStringStartsWith('Income payment made by top withholding agents', $report['rows'][0][5]);
        $this->assertSame('1', $report['rows'][0][7]);
        $this->assertSame('220-052-738-0000', $report['rows'][1][1]);
        $this->assertSame('', $report['rows'][1][2]);
        $this->assertSame('BANSIL ANNIE', $report['rows'][1][3]);
        $this->assertSame('10', $report['rows'][1][7]);
        $this->assertSame(['Grand Total :', '', '', '', '', '', '3688581.60', '', '37413.72'], $report['totals']);

        $render = new \ReflectionMethod(DatAttachmentPdfRenderer::class, 'renderFallbackPdf');
        $pdf = $render->invoke(app(DatAttachmentPdfRenderer::class), $report);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString("PAYEE'S NAME: FORTRESS STEEL INC.", $pdf);
        $this->assertStringContainsString('FOR THE MONTH OF MAY, 2026', $pdf);
        $this->assertStringNotContainsString("OWNER'S TRADE NAME", $pdf);
        $this->assertStringNotContainsString("OWNER'S ADDRESS", $pdf);
        $this->assertStringNotContainsString('TAXABLE MONTH:', $pdf);
    }

    #[DataProvider('reports')]
    public function test_period_is_header_metadata_and_remaining_cells_and_totals_align(
        string $type, int $columnCount, int $amountOffset, array $amounts
    ): void {
        $record = match ($type) {
            'purchase' => new VatInput([
                'tin_number' => '111222333', 'vendor_type' => 'company', 'company_name' => 'SUPPLIER',
                'address1' => 'ADDRESS', 'exempt' => 10, 'zero_rated' => 50, 'services' => 100,
                'capital_goods' => 200, 'other_than_capital_goods' => 700, 'input_vat' => 120,
            ]),
            'sales' => [
                'customer_tin' => '111222333', 'customer_type' => 'company', 'company_name' => 'CUSTOMER',
                'customer_name' => 'SAVED CUSTOMER NAME',
                'address1' => 'ADDRESS', 'exempt_sales' => 10, 'zero_rated_sales' => 50,
                'taxable_sales' => 1000, 'output_vat' => 120,
            ],
            'importation' => new ImportationEntry([
                'import_entry_no' => 'C2051', 'assessment_date' => '2026-07-14', 'supplier' => 'SUPPLIER',
                'importation_date' => '2026-06-10', 'country' => 'CHINA', 'total_landed_cost' => 1060,
                'dutiable_value' => 1000, 'charges' => 60, 'taxable_goods' => 1000, 'exempt' => 60,
                'vat_payable' => 120, 'or_number' => 'OR123', 'payment_date' => '2026-07-15',
            ]),
        };
        $report = app(DatAttachmentReportBuilder::class)->build(
            $type, collect([$record, $record]), ['address1' => 'OWNER ADDRESS'], Carbon::parse('2026-07-01')
        );

        $this->assertSame('07/31/2026', $report['period']);
        $this->assertCount($columnCount, $report['columns']);
        $this->assertNotContains('Taxable Month', $report['columns']);
        foreach ($report['rows'] as $row) {
            $this->assertCount($columnCount, $row);
            $this->assertNotContains('07/31/2026', $row);
            $this->assertSame($type === 'importation' ? 'C2051' : '111-222-333', $row[0]);
            $this->assertSame($amounts, array_slice($row, $amountOffset, count($amounts)));
            if ($type === 'sales') {
                $this->assertSame('CUSTOMER', $row[1]);
                $this->assertSame('SAVED CUSTOMER NAME', $row[2]);
            }
        }
        $this->assertCount($columnCount, $report['totals']);
        $this->assertSame('Grand Total :', $report['totals'][$amountOffset - 1]);
        $this->assertSame(array_map(fn ($amount) => number_format((float) $amount * 2, 2, '.', ''), $amounts),
            array_slice($report['totals'], $amountOffset, count($amounts)));

        // Exercise fallback directly, independently of Node availability.
        $render = new \ReflectionMethod(DatAttachmentPdfRenderer::class, 'renderFallbackPdf');
        $pdf = $render->invoke(app(DatAttachmentPdfRenderer::class), $report);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(1, substr_count($pdf, 'TAXABLE MONTH: 07/31/2026'));
        $this->assertStringNotContainsString('Taxable Month', $pdf);
        $this->assertLessThan(strpos($pdf, 'TAXABLE MONTH:'), strpos($pdf, "OWNER'S ADDRESS:"));
    }
}
