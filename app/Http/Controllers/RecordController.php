<?php

namespace App\Http\Controllers;

use App\Models\ExpandedWtaxEntry;
use App\Models\PurchaseAdjustment;
use App\Models\SalesVatInput;
use App\Models\VatInput;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use App\Services\BIR\PurchaseCompanyDirectory;
use App\Services\BIR\SalesSiCmConsolidator;
use App\Services\PurchaseAmountPresenter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use App\Support\DataTableQuery;

/**
 * Read-only listings for the Record section.
 *
 * One method per data type, each reading only its own table -- Purchase, Sales
 * and Expanded WTAX are stored separately and stay separate here, so no single
 * query mixes them. The queries are the ones the old combined /records screen
 * ran; only the page they feed changed, and each page carries a plain "page"
 * paginator now that it is the sole table on screen.
 *
 * Nothing in this controller touches Excel parsing or DAT generation. The
 * importation listing lives on ImportationController, next to the create/edit/
 * delete rules its table calls.
 */
class RecordController extends Controller
{
    private const PURCHASE_ADJUSTMENT_FIELDS = [
        'purchase_imported',
        'purchase_local',
        'services',
        'others',
    ];

    private const PURCHASE_RAW_VAT_FIELDS = [
        'purchase_local' => 'purchase_local_vat_amount',
        'services' => 'services_vat_amount',
        'others' => 'others_vat_amount',
    ];

    /**
     * Purchase (VAT input) rows, with the broker flag the Adjust action gates on.
     */
    public function purchases(Request $request, PurchaseAmountPresenter $amountPresenter)
    {
        $search = $request->input('search');
        $period = $this->normalisedMonth($request->input('period'));

        /*
         * is_broker is computed in SQL rather than eager-loaded: a purchase row is
         * a broker's when its first nine TIN digits match a broker, and an already
         * adjusted row is never offered for adjustment again.
         *
         * SUBSTR rather than MySQL's LEFT so the listing runs on any driver; the
         * two are the same expression, and this page is covered by tests.
         */
        $companyKey = (string) $request->input('company', '');
        $companyQuery = VatInput::query();
        if ($companyKey === 'unassigned') {
            $companyQuery->whereNull('filing_company_tin')->excludingImportationMirrors();
        } elseif ($companyKey !== '') {
            [$tin, $branch] = array_pad(explode('|', $companyKey, 2), 2, null);
            $company = app(PurchaseCompanyDirectory::class)->resolve($tin, $branch, true);
            $companyQuery->forFilingCompany($company)->excludingImportationMirrors();
        }
        $tableOptions = DataTableQuery::options($request, ['supplier_name', 'tin_number', 'is_imported', 'purchase_imported', 'display_purchase_local_amount', 'display_services_amount', 'display_others_amount', 'display_calculated_total']);
        $purchaseQuery = (clone $companyQuery)
            ->select('vat_inputs.*')
            ->selectRaw("case when vat_inputs.is_adjusted = 1 then 0 else exists(select 1 from brokers where SUBSTR(REPLACE(REPLACE(REPLACE(brokers.tin_number, '-', ''), ' ', ''), '.', ''), 1, 9) = SUBSTR(REPLACE(REPLACE(REPLACE(vat_inputs.tin_number, '-', ''), ' ', ''), '.', ''), 1, 9)) end as is_broker")
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('supplier_name', 'LIKE', "%{$search}%")
                        ->orWhere('tin_number', 'LIKE', "%{$search}%");
                });
            })
            ->when($period, function ($query, Carbon $month) {
                $query->whereBetween('date_uploaded', [
                    $month->copy()->startOfMonth()->toDateString(),
                    $month->copy()->endOfMonth()->toDateString(),
                ]);
            })
            ->orderBy('supplier_name')
            ->orderBy('id');
        $present = function (VatInput $record) use ($amountPresenter) {
            foreach ($amountPresenter->forRecord($record) as $field => $value) {
                $record->setAttribute($field, $value);
            }
        };
        if (str_starts_with($tableOptions['sort'] ?? '', 'display_')) {
            // Historical rows have different display rules; sort the presenter's
            // actual values instead of guessing a SQL formula from stored amounts.
            $vatInputs = DataTableQuery::collection($purchaseQuery->get()->each($present), $request, $tableOptions,
                ['display_purchase_local_amount', 'display_services_amount', 'display_others_amount', 'display_calculated_total']);
        } else {
            $vatInputs = DataTableQuery::query($purchaseQuery, $request, $tableOptions);
            $vatInputs->getCollection()->each($present);
        }

        $adjustedIds = $vatInputs->getCollection()
            ->filter(fn (VatInput $record) => (bool) $record->is_adjusted)
            ->pluck('id');
        $adjustmentHistory = PurchaseAdjustment::query()
            ->whereIn('target_vat_input_id', $adjustedIds)
            ->get()
            ->groupBy('target_vat_input_id');

        $vatInputs->getCollection()->each(function (VatInput $record) use ($adjustmentHistory) {

            if (! $record->is_adjusted) {
                return;
            }

            $histories = $adjustmentHistory->get($record->id, collect());
            $validHistories = $histories->whereNotNull('source_vat_input_id');
            $complete = $validHistories->isNotEmpty()
                && $histories->whereNull('source_vat_input_id')->isEmpty();

            foreach (self::PURCHASE_ADJUSTMENT_FIELDS as $field) {
                $tracked = $validHistories->sum(
                    fn (PurchaseAdjustment $history) => $this->moneyToCents($history->{$field})
                );

                if ($tracked !== $this->moneyToCents($record->{$field})) {
                    $complete = false;
                }
            }

            foreach (self::PURCHASE_RAW_VAT_FIELDS as $baseField => $rawField) {
                if ($record->{$rawField} === null) {
                    continue;
                }

                $trackedVat = $validHistories->sum(
                    fn (PurchaseAdjustment $history) => $this->moneyToCents(
                        $history->{$rawField}
                            ?? round((float) $history->{$baseField} * 0.12, 2)
                    )
                );

                if ($trackedVat !== $this->moneyToCents($record->{$rawField})) {
                    $complete = false;
                }
            }

            $record->setAttribute('adjustment_history_complete', $complete);
        });

        return Inertia::render('Records/PurchaseRecords', [
            'vatInputs' => $vatInputs,
            'companyFilter' => $companyKey,
            'months' => $this->availableMonths(clone $companyQuery, 'date_uploaded'),
            'purchaseCompanies' => app(PurchaseCompanyDirectory::class)->companies(true),
            'filters' => [
                ...$tableOptions,
                'search' => $search,
                'period' => $period?->format('Y-m') ?? '',
            ],
        ]);
    }

    /**
     * Sales rows, grouped by customer identity the way they are filed.
     */
    public function sales(Request $request, SalesSiCmConsolidator $salesConsolidator)
    {
        $search = $request->input('search');
        $period = $this->normalisedMonth($request->input('period'));

        $tableOptions = DataTableQuery::options($request, ['customer_name', 'customer_tin', 'customer_type', 'si_count', 'cm_count', 'exempt_sales', 'zero_rated_sales', 'taxable_net_of_vat', 'output_vat', 'gross_amount']);
        $salesRows = $salesConsolidator->consolidate(
            SalesVatInput::query()
                ->when($search, function ($query, $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('customer_name', 'LIKE', "%{$search}%")
                            ->orWhere('customer_tin', 'LIKE', "%{$search}%")
                            ->orWhere('document_no', 'LIKE', "%{$search}%");
                    });
                })
                ->when($period, function ($query, Carbon $month) {
                    $query->whereBetween('reporting_period', [
                        $month->copy()->startOfMonth()->toDateString(),
                        $month->copy()->endOfMonth()->toDateString(),
                    ]);
                })
                ->orderBy('customer_name')
                ->orderBy('id')
                ->get()
        );

        $salesVatInputs = DataTableQuery::collection($salesRows, $request, $tableOptions, ['si_count', 'cm_count', 'exempt_sales', 'zero_rated_sales', 'taxable_net_of_vat', 'output_vat', 'gross_amount']);

        return Inertia::render('Records/SalesRecords', [
            'salesVatInputs' => $salesVatInputs,
            'months' => $this->availableMonths(SalesVatInput::query(), 'reporting_period'),
            'filters' => [
                ...$tableOptions,
                'search' => $search,
                'period' => $period?->format('Y-m') ?? '',
            ],
        ]);
    }

    /**
     * Expanded WTAX rows, consolidated the way they are filed.
     *
     * Rows sharing Reporting Month + withholding agent + payee identity + ATC +
     * EWT Rate are one line, with the income payment and the tax amount summed.
     * The grouping runs in PHP through ExpandedWtaxEntry::consolidate() rather
     * than as a SQL GROUP BY so this list, the Generate DAT screen's record count
     * and the DAT download all share one rule and cannot drift apart. Search and
     * ordering stay in SQL, and the consolidated collection is paginated
     * afterwards.
     */
    public function expandedWtax(Request $request, BirExpandedWtaxRowValidator $validator)
    {
        $search = $request->input('search');
        $period = $this->normalisedMonth($request->input('period'));
        $tableOptions = $request->validate([
            'per_page' => ['sometimes', 'integer', 'in:10,15,25,50,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['nullable', 'in:payee_name,withholding_agent_tin,payee_tin,payee_branch_code,atc_code,tax_rate,income_payment,tax_withheld,reporting_period,report_type'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $perPage = (int) ($tableOptions['per_page'] ?? 15);

        $dmScope = $request->validate([
            'withholding_agent_tin' => ['nullable', 'regex:/^\d{9}$/'],
            'withholding_agent_branch_code' => ['nullable', 'regex:/^\d{4}$/'],
            'report_type' => ['nullable', 'in:quarterly,annual'],
            'tax_rate' => ['nullable', 'numeric', 'gt:0', 'max:100'],
        ]);
        $scopedQuery = ExpandedWtaxEntry::query();
        foreach ($dmScope as $field => $value) {
            if ($value !== null && $value !== '') {
                $scopedQuery->where($field, $value);
            }
        }

        $expandedRows = ExpandedWtaxEntry::consolidate(
            (clone $scopedQuery)
                ->when($search, function ($query, $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('payee_name', 'LIKE', "%{$search}%")
                            ->orWhere('payee_tin', 'LIKE', "%{$search}%")
                            ->orWhere('atc_code', 'LIKE', "%{$search}%");
                    });
                })
                ->when($period, function ($query, Carbon $month) {
                    $query->whereBetween('reporting_period', [
                        $month->copy()->startOfMonth()->toDateString(),
                        $month->copy()->endOfMonth()->toDateString(),
                    ]);
                })
                ->orderBy('payee_name')
                ->orderByDesc('reporting_period')
                ->orderBy('tax_rate')
                ->orderBy('id')
                ->get()
        )->map(function (array $row) use ($validator) {
            $errors = $validator->validate($row, 0);

            return $row + [
                'invalid_count' => count($errors),
                'validation_errors' => array_map(
                    fn (string $error) => preg_replace('/^Row \d+: /', '', $error),
                    $errors
                ),
                'has_missing_id' => collect($errors)->contains(
                    fn (string $error) => str_contains($error, 'payee_tin must contain at least 9 digits')
                        || str_contains($error, 'payee_tin cannot be 000000000')
                ),
            ];
        });

        $page = LengthAwarePaginator::resolveCurrentPage();

        // Sort the consolidated display amounts, never individual source amounts.
        if ($sort = $tableOptions['sort'] ?? null) {
            $numeric = in_array($sort, ['tax_rate', 'income_payment', 'tax_withheld'], true);
            $descending = ($tableOptions['direction'] ?? 'asc') === 'desc';
            $expandedRows = $expandedRows->sort(function ($a, $b) use ($sort, $numeric, $descending) {
                $comparison = $numeric
                    ? ((float) $a[$sort] <=> (float) $b[$sort])
                    : strcasecmp((string) ($a[$sort] ?? ''), (string) ($b[$sort] ?? ''));

                return ($comparison === 0) ? strcmp($a['id'], $b['id']) : ($descending ? -$comparison : $comparison);
            })->values();
        }
        $page = min($page, max(1, (int) ceil($expandedRows->count() / $perPage)));

        $expandedWtaxEntries = (new LengthAwarePaginator(
            $expandedRows->forPage($page, $perPage)->values(),
            $expandedRows->count(),
            $perPage,
            $page,
            ['path' => $request->url()]
        ))->withQueryString();

        return Inertia::render('Records/ExpandedWtaxRecords', [
            'expandedWtaxEntries' => $expandedWtaxEntries,
            // Built from $expandedRows, not from the page above: the cards total
            // the whole filtered listing, so paging must not move them.
            'withholdingTaxRateSummary' => $this->withholdingTaxRateSummary($expandedRows),
            'months' => $this->availableMonths(clone $scopedQuery, 'reporting_period'),
            'filters' => [
                ...$dmScope,
                'per_page' => $perPage,
                'sort' => $tableOptions['sort'] ?? null,
                'direction' => ($tableOptions['sort'] ?? null) ? ($tableOptions['direction'] ?? 'asc') : null,
                'search' => $search,
                'period' => $period?->format('Y-m') ?? '',
            ],
        ]);
    }

    /**
     * Tax Withheld totalled per EWT rate over the whole filtered listing.
     *
     * Each consolidated line contributes its stored tax_withheld once.
     * merged_rows is a count for the badge on screen, never a multiplier -- the
     * amount was already summed by ExpandedWtaxEntry::consolidate() -- and the
     * rate is not used to re-derive anything, the same rule the listing and the
     * generator follow.
     *
     * Totals accumulate in centavos rather than as floats. A month of these
     * amounts added as floats lands a centavo off often enough to be seen, and
     * these are figures a filer reconciles against the DAT.
     *
     * Rows the validator flagged are included, exactly as the table lists them:
     * a warning means the row needs BIR info before it can be filed, not that
     * its amount is wrong.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{total_tax_withheld: string, rates: array<int, array{tax_rate: string, tax_withheld_total: string}>}
     */
    private function withholdingTaxRateSummary(Collection $rows): array
    {
        $centavos = [];

        foreach ($rows as $row) {
            // Two decimals throughout, so 1 and 1.00 cannot key as two rates.
            $rate = number_format((float) ($row['tax_rate'] ?? 0), 2, '.', '');

            $centavos[$rate] = ($centavos[$rate] ?? 0)
                + (int) round((float) ($row['tax_withheld'] ?? 0) * 100);
        }

        // String keys ("1.00", "10.00"), so the sort has to be told they are
        // numbers -- otherwise 10% files between 1% and 2%.
        ksort($centavos, SORT_NUMERIC);

        return [
            'total_tax_withheld' => number_format(array_sum($centavos) / 100, 2, '.', ''),
            'rates' => collect($centavos)
                ->map(fn (int $total, string $rate) => [
                    'tax_rate' => $rate,
                    'tax_withheld_total' => number_format($total / 100, 2, '.', ''),
                ])
                ->values()
                ->all(),
        ];
    }

    private function normalisedMonth(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    private function availableMonths($query, string $column): array
    {
        return $query
            ->whereNotNull($column)
            ->orderByDesc($column)
            ->pluck($column)
            ->groupBy(fn ($date) => Carbon::parse($date)->format('Y-m'))
            ->map(fn ($group, string $value) => [
                'value' => $value,
                'label' => Carbon::parse($value.'-01')->format('F Y'),
                'records_count' => $group->count(),
            ])
            ->values()
            ->all();
    }

    private function moneyToCents(mixed $amount): int
    {
        return (int) round((float) ($amount ?? 0) * 100);
    }
}
