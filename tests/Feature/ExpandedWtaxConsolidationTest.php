<?php

namespace Tests\Feature;

use App\Models\ExpandedWtaxEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The consolidation rule on its own: rows sharing Reporting Month + withholding
 * agent + payee identity + ATC + EWT Rate become one line, and any difference in
 * those keeps them apart.
 *
 * ExpandedWtaxEntry::consolidate() is the single rule the records list, the
 * Generate DAT screen, the DAT download and the dashboard all read through, so it
 * is pinned here in isolation before any of those callers are involved. The
 * screens that consume it are covered in ExpandedWtaxDatFileTest and
 * ExpandedWtaxBirFormatImportTest.
 *
 * Identity is the payee's name, not the payee's TIN. One payee billed at one rate
 * is one filing line even when the uploaded rows disagree about the TIN, and the
 * same payee at two rates stays two lines.
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

    /**
     * An individual payee, whose identity is the three name columns rather than a
     * company name.
     */
    private function individual(array $overrides = []): ExpandedWtaxEntry
    {
        return $this->entry(array_merge([
            'payee_name' => 'BANSIL ANNIE',
            'payee_type' => 'individual',
            'payee_tin' => '220052738',
            'company_name' => null,
            'last_name' => 'BANSIL',
            'first_name' => 'ANNIE',
            'middle_name' => null,
            'atc_code' => 'WI516',
            'tax_rate' => 10.00,
            'income_payment' => 5865.60,
            'tax_withheld' => 586.56,
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

    public function test_the_same_company_name_with_a_different_tin_still_merges(): void
    {
        $this->entry();
        $this->entry(['payee_tin' => '009999999', 'income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $rows = $this->consolidated();

        // One payee at one rate is one filing line. A detail line carries a single
        // TIN, so the first one wins and the disagreement is recorded rather than
        // resolved.
        $this->assertCount(1, $rows);
        $this->assertSame('000491813', $rows[0]['payee_tin']);
        $this->assertSame(221012.00, $rows[0]['income_payment']);
        $this->assertSame(2, $rows[0]['merged_rows']);
        $this->assertTrue($rows[0]['has_multiple_payee_tins']);
        $this->assertSame(['000491813', '009999999'], $rows[0]['distinct_payee_tins']);
    }

    public function test_one_tin_across_the_group_is_not_flagged(): void
    {
        $this->entry();
        // The dashed spelling of the same TIN, which normalises to the same nine
        // digits, so there is nothing to flag.
        $this->entry(['payee_tin' => '000-491-813-000', 'income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['has_multiple_payee_tins']);
        $this->assertSame(['000491813'], $rows[0]['distinct_payee_tins']);
        $this->assertFalse($rows[0]['has_multiple_payee_branch_codes']);
        $this->assertSame(['0000'], $rows[0]['distinct_payee_branch_codes']);
    }

    public function test_the_first_filable_tin_in_the_group_reaches_the_dat(): void
    {
        // The gap the sample workbook actually has: a named payee with no TIN,
        // followed by the same payee with the TIN filled in.
        $this->entry(['payee_tin' => '', 'payee_branch_code' => '']);
        $this->entry(['payee_tin' => '000491813', 'payee_branch_code' => '0002', 'income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        // Not the first row's blank, because a blank cannot be filed. The branch
        // code comes from the row that supplied the TIN.
        $this->assertSame('000491813', $rows[0]['payee_tin']);
        $this->assertSame('0002', $rows[0]['payee_branch_code']);
    }

    public function test_a_group_with_no_filable_tin_keeps_its_unfilable_value(): void
    {
        // Nothing is invented to paper over the gap: the value stays as uploaded so
        // BirExpandedWtaxRowValidator still names the payee and blocks the file.
        $this->entry(['payee_tin' => '000000000']);
        $this->entry(['payee_tin' => '00049', 'income_payment' => 1988.50, 'tax_withheld' => 39.77]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        $this->assertSame('000000000', $rows[0]['payee_tin']);
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
        // reporting date. All three are the same key values once normalised.
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
        // Same payee and rate, filed against another branch. One of the two branch
        // codes has to reach the DAT; it is the one beside the TIN that got used.
        $this->entry([
            'payee_branch_code' => '0001',
            'income_payment' => 1988.50,
            'tax_withheld' => 39.77,
        ]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        $this->assertSame('PRUDENTIAL GUARANTEE AND ASSURANCE INC', $rows[0]['company_name']);
        $this->assertSame('0000', $rows[0]['payee_branch_code']);
        $this->assertTrue($rows[0]['has_multiple_payee_branch_codes']);
        $this->assertSame(['0000', '0001'], $rows[0]['distinct_payee_branch_codes']);
    }

    public function test_two_genuinely_different_names_under_one_tin_stay_apart(): void
    {
        $this->entry();
        // Same TIN, but a different payee as far as the schedule is concerned. The
        // old TIN-keyed rule folded these together and lost the second name.
        $this->entry([
            'payee_name' => 'PRUDENTIAL LIFE INSURANCE',
            'company_name' => 'PRUDENTIAL LIFE INSURANCE',
            'income_payment' => 1988.50,
            'tax_withheld' => 39.77,
        ]);

        $rows = $this->consolidated();

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['PRUDENTIAL GUARANTEE AND ASSURANCE INC', 'PRUDENTIAL LIFE INSURANCE'],
            [$rows[0]['company_name'], $rows[1]['company_name']]
        );
    }

    /**
     * The normalisation the key applies to a name, exercised one difference at a
     * time. All four spellings are the same payee once shaped.
     */
    public function test_spelling_differences_in_the_payee_name_do_not_block_a_merge(): void
    {
        $this->entry(['company_name' => 'PRUDENTIAL GUARANTEE & ASSURANCE, INC.']);
        $this->entry(['company_name' => 'Prudential Guarantee and Assurance Inc', 'income_payment' => 1000.00, 'tax_withheld' => 20.00]);
        $this->entry(['company_name' => 'PRUDENTIAL  GUARANTEE   AND ASSURANCE INC ', 'income_payment' => 1000.00, 'tax_withheld' => 20.00]);
        $this->entry(['company_name' => 'PRUDENTIAL GUARANTEE AND ASSURANCE INC', 'income_payment' => 1000.00, 'tax_withheld' => 20.00]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['merged_rows']);
        // The first spelling is the one filed, exactly as stored -- the key
        // normalises for comparison only and never rewrites a stored name.
        $this->assertSame('PRUDENTIAL GUARANTEE & ASSURANCE, INC.', $rows[0]['company_name']);
    }

    public function test_individual_payees_merge_on_their_name_parts_and_rate(): void
    {
        $this->individual();
        $this->individual(['payee_tin' => '009999999', 'income_payment' => 1000.00, 'tax_withheld' => 100.00]);

        $rows = $this->consolidated();

        $this->assertCount(1, $rows);
        $this->assertSame(6865.60, $rows[0]['income_payment']);
        $this->assertSame(686.56, $rows[0]['tax_withheld']);
        $this->assertSame('BANSIL', $rows[0]['last_name']);
        $this->assertSame('220052738', $rows[0]['payee_tin']);
        $this->assertTrue($rows[0]['has_multiple_payee_tins']);
    }

    public function test_two_individuals_sharing_a_surname_stay_apart(): void
    {
        $this->individual();
        $this->individual(['first_name' => 'ANDRES', 'payee_name' => 'BANSIL ANDRES']);

        $rows = $this->consolidated();

        $this->assertCount(2, $rows);
        $this->assertSame(['ANNIE', 'ANDRES'], [$rows[0]['first_name'], $rows[1]['first_name']]);
    }

    public function test_the_same_payee_at_two_rates_stays_two_lines(): void
    {
        // The rule the user asked for, stated once in full: ACERSTEEL at 1% and at
        // 2% are two filing lines even though the payee is one.
        $acersteel = ['payee_name' => 'ACERSTEEL INDUSTRIAL SALES INC', 'company_name' => 'ACERSTEEL INDUSTRIAL SALES INC', 'payee_tin' => '007086184'];

        $this->entry($acersteel + ['atc_code' => 'WC158', 'tax_rate' => 1.00, 'income_payment' => 3682716.00, 'tax_withheld' => 36827.16]);
        $this->entry($acersteel + ['atc_code' => 'WC158', 'tax_rate' => 1.00, 'income_payment' => 1000.00, 'tax_withheld' => 10.00]);
        $this->entry($acersteel + ['atc_code' => 'WC160', 'tax_rate' => 2.00, 'income_payment' => 5000.00, 'tax_withheld' => 100.00]);

        $rows = $this->consolidated();

        $this->assertCount(2, $rows);
        $this->assertSame(['1.00', '2.00'], [$rows[0]['tax_rate'], $rows[1]['tax_rate']]);
        $this->assertSame([2, 1], [$rows[0]['merged_rows'], $rows[1]['merged_rows']]);
        $this->assertSame(3683716.00, $rows[0]['income_payment']);
        $this->assertSame(5000.00, $rows[1]['income_payment']);
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
