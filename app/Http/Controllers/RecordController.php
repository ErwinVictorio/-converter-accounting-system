<?php

namespace App\Http\Controllers;

use App\Models\ExpandedWtaxEntry;
use App\Models\SalesVatInput;
use App\Models\VatInput;
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
            ->orderBy('id', 'asc')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Records/PurchaseRecords', [
            'vatInputs' => $vatInputs,
            'filters' => ['search' => $search],
        ]);
    }

    /**
     * Sales rows, grouped by customer identity the way they are filed.
     */
    public function sales(Request $request)
    {
        $search = $request->input('search');

        $salesVatInputs = SalesVatInput::query()
            ->select([
                'customer_name',
                'customer_tin',
                'customer_type',
                'company_name',
                'last_name',
                'first_name',
                'middle_name',
                'address1',
                'address2',
            ])
            ->selectRaw('MIN(id) as id')
            ->selectRaw('COUNT(*) as records_count')
            ->selectRaw('SUM(exempt_sales) as exempt_sales')
            ->selectRaw('SUM(zero_rated_sales) as zero_rated_sales')
            ->selectRaw('SUM(taxable_net_of_vat) as taxable_net_of_vat')
            ->selectRaw('SUM(output_vat) as output_vat')
            ->selectRaw('SUM(net_amount) as net_amount')
            ->selectRaw('SUM(gross_amount) as gross_amount')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('customer_name', 'LIKE', "%{$search}%")
                        ->orWhere('customer_tin', 'LIKE', "%{$search}%")
                        ->orWhere('document_no', 'LIKE', "%{$search}%");
                });
            })
            ->groupBy([
                'customer_name',
                'customer_tin',
                'customer_type',
                'company_name',
                'last_name',
                'first_name',
                'middle_name',
                'address1',
                'address2',
            ])
            ->orderBy('customer_name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Records/SalesRecords', [
            'salesVatInputs' => $salesVatInputs,
            'filters' => ['search' => $search],
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
    public function expandedWtax(Request $request)
    {
        $search = $request->input('search');

        $expandedRows = ExpandedWtaxEntry::consolidate(
            ExpandedWtaxEntry::query()
                ->when($search, function ($query, $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('payee_name', 'LIKE', "%{$search}%")
                            ->orWhere('payee_tin', 'LIKE', "%{$search}%")
                            ->orWhere('atc_code', 'LIKE', "%{$search}%");
                    });
                })
                ->orderByDesc('reporting_period')
                ->orderBy('payee_name')
                ->orderBy('tax_rate')
                ->orderBy('id')
                ->get()
        );

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
            'filters' => ['search' => $search],
        ]);
    }
}
