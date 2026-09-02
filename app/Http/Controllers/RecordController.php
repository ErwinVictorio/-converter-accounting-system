<?php

namespace App\Http\Controllers;

use App\Models\ExpandedWtaxEntry;
use App\Models\SalesVatInput;
use App\Models\VatInput;
use App\Services\BIR\BirExpandedWtaxRowValidator;
use App\Services\BIR\SalesSiCmConsolidator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;

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
    /**
     * Purchase (VAT input) rows, with the broker flag the Adjust action gates on.
     */
    public function purchases(Request $request)
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
        $vatInputs = VatInput::query()
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
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Records/PurchaseRecords', [
            'vatInputs' => $vatInputs,
            'months' => $this->availableMonths(VatInput::query(), 'date_uploaded'),
            'filters' => [
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

        $page = LengthAwarePaginator::resolveCurrentPage();

        $salesVatInputs = (new LengthAwarePaginator(
            $salesRows->forPage($page, 15)->values(),
            $salesRows->count(),
            15,
            $page,
            ['path' => $request->url()]
        ))->withQueryString();

        return Inertia::render('Records/SalesRecords', [
            'salesVatInputs' => $salesVatInputs,
            'months' => $this->availableMonths(SalesVatInput::query(), 'reporting_period'),
            'filters' => [
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

        $expandedRows = ExpandedWtaxEntry::consolidate(
            ExpandedWtaxEntry::query()
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

        $expandedWtaxEntries = (new LengthAwarePaginator(
            $expandedRows->forPage($page, 15)->values(),
            $expandedRows->count(),
            15,
            $page,
            ['path' => $request->url()]
        ))->withQueryString();

        return Inertia::render('Records/ExpandedWtaxRecords', [
            'expandedWtaxEntries' => $expandedWtaxEntries,
            'months' => $this->availableMonths(ExpandedWtaxEntry::query(), 'reporting_period'),
            'filters' => [
                'search' => $search,
                'period' => $period?->format('Y-m') ?? '',
            ],
        ]);
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
                'label' => Carbon::parse($value . '-01')->format('F Y'),
                'records_count' => $group->count(),
            ])
            ->values()
            ->all();
    }
}
