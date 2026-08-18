<?php

namespace App\Http\Controllers;

use App\Models\VatInput;
use App\Models\SalesVatInput;
use App\Models\Supplier;
use App\Services\BIR\BirPurchaseRowValidator;
use App\Services\BIR\BirSalesRowValidator;
use App\Services\BIR\ReliefPurchaseDatGenerator;
use App\Services\BIR\ReliefSalesDatGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class DatFileController extends Controller
{
    public function index(
        Request $request,
        BirPurchaseRowValidator $purchaseValidator,
        BirSalesRowValidator $salesValidator
    )
    {
        $recordType = $request->validate([
            'record_type' => ['nullable', 'in:purchase,sales'],
        ])['record_type'] ?? 'purchase';

        if ($recordType === 'sales') {
            [$availablePeriods, $periodIssues] = $this->salesPeriods($salesValidator);
        } else {
            [$availablePeriods, $periodIssues] = $this->purchasePeriods($purchaseValidator);
        }

        return Inertia::render('GenerateDatFile', [
            'defaultCompany' => config('bir.companies.008791976'),
            'recordType' => $recordType,
            'availablePeriods' => $availablePeriods,
            'periodIssues' => $periodIssues,
        ]);
    }

    public function companyLookup(string $tin)
    {
        $tin = preg_replace('/\D/', '', $tin);
        $birTin = substr($tin, 0, 9);
        $company = config("bir.companies.{$tin}") ?: config("bir.companies.{$birTin}");

        if ($company) {
            return response()->json($company);
        }

        $vatInput = VatInput::query()
            ->whereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin_number, '-', ''), ' ', ''), '.', ''), 9) = ?", [$birTin])
            ->latest('id')
            ->first();

        if ($vatInput) {
            return response()->json([
                'tin' => $tin,
                'name' => $vatInput->company_name ?: $vatInput->supplier_name,
                'registered_name' => $vatInput->company_name ?: $vatInput->supplier_name,
                'address1' => $vatInput->address1 ?: '',
                'address2' => $vatInput->address2 ?: '',
                'rdo_code' => '',
                'source' => 'vat_inputs',
            ]);
        }

        $supplier = Supplier::query()
            ->whereRaw("REPLACE(REPLACE(REPLACE(tin, '-', ''), ' ', ''), '.', '') = ?", [$tin])
            ->orWhereRaw("LEFT(REPLACE(REPLACE(REPLACE(tin, '-', ''), ' ', ''), '.', ''), 9) = ?", [$birTin])
            ->first();

        if ($supplier) {
            return response()->json([
                'tin' => $tin,
                'name' => $supplier->name,
                'registered_name' => $supplier->name,
                'address1' => $supplier->addr ?: '',
                'address2' => $supplier->city ?: '',
                'rdo_code' => '',
                'source' => 'suppliers',
            ]);
        }

        return response()->json([
            'message' => 'TIN not found.',
        ], 404);
    }

    public function download(
        Request $request,
        ReliefPurchaseDatGenerator $purchaseGenerator,
        ReliefSalesDatGenerator $salesGenerator,
        BirPurchaseRowValidator $purchaseValidator,
        BirSalesRowValidator $salesValidator
    )
    {
        $validated = $request->validate([
            'period' => ['required', 'date'],
            'record_type' => ['nullable', 'in:purchase,sales'],
        ]);

        $period = Carbon::parse($validated['period'])->endOfMonth();
        $recordType = $validated['record_type'] ?? 'purchase';

        if ($recordType === 'sales') {
            return $this->downloadSales($period, $salesGenerator, $salesValidator);
        }

        return $this->downloadPurchase($period, $purchaseGenerator, $purchaseValidator);
    }

    private function purchasePeriods(BirPurchaseRowValidator $validator): array
    {
        $availablePeriods = VatInput::query()
            ->selectRaw("DATE_FORMAT(date_uploaded, '%Y-%m') as value")
            ->selectRaw("DATE_FORMAT(date_uploaded, '%M %Y') as label")
            ->selectRaw('COUNT(*) as records_count')
            ->groupBy('value', 'label')
            ->orderByDesc('value')
            ->get();
        $periodIssues = [];

        VatInput::query()
            ->orderBy('date_uploaded')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (VatInput $record) => $record->date_uploaded->format('Y-m'))
            ->each(function ($records, string $period) use (&$periodIssues, $validator) {
                $errors = [];

                foreach ($records->values() as $index => $record) {
                    foreach ($validator->validate($record->toBirPurchaseRow(), $index + 2) as $error) {
                        $errors[] = "Record #{$record->id} {$record->supplier_name}: {$error}";
                    }
                }

                $periodIssues[$period] = [
                    'invalid_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ];
            });

        return [$availablePeriods, $periodIssues];
    }

    private function salesPeriods(BirSalesRowValidator $validator): array
    {
        $availablePeriods = SalesVatInput::query()
            ->selectRaw("DATE_FORMAT(reporting_period, '%Y-%m') as value")
            ->selectRaw("DATE_FORMAT(reporting_period, '%M %Y') as label")
            ->selectRaw('COUNT(*) as records_count')
            ->whereNotNull('reporting_period')
            ->groupBy('value', 'label')
            ->orderByDesc('value')
            ->get();
        $periodIssues = [];

        SalesVatInput::query()
            ->whereNotNull('reporting_period')
            ->orderBy('reporting_period')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SalesVatInput $record) => $record->reporting_period->format('Y-m'))
            ->each(function ($records, string $period) use (&$periodIssues, $validator) {
                $errors = [];

                foreach ($this->groupSalesRows($records)->values() as $index => $row) {
                    foreach ($validator->validate($row, $index + 2) as $error) {
                        $errors[] = "Sales group {$row['customer_name']}: {$error}";
                    }
                }

                $periodIssues[$period] = [
                    'invalid_count' => count($errors),
                    'errors' => array_slice($errors, 0, 10),
                ];
            });

        return [$availablePeriods, $periodIssues];
    }

    private function downloadPurchase(
        Carbon $period,
        ReliefPurchaseDatGenerator $generator,
        BirPurchaseRowValidator $validator
    ) {
        $records = VatInput::query()
            ->whereBetween('date_uploaded', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'No VAT input records found for the selected reporting month.');
        }

        $rowErrors = [];
        foreach ($records as $index => $record) {
            foreach ($validator->validate($record->toBirPurchaseRow(), $index + 2) as $error) {
                $rowErrors[] = "Record #{$record->id} {$record->supplier_name}: {$error}";
            }
        }

        if ($rowErrors !== []) {
            return back()->with('error', 'Cannot generate DAT. Fix these VAT input rows first: ' . implode(' ', array_slice($rowErrors, 0, 5)));
        }

        $defaultCompany = config('bir.companies.008791976');

        $company = [
            'tin' => $defaultCompany['tin'],
            'name' => $defaultCompany['name'],
            'registered_name' => $defaultCompany['registered_name'],
            'address1' => $defaultCompany['address1'],
            'address2' => $defaultCompany['address2'],
            'rdo_code' => $defaultCompany['rdo_code'],
            'final_header_field' => '12',
        ];

        $content = $generator->generate(
            $company,
            $records->map(fn (VatInput $record) => $record->toBirPurchaseRow()),
            $period,
            0
        );

        $fileName = $generator->filename($company, $period);

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function downloadSales(
        Carbon $period,
        ReliefSalesDatGenerator $generator,
        BirSalesRowValidator $validator
    ) {
        $records = SalesVatInput::query()
            ->whereBetween('reporting_period', [
                $period->copy()->startOfMonth()->toDateString(),
                $period->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return back()->with('error', 'No Sales VAT records found for the selected reporting month.');
        }

        $salesRows = $this->groupSalesRows($records)->values();
        $rowErrors = [];

        foreach ($salesRows as $index => $row) {
            foreach ($validator->validate($row, $index + 2) as $error) {
                $rowErrors[] = "Sales group {$row['customer_name']}: {$error}";
            }
        }

        if ($rowErrors !== []) {
            return back()->with('error', 'Cannot generate Sales DAT. Fix these sales rows first: ' . implode(' ', array_slice($rowErrors, 0, 5)));
        }

        $defaultCompany = config('bir.companies.008791976');

        $company = [
            'tin' => $defaultCompany['tin'],
            'name' => $defaultCompany['name'],
            'registered_name' => $defaultCompany['registered_name'],
            'address1' => $defaultCompany['address1'],
            'address2' => $defaultCompany['address2'],
            'rdo_code' => $defaultCompany['rdo_code'],
            'final_header_field' => '12',
        ];

        $content = $generator->generate($company, $salesRows, $period);
        $fileName = $generator->filename($company, $period);

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    private function groupSalesRows(Collection $records): Collection
    {
        return $records
            ->groupBy(function (SalesVatInput $record) {
                $tin = substr(preg_replace('/\D/', '', (string) $record->customer_tin), 0, 9);
                $name = preg_replace('/\s+/', '', strtoupper((string) ($record->company_name ?: $record->customer_name)));

                return implode('|', [
                    $tin,
                    $record->customer_type ?: 'company',
                    $name,
                    strtoupper((string) $record->last_name),
                    strtoupper((string) $record->first_name),
                    strtoupper((string) $record->middle_name),
                    strtoupper((string) $record->address1),
                    strtoupper((string) $record->address2),
                ]);
            })
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'customer_type' => $first->customer_type ?: 'company',
                    'customer_tin' => $first->customer_tin,
                    'customer_name' => $first->customer_name,
                    'company_name' => $first->customer_type === 'individual'
                        ? ''
                        : ($first->company_name ?: $first->customer_name),
                    'last_name' => $first->last_name,
                    'first_name' => $first->first_name,
                    'middle_name' => $first->middle_name,
                    'address1' => $first->address1,
                    'address2' => $first->address2,
                    'exempt_sales' => round($group->sum(fn (SalesVatInput $record) => (float) $record->exempt_sales), 2),
                    'zero_rated_sales' => round($group->sum(fn (SalesVatInput $record) => (float) $record->zero_rated_sales), 2),
                    'taxable_sales' => round($group->sum(fn (SalesVatInput $record) => (float) $record->taxable_net_of_vat), 2),
                    'output_vat' => round($group->sum(fn (SalesVatInput $record) => (float) $record->output_vat), 2),
                ];
            });
    }
}
