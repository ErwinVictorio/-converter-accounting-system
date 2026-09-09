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
