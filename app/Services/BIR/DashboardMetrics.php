<?php

namespace App\Services\BIR;

use App\Models\ExpandedWtaxEntry;
use App\Models\ImportationEntry;
use App\Models\SalesVatInput;
use App\Models\VatInput;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Dashboard aggregates across the four BIR data sources this system files:
 * Sales, Purchases, Importation and Expanded Withholding Tax.
 *
 * Every figure comes from columns that already exist:
 *
 *   Sales        sales_vatsinputs        reporting_period  net_amount        output_vat
 *   Purchases    vat_inputs              date_uploaded     total_purchases   input_vat
 *   Importation  importation_entries     tax_month         total_landed_cost vat_payable
 *   Expanded     expanded_wtax_entries   reporting_period  income_payment    tax_withheld
 *
 * Purchases read through VatInput::excludingImportationMirrors(). Each manual
 * importation entry is mirrored into vat_inputs by ImportationController so the
 * purchase DAT generator can emit it, so without that scope every importation
 * would be counted twice -- once as a purchase and once as an importation -- and
 * its VAT twice over. The purchase DAT download applies the same scope.
 *
 * The fourth figure of each source is named 'tax' rather than 'vat' because the
 * expanded module's is tax withheld under 1601EQ/QAP, not VAT. It is reported on its
 * own card and its own chart series, and vatBreakdown() deliberately never reads
 * it: withholding tax is not creditable against output VAT, so folding it into
 * the input side would understate what the company owes.
 *
 * The SQL stays portable (COUNT/SUM/COALESCE and whereBetween on a month range,
 * the idiom DatFileController already uses) rather than MySQL's DATE_FORMAT: the
 * dashboard route is covered by the test suite, which runs on sqlite.
 */
class DashboardMetrics
{
    /**
     * Table, month column and the two figures each module contributes.
     */
    private const SOURCES = [
        'sales' => [
            'model' => SalesVatInput::class,
            'date' => 'reporting_period',
            'amount' => 'net_amount',
            'tax' => 'output_vat',
        ],
        'purchases' => [
            'model' => VatInput::class,
            'date' => 'date_uploaded',
            'amount' => 'total_purchases',
            'tax' => 'input_vat',
        ],
        'importation' => [
            'model' => ImportationEntry::class,
            'date' => 'tax_month',
            'amount' => 'total_landed_cost',
            'tax' => 'vat_payable',
        ],
        'expanded' => [
            'model' => ExpandedWtaxEntry::class,
            'date' => 'reporting_period',
            'amount' => 'income_payment',
            'tax' => 'tax_withheld',
        ],
    ];

    /**
     * Everything the dashboard renders for one tax month.
     */
    public function forMonth(Carbon $month): array
    {
        $year = (int) $month->format('Y');

        $current = $this->totals($month);
        $previous = $this->totals($month->copy()->subMonth());
        // Computed once and handed to both consumers: the cards want the net
        // position, the monthly summary wants the components.
        $vat = $this->vatBreakdown($current);
        $series = $this->monthlySeries($year);

        return [
            'filters' => ['tax_month' => $month->format('Y-m')],
            'monthLabel' => $month->format('F Y'),
            'months' => $this->availableMonths($month),
            'stats' => $this->stats($current, $previous, $vat),
            'summary' => $this->summary($current, $vat),
            'transactions' => $series['transactions'],
            'amounts' => $series['amounts'],
            'chartYear' => $year,
            'recent' => $this->recentImportations($month),
            // Distinguishes "no data anywhere yet" from "nothing in this month".
            'hasAnyData' => $this->hasAnyData(),
        ];
    }

    /**
     * Record count, amount and the second figure (VAT, or tax withheld for the
     * expanded module) for each module in one month.
     */
    private function totals(Carbon $month): array
    {
        $totals = [];

        foreach (self::SOURCES as $key => $source) {
            $row = $this->scopedToMonth($this->baseQuery($key), $source['date'], $month)
                ->selectRaw('COUNT(*) as records')
                ->selectRaw("COALESCE(SUM({$source['amount']}), 0) as amount")
                ->selectRaw("COALESCE(SUM({$source['tax']}), 0) as tax")
                ->first();

            $totals[$key] = [
                'records' => (int) ($row->records ?? 0),
                'amount' => $this->money($row->amount ?? 0),
                'tax' => $this->money($row->tax ?? 0),
            ];
        }

        // The expanded module counts filing lines rather than uploaded rows, so the
        // card cannot disagree with the DAT it will produce: two rows sharing
        // reporting month, TIN, ATC and rate are one 1601EQ/QAP detail line.
        // ExpandedWtaxEntry::consolidate() is the same rule the records list and the
        // generator apply. Only the count is re-read -- consolidation adds rows
        // together rather than dropping any, so the two sums above are unaffected.
        $totals['expanded']['records'] = $this->consolidatedCount(
            $this->scopedToMonth($this->baseQuery('expanded'), self::SOURCES['expanded']['date'], $month)->get()
        );

        return $totals;
    }

    /**
     * The summary cards. Deltas compare the selected month with the one
     * immediately before it.
     */
    private function stats(array $current, array $previous, array $vat): array
    {
        $stats = [];

        foreach (array_keys(self::SOURCES) as $key) {
            $stats[$key] = [
                'amount' => $current[$key]['amount'],
                'records' => $current[$key]['records'],
                'previous_records' => $previous[$key]['records'],
                'previous_amount' => $previous[$key]['amount'],
            ];
        }

        // The 1601EQ/QAP figures are reported on their own card, so it carries both:
        // the income payments as the amount above, and the tax withheld here.
        // The VAT modules' second figure is the monthly summary's job instead.
        $stats['expanded']['tax_withheld'] = $current['expanded']['tax'];
        $stats['expanded']['previous_tax_withheld'] = $previous['expanded']['tax'];

        // Only the net position and its baseline: the component figures are the
        // monthly summary's job, and shipping them twice invites the two to drift.
        $stats['vat'] = [
            'net' => $vat['net'],
            'previous_net' => $this->vatBreakdown($previous)['net'],
        ];

        return $stats;
    }

    /**
     * Net VAT for the month: output VAT less the input VAT the company may credit
     * against it, importation VAT included. Positive is payable, negative is
     * creditable -- both are normal, so the sign is reported rather than clamped.
     *
     * Expanded withholding tax is absent by design. It is a 1601EQ/QAP figure, not VAT,
     * and is not creditable against output VAT.
     */
    private function vatBreakdown(array $totals): array
    {
        $output = $totals['sales']['tax'];
        $input = $totals['purchases']['tax'];
        $importation = $totals['importation']['tax'];
        $totalInput = $this->money($input + $importation);

        return [
            'output' => $output,
            'input' => $input,
            'importation' => $importation,
            'total_input' => $totalInput,
            'net' => $this->money($output - $totalInput),
        ];
    }

    /**
     * The monthly summary strip: amounts per module plus the VAT breakdown.
     */
    private function summary(array $current, array $vat): array
    {
        return [
            'total_sales' => $current['sales']['amount'],
            'total_purchases' => $current['purchases']['amount'],
            'total_importation' => $current['importation']['amount'],
            'total_expanded' => $current['expanded']['amount'],
            'vat' => $vat,
        ];
    }

    /**
     * Jan-Dec for one year, as two 12-point series: record counts per module and
     * amounts per module. Months without data are present as zeroes so both charts
     * always draw a full axis.
     *
     * Grouping happens in PHP rather than SQL because the two engines spell
     * year-month extraction differently (DATE_FORMAT vs strftime).
     */
    private function monthlySeries(int $year): array
    {
        $grouped = [];

        foreach (self::SOURCES as $key => $source) {
            $grouped[$key] = $this->baseQuery($key)
                ->whereBetween($source['date'], [
                    Carbon::create($year, 1, 1)->startOfMonth()->toDateString(),
                    Carbon::create($year, 12, 1)->endOfMonth()->toDateString(),
                ])
                // The expanded series counts consolidated filing lines, which needs
                // the grouping columns, so that one source is loaded whole.
                ->get($key === 'expanded' ? ['*'] : [$source['date'], $source['amount']])
                ->groupBy(fn ($record) => (int) Carbon::parse(
                    $record->getRawOriginal($source['date'])
                )->format('n'));
        }

        $transactions = [];
        $amounts = [];

        foreach (range(1, 12) as $month) {
            $label = Carbon::create($year, $month, 1)->format('M');
            $transactionPoint = ['month' => $label];
            $amountPoint = ['month' => $label];

            foreach (self::SOURCES as $key => $source) {
                /** @var Collection $rows */
                $rows = $grouped[$key]->get($month, collect());

                $transactionPoint[$key] = $key === 'expanded'
                    ? $this->consolidatedCount($rows)
                    : $rows->count();
                $amountPoint[$key] = $this->money($rows->sum(
                    fn ($record) => (float) $record->{$source['amount']}
                ));
            }

            $transactions[] = $transactionPoint;
            $amounts[] = $amountPoint;
        }

        return ['transactions' => $transactions, 'amounts' => $amounts];
    }

    /**
     * Tax month options: a rolling two-year window, plus any month that actually
     * holds data in any of the four modules (so older filings stay reachable),
     * plus whatever is currently selected.
     */
    private function availableMonths(Carbon $selected): array
    {
        $months = collect(range(0, 23))
            ->map(fn (int $back) => Carbon::now()->startOfMonth()->subMonths($back)->format('Y-m'));

        foreach (self::SOURCES as $key => $source) {
            $withData = $this->baseQuery($key)
                ->whereNotNull($source['date'])
                ->distinct()
                ->pluck($source['date'])
                ->map(fn ($date) => Carbon::parse($date)->format('Y-m'));

            $months = $months->merge($withData);
        }

        return $months
            ->push($selected->format('Y-m'))
            ->unique()
            ->sortDesc()
            ->values()
            ->map(fn (string $month) => [
                'value' => $month,
                'label' => Carbon::parse($month . '-01')->format('F Y'),
            ])
            ->all();
    }

    /**
     * Importation analytics stay on the dashboard: the five newest entries for the
     * selected month.
     */
    private function recentImportations(Carbon $month): array
    {
        return $this->scopedToMonth(ImportationEntry::query(), 'tax_month', $month)
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (ImportationEntry $entry) => [
                'id' => $entry->id,
                'import_entry_no' => $entry->import_entry_no,
                'supplier' => $entry->supplier,
                'country' => $entry->country,
                'tax_month' => $entry->tax_month->format('M Y'),
                'taxable_goods' => (float) $entry->taxable_goods,
                'vat_payable' => (float) $entry->vat_payable,
                'created_at' => $entry->created_at?->format('M j, Y g:i A'),
            ])
            ->all();
    }

    private function hasAnyData(): bool
    {
        foreach (array_keys(self::SOURCES) as $key) {
            if ($this->baseQuery($key)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fresh query for one module, with the module's own exclusions applied.
     */
    private function baseQuery(string $key): Builder
    {
        $query = self::SOURCES[$key]['model']::query();

        return $key === 'purchases'
            ? $query->excludingImportationMirrors()
            : $query;
    }

    /**
     * tax_month is always the first of the month, but reporting_period and
     * date_uploaded hold arbitrary days, so all three are filtered on the month
     * range -- the same way DatFileController selects records for a DAT.
     */
    private function scopedToMonth(Builder $query, string $column, Carbon $month): Builder
    {
        return $query->whereBetween($column, [
            $month->copy()->startOfMonth()->toDateString(),
            $month->copy()->endOfMonth()->toDateString(),
        ]);
    }

    /**
     * How many 1601EQ/QAP detail lines a set of stored expanded rows becomes.
     *
     * Counted through ExpandedWtaxEntry::consolidate() rather than a DISTINCT over
     * the four grouping columns, for two reasons: the count then matches the DAT's
     * line count by construction, whatever shape the stored values are in, and
     * COUNT(DISTINCT a,b,c,d) is MySQL-only -- the test suite runs on sqlite.
     *
     * @param  Collection<int, \App\Models\ExpandedWtaxEntry>  $rows
     */
    private function consolidatedCount(Collection $rows): int
    {
        return ExpandedWtaxEntry::consolidate($rows)->count();
    }

    private function money(float|int|string $value): float
    {
        return round((float) $value, 2);
    }
}
