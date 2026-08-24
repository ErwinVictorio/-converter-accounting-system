<?php

namespace Tests\Feature;

use App\Models\ExpandedWtaxEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The consolidation rule on its own: rows sharing Reporting Month + TIN + ATC +
 * EWT Rate become one line, and any difference in those four keeps them apart.
 *
 * ExpandedWtaxEntry::consolidate() is the single rule the records list, the
 * Generate DAT screen, the DAT download and the dashboard all read through, so it
 * is pinned here in isolation before any of those callers are involved. The
 * screens that consume it are covered in ExpandedWtaxDatFileTest and
 * ExpandedWtaxBirFormatImportTest.
 *
 * The amounts in the fixture are the real duplicate from the filed reference file
 * Docs/Expanded/0087919760000123120251604E.dat -- PRUDENTIAL GUARANTEE appears
 * twice under WC160 at 2%, and merging the two is what takes that file from 59
 * detail lines to 58.
 */
class ExpandedWtaxConsolidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function entry(array $overrides = []): ExpandedWtaxEntry
    {
        return ExpandedWtaxEntry::create(array_merge([
            'reporting_period' => '2025-12-31',
            'payee_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'payee_type' => 'company',
            'payee_tin' => '000491813',
            'payee_branch_code' => '0000',
            'company_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC',
            'last_name' => null,
            'first_name' => null,
            'middle_name' => null,
            'atc_code' => 'WC160',
            'tax_rate' => 2.00,
            'income_payment' => 219023.50,
            'tax_withheld' => 4380.47,
        ], $overrides));
    }

    private function consolidated(): \Illuminate\Support\Collection
    {
        return ExpandedWtaxEntry::consolidate(ExpandedWtaxEntry::orderBy('id')->get());
    }

    public function test_the_same_month_tin_atc_and_rate_merge(): void
    {
        $this->entry();
        $this->entry(['income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        $this->assertSame(221012.00, $rows[0]['income_payment']);
        $this->assertSame(4420.24, $rows[0]['tax_withheld']);
        $this->assertSame(2, $rows[0]['merged_rows']);

        // The merged row still satisfies the template's own formula exactly:
        // ROUND(221012 x 2 / 100, 2) = 4420.24. Nothing was re-derived to get there.
        $this->assertSame(4420.24, round(221012.00 * 2 / 100, 2));
    }

    public function test_the_same_tin_with_a_different_atc_does_not_merge(): void
    {
        $this->entry();
        $this->entry(['atc_code' => 'WC158', 'tax_rate' => 1.00, 'tax_withheld' => 2190.24]);

        $this->assertCount(2, $this->consolidated());
    }

    public function test_the_same_tin_with_a_different_rate_does_not_merge(): void
    {
        $this->entry();
        // Same ATC, different rate. The pair is unfilable -- the validator requires
        // the ATC's configured rate -- but the grouping must still keep them apart,
        // because summing across rates would misstate both lines.
        $this->entry(['tax_rate' => 5.00, 'tax_withheld' => 10951.18]);

        $rows = $this->consolidated();

        $this->assertCount(2, $rows);
        $this->assertSame(['2.00', '5.00'], [$rows[0]['tax_rate'], $rows[1]['tax_rate']]);
    }

    public function test_the_same_company_name_with_a_different_tin_does_not_merge(): void
    {
        $this->entry();
        $this->entry(['payee_tin' => '009999999']);

        $rows = $this->consolidated();

        $this->assertCount(2, $rows);
        $this->assertSame(['000491813', '009999999'], [$rows[0]['payee_tin'], $rows[1]['payee_tin']]);
    }

    public function test_a_different_reporting_month_does_not_merge(): void
    {
        $this->entry();
        $this->entry(['reporting_period' => '2025-11-30']);

        $rows = $this->consolidated();

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['2025-12-31', '2025-11-30'],
            [$rows[0]['reporting_period'], $rows[1]['reporting_period']]
        );
    }

    public function test_the_consolidated_income_payment_total_is_correct(): void
    {
        $this->entry(['income_payment' => 219023.50, 'tax_withheld' => 4380.47]);
        $this->entry(['income_payment' => 1988.50, 'tax_withheld' => 39.77]);
        $this->entry(['income_payment' => 0.55, 'tax_withheld' => 0.01]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        // 219023.50 + 1988.50 + 0.55, to the centavo and free of float noise.
        $this->assertSame(221012.55, $rows[0]['income_payment']);
        $this->assertSame(3, $rows[0]['merged_rows']);
    }

    public function test_the_consolidated_tax_amount_total_is_correct(): void
    {
        $this->entry(['tax_withheld' => 4380.47]);
        $this->entry(['tax_withheld' => 39.77]);
        $this->entry(['tax_withheld' => 0.01]);

        $this->assertSame(4420.25, $this->consolidated()[0]['tax_withheld']);
    }

    public function test_a_negative_reversal_is_summed_with_its_sign(): void
    {
        $this->entry(['income_payment' => 219023.50, 'tax_withheld' => 4380.47]);
        $this->entry(['income_payment' => -19023.50, 'tax_withheld' => -380.47]);

        $rows = $this->consolidated();

        $this->assertSame(200000.00, $rows[0]['income_payment']);
        $this->assertSame(4000.00, $rows[0]['tax_withheld']);
    }

    public function test_formatting_differences_in_the_key_do_not_block_a_merge(): void
    {
        $this->entry();
        // The same payee, keyed by a dashed TIN, a lowercase ATC and a mid-month
        // reporting date. All three are the same four values once normalised.
        $this->entry([
            'reporting_period' => '2025-12-01',
            'payee_tin' => '000-491-813-000',
            'atc_code' => 'wc160 ',
            'income_payment' => 1988.50,
            'tax_withheld' => 39.77,
        ]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        $this->assertSame(221012.00, $rows[0]['income_payment']);
    }

    public function test_the_first_row_of_a_group_supplies_the_unsummed_fields(): void
    {
        $this->entry(['payee_branch_code' => '0000']);
        // Same key, spelled differently and filed against another branch. One of
        // the two spellings has to reach the DAT; it is the first.
        $this->entry([
            'payee_name' => 'PRUDENTIAL GUARANTEE ASSURANCE',
            'company_name' => 'PRUDENTIAL GUARANTEE ASSURANCE',
            'payee_branch_code' => '0001',
            'income_payment' => 1988.50,
            'tax_withheld' => 39.77,
        ]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        $this->assertSame('PRUDENTIAL GUARANTEE AND ASSURANCE INC', $rows[0]['company_name']);
        $this->assertSame('0000', $rows[0]['payee_branch_code']);
    }

    public function test_group_order_follows_the_order_rows_arrive_in(): void
    {
        // Fed in payee order, the way the DAT download sorts its query.
        $this->entry(['payee_name' => 'ACERSTEEL', 'company_name' => 'ACERSTEEL', 'payee_tin' => '007086184']);
        $this->entry(['payee_name' => 'PRUDENTIAL', 'company_name' => 'PRUDENTIAL']);
        $this->entry(['payee_name' => 'PRUDENTIAL', 'company_name' => 'PRUDENTIAL', 'income_payment' => 1988.50, 'tax_withheld' => 39.77]);
        $this->entry(['payee_name' => 'ZENITH', 'company_name' => 'ZENITH', 'payee_tin' => '302331355']);

        $rows = $this->consolidated();

        $this->assertSame(
            ['ACERSTEEL', 'PRUDENTIAL', 'ZENITH'],
            $rows->pluck('company_name')->all()
        );
    }

    public function test_a_row_with_no_atc_never_merges_into_a_real_code(): void
    {
        $this->entry();
        $this->entry(['atc_code' => null]);

        $rows = $this->consolidated();

        $this->assertCount(2, $rows);
        $this->assertNull($rows[1]['atc_code']);
    }

    public function test_the_dropped_columns_are_gone_from_the_table(): void
    {
        // The BIR Excel format carries no transaction date and no Reference/PV/SI,
        // so neither is stored any more.
        foreach (['transaction_date', 'source_no', 'reference_no', 'source_row'] as $column) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('expanded_wtax_entries', $column),
                "expanded_wtax_entries should no longer have a {$column} column."
            );
        }
    }
}
